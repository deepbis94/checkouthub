export async function deliverOutboundWebhook(job) {
  const { url, payload, idempotencyKey, signature } = job.data;
  const attempts = job.opts.attempts ?? 8;
  const backoff = job.opts.backoff ?? { type: 'exponential', delay: 2000 };

  if (!url) {
    return { skipped: true };
  }

  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': idempotencyKey ?? `webhook-${job.id}`,
      'X-Correlation-Id': job.data.correlationId ?? `webhook-${job.id}`,
      ...(signature ? { 'X-CheckoutHub-Signature': signature } : {}),
    },
    body: JSON.stringify(payload ?? {}),
  });

  if (!response.ok) {
    const error = new Error(`webhook ${response.status}`);
    error.attempts = attempts;
    error.backoff = backoff;
    throw error;
  }

  return { status: response.status };
}
