# Order lifecycle has one intent-named writer

Every change to an Order's lifecycle status goes through `App\Services\Order\OrderTransitionService`. Callers name *why* the order moves (`completeFromGateway`, `expireUnpaid`, `cancelByGuest`, `markDelivered`, …) instead of passing the eleven positional flags of the former `OrderService::changeOrderStatus($invoiceId, $status, $orderId, $emitPaymentSuccess, …)`. All twelve intents funnel into one private executor that owns the Order Flow gate, payment authority, delivery invariant, row lock, inventory reservation/restore, coupon and promotion consequences, status history, and lifecycle events.

Why: the positional signature made correctness depend on remembering flag order at fourteen call sites, and every new caller could silently invent a flag combination the lifecycle rules never sanctioned. Intent names make the caller's authority explicit and leave exactly one place where transition rules can change.

`OrderService::changeOrderStatus` remains as a deprecated adapter that forwards to `OrderTransitionService::legacyChangeOrderStatus`. Production callers were migrated in this change; the adapter exists only for the remaining test callers and is deleted once they move to intents.

## Considered Options

- **Keep the flag-based signature** — rejected: it is the defect. Flags encode internal mechanics, not intent, and drift between call sites is invisible in review.
- **One class per transition** — rejected: twelve classes would share the same executor, lock, and consequence block; the split adds indirection without isolating any rule.
- **Status value object with typed transitions** — deferred: it changes the public error contract (`Order|false`) and the legacy callers in one step. The intent surface can adopt it later without touching call sites again.
- **Chosen**: one module, twelve intents, one private executor, temporary delegating adapter.

## Consequences

- New lifecycle behavior is added as an intent, never as a new flag or a direct `orders.status` write.
- The executor is deliberately the only place that knows transition consequences; the intents stay one-screen parameter sets.
- Known debt accepted by this decision and tracked separately: authorization checks live inside the executor rather than in policies; the executor body is long; lifecycle events are dispatched inside the transition transaction (pre-existing behavior, unchanged here); the delivery-completion rule is duplicated with `ShipmentService::maybeCompleteOrder` and the two must change together.
- `docs/api/` and the API contract are untouched: routes, payloads, and response shapes are unchanged.
