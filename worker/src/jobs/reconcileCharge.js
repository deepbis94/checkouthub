const API_URL = process.env.API_URL ?? 'http://app:8000';
const WORKER_TOKEN = process.env.CHECKOUTHUB_WORKER_TOKEN ?? '';

export async function reconcileCharge(job) {
  const { checkoutId, gateway, chargeRef, idempotencyKey, correlationId } = job.data ?? {};
  const response = await fetch(`${API_URL}/api/v1/internal/checkouts/reconcile`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${WORKER_TOKEN}`,
      'Content-Type': 'application/json',
      'Idempotency-Key': idempotencyKey ?? `reconcile-${job.id}`,
      'X-Correlation-Id': correlationId ?? `reconcile-${job.id}`,
    },
    body: JSON.stringify({
      checkout_id: checkoutId,
      gateway,
      charge_ref: chargeRef,
    }),
  });

  if (response.status === 404) {
    return { skipped: true, reason: 'endpoint_not_ready' };
  }

  if (!response.ok) {
    throw new Error(`reconcile failed: ${response.status}`);
  }

  const body = await response.json();
  if (body.status === 'inconclusive' || body.retry) {
    throw new Error('reconcile inconclusive');
  }

  return body;
}
