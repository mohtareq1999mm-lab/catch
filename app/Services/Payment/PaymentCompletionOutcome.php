<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * Canonical outcome of a locked payment-completion attempt.
 *
 * Returned outcomes (no state change beyond idempotency bookkeeping):
 * - Processed: order completed exactly once via the canonical commit.
 * - IdempotentReplay: transaction already carried an idempotency token.
 * - OrderNotPending: order left pending state without a duplicate payment.
 * - DuplicateHold: provider reports paid for a non-pending order on a
 *   DIFFERENT transaction row than the completing one. Recorded in
 *   payment_reconciliation_results for ops; order/txn completion untouched.
 * - UnknownOrder: locked transaction has no order (defensive; callers
 *   normally guard this before entering the locked section).
 *
 * Thrown (never returned) outcomes:
 * - Mismatch: amount/currency/provider-ref divergence. Reported via
 *   PaymentMismatchException so the caller marks the transaction failed.
 * - CouponBlocked: coupon policy refused completion. The original
 *   CouponConsumptionException bubbles so the caller's transaction rolls
 *   back and the order stays non-completed.
 *
 * NOTE (dead cases): Mismatch/CouponBlocked exist as enum cases but are
 * never RETURNED — only thrown as exceptions. They stay in the enum so
 * match() arms over outcomes remain exhaustive and future refactors can
 * convert a throw into a return without touching every caller.
 */
enum PaymentCompletionOutcome: string
{
    case Processed = 'processed';
    case IdempotentReplay = 'idempotent_replay';
    case OrderNotPending = 'order_not_pending';
    case Mismatch = 'mismatch';
    case CouponBlocked = 'coupon_blocked';
    case DuplicateHold = 'duplicate_hold';
    case UnknownOrder = 'unknown_order';
}
