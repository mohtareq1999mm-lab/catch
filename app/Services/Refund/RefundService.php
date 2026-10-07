<?php

declare(strict_types=1);

namespace App\Services\Refund;

use App\Events\Refund\RefundProcessed;
use App\Events\RefundApproved;
use App\Models\OrderTrackingEvent;
use App\Services\Payment\CurrencyPrecision;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Refund;
use Marvel\Database\Models\Transaction;
use Marvel\Enums\Role;

/**
 * Phase 10 unification — THE canonical refund domain service.
 *
 * ONE refund domain, ONE write path. Every surviving refund entry point
 * (customer request, Marvel request controller, admin approve/reject)
 * delegates here. There is no competing implementation.
 *
 * Lifecycle (the only states this phase writes):
 *   pending -> approved | pending -> rejected
 *
 * Hard rules enforced here:
 * - NO payment-gateway call (no Stripe/PayPal/MyFatoorah adapter usage).
 * - NO wallet / balance / shop-earnings mutation.
 * - NO payment_status / Order Flow mutation (refunds are a separate domain).
 * - Server-authoritative amounts in minor units; client amounts validated.
 * - Over-refund impossible: remaining = paid - approved(table) - approved
 *   (legacy txn ledger, read-only) enforced inside the locked transaction.
 * - Exactly-once approval: atomic PENDING -> APPROVED under lock; replays
 *   fail closed with no side effects.
 * - Inventory restore happens ONLY on full refunds (cumulative approved
 *   == paid), because amount-only partials carry no item scope and must
 *   never move ambiguous stock. Reviews are never auto-deleted: the
 *   reviews table carries no order/item scope, so no refund can safely
 *   determine which reviews belong to it.
 *
 * LOCKING: Transaction -> Order -> Refund (the F-AUDIT-01 global ordering).
 * The transaction row is locked because it is the paid-money authority;
 * the order row serializes concurrent approvals; the refund row makes the
 * state transition atomic.
 */
