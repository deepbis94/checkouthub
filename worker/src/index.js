import { Queue, Worker } from 'bullmq';
import IORedis from 'ioredis';
import { deliverOutboundWebhook } from './jobs/outboundWebhook.js';
import { renewSubscription } from './jobs/renewSubscription.js';
import { probeGateways } from './jobs/probeGateways.js';
import { expireCheckouts } from './jobs/expireCheckouts.js';
import { reconcileCharge } from './jobs/reconcileCharge.js';

const connection = new IORedis({
  host: process.env.REDIS_HOST ?? '127.0.0.1',
  port: Number(process.env.REDIS_PORT ?? 6379),
  maxRetriesPerRequest: null,
});

const prefix = process.env.BULLMQ_PREFIX ?? 'bull';

const queues = {
  webhooks: new Queue('webhooks.outbound', { connection, prefix }),
  renewals: new Queue('subscriptions.renew', { connection, prefix }),
  probes: new Queue('gateways.probe', { connection, prefix }),
  expiry: new Queue('checkouts.expire', { connection, prefix }),
  reconcile: new Queue('charges.reconcile', { connection, prefix }),
};

const processors = {
  'webhooks.outbound': deliverOutboundWebhook,
  'subscriptions.renew': renewSubscription,
  'gateways.probe': probeGateways,
  'checkouts.expire': expireCheckouts,
  'charges.reconcile': reconcileCharge,
};

const workers = Object.entries(processors).map(([name, processor]) => {
  const worker = new Worker(name, processor, {
    connection,
    prefix,
    concurrency: Number(process.env.WORKER_CONCURRENCY ?? 4),
  });

  worker.on('failed', (job, err) => {
    console.error(
      JSON.stringify({
        msg: 'job_failed',
        queue: name,
        jobId: job?.id,
        attemptsMade: job?.attemptsMade,
        err: err.message,
      }),
    );
  });

  worker.on('completed', (job) => {
    console.log(JSON.stringify({ msg: 'job_completed', queue: name, jobId: job.id }));
  });

  return worker;
});

await queues.probes.add(
  'probe',
  {},
  {
    repeat: { every: Number(process.env.PROBE_INTERVAL_MS ?? 15000) },
    jobId: 'gateway-health-probe',
  },
);

await queues.expiry.add(
  'sweep',
  {},
  {
    repeat: { every: Number(process.env.EXPIRY_INTERVAL_MS ?? 60000) },
    jobId: 'stale-checkout-sweep',
  },
);

await queues.renewals.add(
  'daily',
  {},
  {
    repeat: { pattern: process.env.RENEWAL_CRON ?? '0 6 * * *' },
    jobId: 'subscription-renewal-cron',
  },
);

const laravelPrefix = process.env.LARAVEL_REDIS_PREFIX ?? '';

async function promoteLaravelJobs(listKey, queue) {
  const key = `${laravelPrefix}${listKey}`;
  while (true) {
    const popped = await connection.blpop(key, 5);
    if (!popped) {
      continue;
    }

    const job = JSON.parse(popped[1]);
    await queue.add('job', job, {
      jobId: job.idempotencyKey,
      attempts: 8,
      backoff: { type: 'exponential', delay: 2000 },
      removeOnComplete: 1000,
    });
  }
}

promoteLaravelJobs('checkouthub:jobs:webhooks.outbound', queues.webhooks).catch((err) => {
  console.error(JSON.stringify({ msg: 'promote_webhooks_failed', err: err.message }));
});
promoteLaravelJobs('checkouthub:jobs:subscriptions.renew', queues.renewals).catch((err) => {
  console.error(JSON.stringify({ msg: 'promote_renewals_failed', err: err.message }));
});
promoteLaravelJobs('checkouthub:jobs:charges.reconcile', queues.reconcile).catch((err) => {
  console.error(JSON.stringify({ msg: 'promote_reconcile_failed', err: err.message }));
});

console.log(JSON.stringify({ msg: 'worker_started', queues: Object.keys(processors) }));

const shutdown = async () => {
  await Promise.all(workers.map((worker) => worker.close()));
  await Promise.all(Object.values(queues).map((queue) => queue.close()));
  await connection.quit();
  process.exit(0);
};

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
