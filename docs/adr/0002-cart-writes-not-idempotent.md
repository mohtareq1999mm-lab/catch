# Cart writes are intentionally not idempotent

Cart add/update/remove/clear endpoints accept no idempotency key, and a retried already-succeeded add increments the line quantity again (a duplicate network retry can double the quantity). This is a deliberate scope decision (D4): checkout and payment already have idempotency (`GuestIdempotencyKey`), cart writes do not, and the per-identity rate limiter (40 writes/min) is the only backpressure. The risk is accepted and documented for both web and mobile clients; mobile networks retry more aggressively, so clients should avoid blind retries of cart writes.

## Considered Options

- **Optional `Idempotency-Key` header for cart writes now** — rejected for this feature: widens scope beyond the approved baseline; replay cache keyed by identity + key (~10 min) remains the design if adopted later as a separate improvement.
- **Backend-side request de-duplication by payload hash** — rejected: ambiguous with legitimate repeated adds of the same line, which must increment.

## Consequences

- Retried successful adds are duplicated quantity, not errors — clients see `201` twice.
- Documented as a residual risk in the guest checkout evidence report and the API contract; revisit only as an approved follow-up feature.