class RefundService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * @return array{currency: string, paid_minor: int, approved_minor: int, remaining_minor: int, transaction_id: ?int}
     */
    public function remainingFor(Order $order): array
    {
        $paid = $this->paidTotal($order);
        $approvedMinor = $this->approvedMinor((int) $order->getKey(), $paid['currency']);

        return [
            'currency' => $paid['currency'],
            'paid_minor' => $paid['paid_minor'],
            'approved_minor' => $approvedMinor,
            'remaining_minor' => max(0, $paid['paid_minor'] - $approvedMinor),
            'transaction_id' => $paid['transaction_id'],
        ];
    }

    /**
     * Customer refund request. Creates PENDING and stops — no approval, no
     * inventory movement, no gateway, no wallet.
     *
     * @param array{order_id: int, amount: float, title?: ?string, description?: ?string, currency?: ?string, refund_reason_id?: ?int, images?: ?array} $data
     *
     * @throws \RuntimeException fail-closed on every rule violation.
     */
    public function request(int $customerId, array $data): Refund
    {
        return DB::transaction(function () use ($customerId, $data) {
            $order = Order::query()->whereKey((int) ($data['order_id'] ?? 0))->lockForUpdate()->first();

            if (!$order) {
                throw new \RuntimeException(__('message.ERROR.NOT_FOUND'));
            }

            $this->assertOwnership($customerId, $order);

            $paid = $this->paidTotal($order);

            if ($paid['paid_minor'] <= 0) {
                throw new \RuntimeException(__('message.ERROR.WRONG_REFUND'));
            }

            $requestMinor = CurrencyPrecision::toMinorUnits((float) ($data['amount'] ?? 0), $paid['currency']);

            if ($requestMinor <= 0) {
                throw new \RuntimeException(__('message.ERROR.INVALID_AMOUNT'));
            }

            $currency = isset($data['currency']) && trim((string) $data['currency']) !== ''
                ? strtoupper(trim((string) $data['currency']))
                : $paid['currency'];

            if ($currency !== $paid['currency']) {
                throw new \RuntimeException(
                    __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => $currency])
                );
            }

            // Request-time cap against APPROVED rows only (pending requests
            // reserve nothing — concurrent pendings are allowed and the
            // approval serializes them under lock, so overlapping requests
            // fail at decision time, never at request time).
            $approvedMinor = $this->approvedMinor((int) $order->getKey(), $paid['currency']);

            if ($requestMinor > max(0, $paid['paid_minor'] - $approvedMinor)) {
                throw new \RuntimeException(__('message.ERROR.WRONG_REFUND'));
            }

            // NOTE: only real ledger columns are written. Legacy inputs such
            // as images/shop/customer references have no columns backing
            // them and are intentionally not persisted.
            $refund = Refund::query()->create([
                'order_id' => $order->getKey(),
                'user_id' => $customerId,
                'amount' => CurrencyPrecision::fromMinorUnits($requestMinor, $paid['currency']),
                'currency' => $paid['currency'],
                'title' => isset($data['title']) && trim((string) $data['title']) !== ''
                    ? mb_substr(strip_tags((string) $data['title']), 0, 191)
                    : 'Refund request',
                'description' => isset($data['description']) ? mb_substr(strip_tags((string) $data['description']), 0, 10000) : null,
                'refund_reason_id' => $data['refund_reason_id'] ?? null,
                'status' => self::STATUS_PENDING,
            ]);

            $this->track($order, $refund, 'refund.requested', [
                'actor_type' => 'customer',
                'actor_id' => $customerId,
                'metadata' => [
                    'refund_id' => $refund->getKey(),
                    'refund_amount' => $refund->amount,
                    'order_number' => $order->order_number ?? null,
                ],
            ]);

            return $refund->fresh() ?? $refund;
        });
    }

    /**
     * Admin approval. Atomic PENDING -> APPROVED with full side-effect fan-out
     * via the canonical RefundApproved event. Idempotent: a non-pending row
     * fails closed with no side effects.
     *
     * @throws \RuntimeException
     */
    public function approve(int $refundId, int $adminId, ?string $note = null): Refund
    {
        return DB::transaction(function () use ($refundId, $adminId, $note) {
            // Lock-free probes first (no lock participation, fail fast).
            $probe = Refund::query()->whereKey($refundId)->first();

            if (!$probe) {
                throw new \RuntimeException(__('message.ERROR.NOT_FOUND'));
            }

            // Global lock order Transaction -> Order -> Refund (F-AUDIT-01):
            // the paid-money row first, then the order, then the decision
            // row — the same direction as payment callbacks, so no AB-BA
            // edge can deadlock against the webhook path.
            $orderProbe = Order::query()->whereKey((int) $probe->order_id)->first();

            if (!$orderProbe) {
                throw new \RuntimeException(__('message.ERROR.NOT_FOUND'));
            }

            $paid = $this->paidTotal($orderProbe, true);

            $order = Order::query()->whereKey((int) $probe->order_id)->lockForUpdate()->firstOrFail();

            $refund = Refund::query()->whereKey($refundId)->lockForUpdate()->first();

            if (!$refund) {
                throw new \RuntimeException(__('message.ERROR.NOT_FOUND'));
            }

            if ($refund->status !== self::STATUS_PENDING) {
                throw new \RuntimeException(__('message.ERROR.ALREADY_REFUNDED'));
            }

            // D7 (preserved): delivered digital entitlements are not refundable.
            $hasDeliveredDigital = \App\Models\DigitalEntitlement::query()
                ->where('order_id', $order->getKey())
                ->where('status', \App\Models\DigitalEntitlement::STATUS_DELIVERED)
                ->exists();

            if ($hasDeliveredDigital) {
                throw new \RuntimeException(__('message.ERROR.DIGITAL_NOT_REFUNDABLE_AFTER_DELIVERY'));
            }

            if ($paid['paid_minor'] <= 0) {
                throw new \RuntimeException(__('message.ERROR.WRONG_REFUND'));
            }

            $requestMinor = CurrencyPrecision::toMinorUnits((float) $refund->amount, $paid['currency']);
            $approvedOthersMinor = $this->approvedMinor((int) $order->getKey(), $paid['currency'], (int) $refund->getKey());

            if ($requestMinor <= 0 || $requestMinor > max(0, $paid['paid_minor'] - $approvedOthersMinor)) {
                throw new \RuntimeException(__('message.ERROR.WRONG_REFUND'));
            }

            $isFull = ($paid['paid_minor'] - $approvedOthersMinor - $requestMinor) <= 0;

            $refund->update([
                'status' => self::STATUS_APPROVED,
                'currency' => $refund->currency ?: $paid['currency'],
                'decided_by' => $adminId,
                'decided_at' => now(),
                'decision_note' => $note !== null && trim($note) !== '' ? mb_substr(strip_tags($note), 0, 10000) : null,
            ]);

            $fresh = $refund->fresh() ?? $refund;

            // Canonical fan-out. Both events are ShouldDispatchAfterCommit:
            // listeners run only after this transaction commits.
            event(new RefundApproved($fresh));
            event(new RefundProcessed($fresh, $order->fresh() ?? $order, $isFull ? 'full' : 'partial'));

            return $fresh;
        });
    }

    /**
     * Admin rejection. Atomic PENDING -> REJECTED. No money-adjacent side
     * effects; the decision itself is tracked.
     *
     * @throws \RuntimeException
     */
    public function reject(int $refundId, int $adminId, ?string $note = null): Refund
    {
        return DB::transaction(function () use ($refundId, $adminId, $note) {
            // Lock-free probe, then global order Order -> Refund (subset of
            // the Transaction -> Order -> Refund sequence; rejection never
            // locks the transaction row).
            $probe = Refund::query()->whereKey($refundId)->first();

            if (!$probe) {
                throw new \RuntimeException(__('message.ERROR.NOT_FOUND'));
            }

            $order = Order::query()->whereKey((int) $probe->order_id)->lockForUpdate()->first();

            if (!$order) {
                throw new \RuntimeException(__('message.ERROR.NOT_FOUND'));
            }

            $refund = Refund::query()->whereKey($refundId)->lockForUpdate()->first();

            if (!$refund) {
                throw new \RuntimeException(__('message.ERROR.NOT_FOUND'));
            }

            if ($refund->status !== self::STATUS_PENDING) {
                throw new \RuntimeException(__('message.ERROR.ALREADY_REFUNDED'));
            }

            $refund->update([
                'status' => self::STATUS_REJECTED,
                'decided_by' => $adminId,
                'decided_at' => now(),
                'decision_note' => $note !== null && trim($note) !== '' ? mb_substr(strip_tags($note), 0, 10000) : null,
            ]);

            $fresh = $refund->fresh() ?? $refund;

            $this->track($order, $fresh, 'refund.rejected', [
                'actor_type' => 'admin',
                'actor_id' => $adminId,
                'metadata' => [
                    'refund_id' => $fresh->getKey(),
                    'refund_amount' => $fresh->amount,
                    'reason' => $note,
                ],
            ]);

            return $fresh;
        });
    }

    /**
     * True when the order's cumulative approved refunds cover the paid total.
     * Used by side-effect owners (inventory, reviews) to act on full refunds
     * only. Lock-free read for listeners (runs after commit).
     */
    public function isFullRefund(Order $order): bool
    {
        $paid = $this->paidTotal($order);

        if ($paid['paid_minor'] <= 0) {
            return false;
        }

        return $this->approvedMinor((int) $order->getKey(), $paid['currency']) >= $paid['paid_minor'];
    }

    /**
     * Customer refund list with counts/summary. Totals are grouped per
     * currency — different currencies are never summed together.
     *
     * @return array{data: array<int, mixed>, summary: array<string, mixed>}
     */
    public function listForCustomer(int $customerId, int $perPage = 15): array
    {
        $query = Refund::query()->with(['order', 'customer', 'refund_reason', 'refund_policy'])->where('user_id', $customerId)->latest();

        return $this->paginateWithSummary($query, $perPage);
    }

    /**
     * Admin refund list. Paginated rows plus the same summary shape as the
     * customer list (totals grouped per currency, never summed across).
     *
     * @param array{status?: ?string, order_id?: ?int} $filters
     */
    public function listForAdmin(array $filters = [], int $perPage = 15): array
    {
        $query = Refund::query()->with(['order', 'customer', 'refund_reason', 'refund_policy'])->latest();

        if (!empty($filters['status']) && in_array($filters['status'], [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['order_id'])) {
            $query->where('order_id', (int) $filters['order_id']);
        }

        return $this->paginateWithSummary($query, $perPage);
    }

    /**
     * Shared paginator + summary shape for both list surfaces. Totals are
     * grouped per currency — different currencies are never summed together.
     */
    private function paginateWithSummary($query, int $perPage): array
    {
        $all = (clone $query)->get();

        $summary = [
            'total_refunds' => $all->count(),
            'pending_refunds' => $all->where('status', self::STATUS_PENDING)->count(),
            'approved_refunds' => $all->where('status', self::STATUS_APPROVED)->count(),
            'rejected_refunds' => $all->where('status', self::STATUS_REJECTED)->count(),
            'totals_by_currency' => [],
        ];

        foreach ($all->where('status', self::STATUS_APPROVED)->groupBy(fn ($r) => strtoupper((string) ($r->currency ?? '')) ?: 'UNKNOWN') as $currency => $rows) {
            $summary['totals_by_currency'][$currency] = [
                'approved_amount' => round($rows->sum(fn ($r) => (float) $r->amount), 3),
            ];
        }

        $paginator = $query->paginate(min(max($perPage, 1), 100));

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => $summary,
        ];
    }

    // ------------------------------------------------------------------
    // Internal authorities.
    // ------------------------------------------------------------------

    /**
     * Paid-money authority. Prefers the latest paid transaction row (actual
     * collected funds, read-only — never written here); falls back to the
     * order total when payment is marked success without a txn row (e.g.
     * manual/offline settlement). Zero when nothing was paid.
     *
     * @return array{currency: string, paid_minor: int, transaction_id: ?int}
     */
    private function paidTotal(Order $order, bool $locked = false): array
    {
        $txnQuery = Transaction::query()->where('order_id', $order->getKey())
            ->whereIn('status', ['paid', 'partially_refunded'])
            ->latest();

        $txn = $locked ? $txnQuery->lockForUpdate()->first() : $txnQuery->first();

        if ($txn && (float) $txn->amount > 0 && trim((string) $txn->currency) !== '') {
            $currency = strtoupper(trim((string) $txn->currency));

            return [
                'currency' => $currency,
                'paid_minor' => CurrencyPrecision::toMinorUnits((float) $txn->amount, $currency),
                'transaction_id' => (int) $txn->getKey(),
            ];
        }

        $currency = strtoupper(trim((string) ($order->currency_code ?? ''))) ?: 'KWD';

        if (($order->payment_status ?? null) === Order::PAYMENT_STATUS_SUCCESS && (float) ($order->total_price ?? 0) > 0) {
            return [
                'currency' => $currency,
                'paid_minor' => CurrencyPrecision::toMinorUnits((float) $order->total_price, $currency),
                'transaction_id' => null,
            ];
        }

        return ['currency' => $currency, 'paid_minor' => 0, 'transaction_id' => null];
    }

    /**
     * Sum of approved refund rows for the order (canonical ledger) PLUS any
     * legacy provider-ledger entries on the paid transaction (read-only
     * conservative accounting for pre-unification Track B money movement).
     */
    private function approvedMinor(int $orderId, string $currency, ?int $excludeRefundId = null): int
    {
        $query = Refund::query()->where('order_id', $orderId)->where('status', self::STATUS_APPROVED);

        if ($excludeRefundId !== null) {
            $query->whereKeyNot($excludeRefundId);
        }

        $total = 0;

        foreach ($query->pluck('amount') as $amount) {
            $total += CurrencyPrecision::toMinorUnits((float) $amount, $currency);
        }

        $txn = Transaction::query()->where('order_id', $orderId)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->latest()
            ->first();

        if ($txn && is_array($txn->gateway_response)) {
            foreach ((array) ($txn->gateway_response['_refunds'] ?? []) as $entry) {
                if (is_array($entry) && isset($entry['amount']) && is_numeric($entry['amount'])) {
                    $total += CurrencyPrecision::toMinorUnits((float) $entry['amount'], $currency);
                }
            }
        }

        return $total;
    }

    private function assertOwnership(int $customerId, Order $order): void
    {
        $ownerId = $order->user_id ?? $order->customer_id ?? null;

        if ((int) $ownerId === $customerId) {
            return;
        }

        $user = \Marvel\Database\Models\User::query()->whereKey($customerId)->first();

        if ($user && $user->hasRole(Role::SUPER_ADMIN)) {
            return;
        }

        throw new \RuntimeException(__('message.ERROR.NOT_AUTHORIZED'));
    }

    /**
     * @param array{actor_type: string, actor_id: ?int, metadata: array<string, mixed>} $context
     */
    private function track(Order $order, Refund $refund, string $eventType, array $context): void
    {
        $actorType = $context['actor_type'];
        $actorId = $context['actor_id'] ?? null;

        OrderTrackingEvent::create([
            'order_id' => $order->getKey(),
            'event_type' => $eventType,
            'event_timestamp' => now(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_name' => $actorType === 'admin' && $actorId
                ? (\App\Models\User::find($actorId)?->name ?? 'Admin User')
                : 'Customer',
            'old_status' => null,
            'new_status' => null,
            'metadata' => $context['metadata'] ?? [],
            'customer_visible' => true,
            'customer_label_key' => 'tracking.refund.' . explode('.', $eventType)[1],
            'customer_description_key' => 'tracking.refund.' . explode('.', $eventType)[1] . '_description',
            'admin_notes' => null,
            'source' => 'system',
            'ip_address' => request()->ip(),
        ]);
    }
}
