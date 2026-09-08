const API_URL = process.env.API_URL ?? 'http://app:8000';
const WORKER_TOKEN = process.env.CHECKOUTHUB_WORKER_TOKEN ?? '';

export async function renewSubscription(job) {
  const response = await fetch(`${API_URL}/api/v1/internal/subscriptions/renew`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${WORKER_TOKEN}`,
      'Content-Type': 'application/json',
      'Idempotency-Key': job.data.idempotencyKey ?? `renew-${job.id}`,
    },
    body: JSON.stringify(job.data ?? {}),
  });

  if (response.status === 404) {
    return { skipped: true, reason: 'endpoint_not_ready' };
  }

  if (!response.ok) {
    throw new Error(`renew failed: ${response.status}`);
  }

  return response.json();
}
