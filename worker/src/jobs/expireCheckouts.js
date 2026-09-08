const API_URL = process.env.API_URL ?? 'http://app:8000';
const WORKER_TOKEN = process.env.CHECKOUTHUB_WORKER_TOKEN ?? '';

export async function expireCheckouts(job) {
  // Phase 4 wires the sweep endpoint; keep the job idempotent by jobId.
  const url = `${API_URL}/api/v1/internal/checkouts/expire`;
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${WORKER_TOKEN}`,
      'Idempotency-Key': `expire-${job.id}`,
      'X-Correlation-Id': `expire-${job.id}`,
    },
  });

  if (response.status === 404) {
    return { skipped: true, reason: 'endpoint_not_ready' };
  }

  if (!response.ok) {
    throw new Error(`expire sweep failed: ${response.status}`);
  }

  return response.json();
}
