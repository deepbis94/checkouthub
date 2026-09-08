const API_URL = process.env.API_URL ?? 'http://app:8000';
const WORKER_TOKEN = process.env.CHECKOUTHUB_WORKER_TOKEN ?? '';

export async function probeGateways(job) {
  const response = await fetch(`${API_URL}/api/v1/internal/gateways/probe`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${WORKER_TOKEN}`,
      'Content-Type': 'application/json',
      'X-Correlation-Id': `probe-${job.id}`,
      'Idempotency-Key': `probe-${job.id}`,
    },
  });

  if (!response.ok) {
    throw new Error(`probe failed: ${response.status}`);
  }

  return response.json();
}
