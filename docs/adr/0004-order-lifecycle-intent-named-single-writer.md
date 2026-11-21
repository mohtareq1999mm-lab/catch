# Order lifecycle has one intent-named writer

Every change to an Order's lifecycle status goes through `App\Services\Order\OrderTransitionService`. Callers name *why* the order moves (`completeFromGateway`, `expireUnpaid`, `cancelByGuest`, `markDelivered`, …) instead of passing the eleven positional flags of the former `OrderService::changeOrderStatus($invoiceId, $status, $orderId, $emitPaymentSuccess, …)`. All twelve intents funnel into one private executor that owns the Order Flow gate, payment authority, delivery invariant, row lock, inventory reservation/restore, coupon and promotion consequences, status history, and lifecycle events.

Why: the positional signature made correctness depend on remembering flag order at fourteen call sites, and every new caller could silently invent a flag combination the lifecycle rules never sanctioned. Intent names make the caller's authority explicit and leave exactly one place where transition rules can change.

`OrderService::changeOrderStatus` and its `legacyChangeOrderStatus` delegate were a temporary compatibility seam: production callers were migrated first, then the test callers, and the seam was deleted. No caller passes positional transition flags any more.

## Considered Options

- **Keep the flag-based signature** — rejected: it is the defect. Flags encode internal mechanics, not intent, and drift between call sites is invisible in review.
- **One class per transition** — rejected: twelve classes would share the same executor, lock, and consequence block; the split adds indirection without isolating any rule.
- **Status value object with typed transitions** — deferred: it changes the public error contract (`Order|false`) in one step. The intent surface can adopt it later without touching call sites again.
- **Chosen**: one module, twelve intents, one private executor. The temporary delegating adapter that bridged the migration has since been deleted.

## Consequences

- New lifecycle behavior is added as an intent, never as a new flag or a direct `orders.status` write.
- The executor is deliberately the only place that knows transition consequences; the intents stay one-screen parameter sets.
- Known debt accepted by this decision and tracked separately: authorization checks live inside the executor rather than in policies; the executor body is long; lifecycle events are dispatched inside the transition transaction (pre-existing behavior, unchanged here).
- The delivery-completion rule (`paid + at least one fulfillment + every fulfillment delivered`) is no longer duplicated: both this executor's `delivered` guard and `ShipmentService::maybeCompleteOrder` ask `App\Services\Order\DeliveryCompletionInvariant`. Each caller keeps its own failure shape (throw vs. stay-as-is); neither restates the conditions.
- `docs/api/` and the API contract are untouched: routes, payloads, and response shapes are unchanged.

## Actor contract (explicit intent inputs)

Every public intent takes the caller's actor as a required second parameter — `intent(Order $order, TransitionActor $actor, …)` — and the executor reads no ambient state (`auth()` is gone from `OrderTransitionService`; the actor derives history identity, F-1 payment authority, and force-deliver permission).

Actor kinds and factories (`App\ValueObjects\TransitionActor`):

- `TransitionActor::system()` — workers, reapers, schedulers, callbacks: unauthenticated background.
- `TransitionActor::gateway()` — payment-provider callbacks: authority was established by provider verification.
- `TransitionActor::user($user)` — an authenticated user; anonymous resolves to `system`; authenticated users record `admin` when they hold `payments.mark_paid` or `update-order-status`, else `user`.
- `TransitionActor::guest()` — anonymous guest flows.

Derived semantics:

- History: `actor_id`/`actor_type` keep the legacy values for existing flows — authenticated users are recorded `admin` when they hold `payments.mark_paid` or `update-order-status`, else `user`; every other kind records `system` with a null id.
- F-1 (completing an UNPAID order): a payer actor needs `payments.mark_paid`; system/gateway/guest kinds carry authority by construction; a zero-payable order exempts the gate — there is nothing to collect.
- Force-deliver: the legacy `update-order-status` permission.

Legacy callers that read ambient `auth()` now pass the equivalent actor (`TransitionActor::user(auth()->user())`); background callers pass `TransitionActor::system()`. The legacy webhook (`PaymentTrait`) passes `TransitionActor::user(auth()->user())` to preserve its documented "authenticated actor without payments.mark_paid stays refused" parity; the canonical callback (`PaymentCompletionService`) passes `TransitionActor::gateway()`.
