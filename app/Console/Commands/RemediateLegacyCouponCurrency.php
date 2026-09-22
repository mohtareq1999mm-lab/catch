<?php

namespace App\Console\Commands;

use App\Models\CurrencyRate;
use App\Services\Currency\CurrencyService;
use App\Services\Customer\CustomerMetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Order;

/**
 * FINAL CLOSURE Sec 5 — legacy currency remediation for coupon spend.
 *
 * Legacy predicate (pre-currency-feature rows): currency snapshot absent
 * (currency_code NULL) or converted_total_price NULL, never yet remediated.
 *
 * Case A (recoverable): original currency is known (row or paid transaction)
 * AND a HISTORICAL rate (<= order date, never today's substitution) exists
 * for original→base. The missing snapshot is filled deterministically.
 *
 * Case B (not recoverable): marked LEGACY_CURRENCY_UNRESOLVED;
 * spend metrics exclude these rows until remediation/business policy.
 * No rate is ever invented.
 *
 * Safety properties:
 * - Dry-run by default; --apply writes.
 * - Idempotent: fixed/marked rows leave the predicate; reruns are no-ops.
 * - Crash-safe metrics: candidate user IDs are persisted before writes and
 *   healed on the next --apply if a previous run died mid-flight.
 * - Race-safe writes: in-transaction predicate guards; concurrent reruns
 *   skip rows the other run already handled (affected-row check).
 * - Auditable: every applied change is logged with before/after + rate
 *   provenance (actual LKG row dates).
 * - Per-order transactions with row locks; base source is logged
 *   (settings row vs config default fallback).
 */
class RemediateLegacyCouponCurrency extends Command
{
    protected $signature = 'coupons:remediate-legacy-currency
                            {--apply : Write changes (default is dry-run report only)}
                            {--limit=500 : Max legacy orders to process}';

    protected $description = 'Audit/remediate pre-currency orders for coupon spend normalization (Case A backfill, Case B unresolved marking)';

    public const STATUS_UNRESOLVED = 'unresolved';

    private const PENDING_FILE = 'legacy_currency_pending_users.json';

    /** Same-currency attribution tolerance (order total vs txn amount). */
    private const AMOUNT_TOLERANCE = 0.01;

    public function handle(CurrencyService $currencyService, CustomerMetricsService $metricsService): int
    {
        if (!Schema::hasTable('orders')) {
            $this->error('orders table missing.');
            return 1;
        }

        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));
        $base = $currencyService->getBaseCode();
        $baseSource = \Marvel\Database\Models\Settings::query()->exists() ? 'settings' : 'config-default';
        if ($baseSource === 'config-default') {
            $this->warn('No settings row: base currency falls back to config default (' . $base . '). Verify before --apply.');
        }

        // Sec 4 audit block (actuals, this database).
        $audit = $this->auditCounts();
        $this->table(
            ['Audit metric', 'Count'],
            collect($audit)->map(fn ($v, $k) => [$k, $v])->values()->all()
        );

        // Crash recovery: a previous --apply may have died after order writes
        // but before the metrics rebuild. Heal those users first.
        $pendingHeal = $this->takePendingUsers();
        if ($apply && !empty($pendingHeal)) {
            $this->warn('Healing metrics for ' . count($pendingHeal) . ' users from an interrupted run.');
            $this->rebuildUsers($metricsService, $pendingHeal);
        }

        $legacy = Order::query()
            ->whereNull('legacy_currency_status')
            ->where(function ($q) {
                $q->whereNull('currency_code')
                    ->orWhereNull('converted_total_price');
            })
            ->with('transactions')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        // Persist candidates BEFORE writes so a crash remains healable.
        $candidateUsers = $legacy->pluck('user_id')->filter()->unique()->values()->all();
        if ($apply && !empty($candidateUsers)) {
            Storage::put(self::PENDING_FILE, json_encode($candidateUsers));
        }

        $recovered = [];
        $unresolved = [];

        foreach ($legacy as $order) {
            $result = $this->classify($order, $base, $currencyService);

            if ($result['status'] === 'recoverable') {
                $recovered[] = ['order' => $order, 'fill' => $result['fill'], 'provenance' => $result['provenance']];
                if ($apply) {
                    if (!$this->applyRecovery($order, $result['fill'], $base, $result['provenance'])) {
                        $this->warn("Order {$order->id}: skipped (handled by a concurrent run).");
                    }
                }
            } elseif ($result['status'] === 'unresolved') {
                $unresolved[] = ['order' => $order, 'reason' => $result['reason']];
                if ($apply) {
                    if (!$this->applyUnresolved($order, $result['reason'])) {
                        $this->warn("Order {$order->id}: skipped (handled by a concurrent run).");
                    }
                }
            }
        }

        $rebuilt = 0;
        if ($apply && !empty($candidateUsers)) {
            $rebuilt = $this->rebuildUsers($metricsService, $candidateUsers);
            Storage::delete(self::PENDING_FILE);
        }

        $this->table(
            ['Order', 'Before(converted)', 'After(converted)', 'Disposition'],
            array_merge(
                array_map(fn ($r) => [
                    $r['order']->id,
                    var_export($r['order']->converted_total_price, true),
                    $r['fill']['converted_total_price'],
                    'RECOVERABLE' . ($apply ? ' (applied)' : ' (dry-run)'),
                ], $recovered),
                array_map(fn ($r) => [
                    $r['order']->id,
                    var_export($r['order']->converted_total_price, true),
                    '—',
                    'UNRESOLVED: ' . $r['reason'] . ($apply ? ' (marked)' : ' (dry-run)'),
                ], $unresolved)
            )
        );

        $this->info(sprintf(
            'Legacy scanned: %d | Recoverable: %d | Unresolved: %d | Mode: %s | Base: %s (%s) | Metrics rebuilt for %d users',
            $legacy->count(), count($recovered), count($unresolved),
            $apply ? 'APPLY' : 'DRY-RUN', $base, $baseSource, $rebuilt
        ));

        if (!$apply && ($recovered || $unresolved)) {
            $this->comment('Dry-run only: no writes performed. Re-run with --apply to remediate.');
        }

        return 0;
    }

    /**
     * Sec 4 audit: total legacy orders, metadata/rate/validity split,
     * affected customers and potentially-affected spend evaluations.
     *
     * @return array<string, int>
     */
    private function auditCounts(): array
    {
        $hasStatus = Schema::hasColumn('orders', 'legacy_currency_status');
        $legacyShape = fn ($q) => $q->where(function ($qq) {
            $qq->whereNull('currency_code')->orWhereNull('converted_total_price');
        });

        return [
            'total_orders' => Order::query()->count(),
            'legacy_null_currency_code' => Order::query()->whereNull('currency_code')->count(),
            'legacy_null_converted' => Order::query()->whereNull('converted_total_price')->count(),
            'legacy_with_full_metadata' => Order::query()->whereNotNull('currency_code')
                ->whereNotNull('currency_rate')->whereNotNull('converted_total_price')->count(),
            'legacy_unresolved_marked' => $hasStatus
                ? Order::query()->where('legacy_currency_status', self::STATUS_UNRESOLVED)->count()
                : 0,
            'legacy_ambiguous_unmarked' => Order::query()
                ->when($hasStatus, fn ($q) => $q->whereNull('legacy_currency_status'))
                ->where($legacyShape)->count(),
            'affected_customers' => Order::query()->where($legacyShape)->distinct()->count('user_id'),
            'affected_spend_evaluations' => Order::query()->where($legacyShape)
                ->where('status', Order::ORDER_STATUS_COMPLETED)
                ->where('payment_status', Order::PAYMENT_STATUS_SUCCESS)
                ->distinct()->count('user_id'),
        ];
    }

    /**
     * @return array{status: string, fill?: array, reason?: string, provenance?: array}
     */
    private function classify(Order $order, string $base, CurrencyService $currencyService): array
    {
        // Dateless rows cannot anchor a historical rate: never substitute today.
        $orderDate = $order->created_at?->toDateString();
        if (!$orderDate) {
            return ['status' => 'unresolved', 'reason' => 'no_order_date'];
        }

        // Original currency: row is authoritative when present; else the
        // latest PAID transaction, else the latest transaction with currency.
        $original = $order->currency_code ? strtoupper(trim((string) $order->currency_code)) : null;
        $txnAmount = null;
        if (!$original) {
            $txns = $order->relationLoaded('transactions') ? $order->transactions : $order->transactions()->orderByDesc('id')->get();
            $paid = $txns->firstWhere('status', 'paid');
            $chosen = ($paid && $paid->currency) ? $paid : $txns->firstWhere(fn ($t) => !empty($t->currency));
            // $firstWhere with closure on Eloquent collection: fall back manually.
            if (!$chosen) {
                foreach ($txns as $t) {
                    if (!empty($t->currency)) {
                        $chosen = $t;
                        break;
                    }
                }
            }
            if (!$chosen) {
                return ['status' => 'unresolved', 'reason' => 'no_original_currency'];
            }
            $original = strtoupper(trim((string) $chosen->currency));
            $txnAmount = $chosen->amount;
        }

        // Same-currency attribution guard: txn amount must agree with the
        // order total (tolerance covers float storage, not fees/partials).
        if ($txnAmount !== null && abs((float) $txnAmount - (float) $order->total_price) >= self::AMOUNT_TOLERANCE) {
            return ['status' => 'unresolved', 'reason' => 'txn_amount_mismatch'];
        }

        // Same-currency fast path: no rate needed, conversion is identity.
        if ($original === strtoupper($base)) {
            return ['status' => 'recoverable', 'fill' => [
                'currency_code' => $original,
                'base_currency_code' => $base,
                'currency_rate' => '1',
                'currency_rate_date' => $orderDate,
                'converted_total_price' => round((float) $order->total_price, 2),
            ], 'provenance' => ['identity' => true]];
        }

        // Historical conversion only (<= order date). Missing rate → Case B.
        try {
            $conversion = $currencyService->convert((float) $order->total_price, $original, $base, $orderDate);
        } catch (\Throwable $e) {
            // Last resort: a stored rate already on the row (written at order
            // time). Prefer the validated historical path above; use this only
            // when history is absent, and say so in the audit log.
            if ($order->currency_rate && (float) $order->currency_rate > 0) {
                return ['status' => 'recoverable', 'fill' => [
                    'base_currency_code' => $base,
                    'currency_rate_date' => $orderDate,
                    'converted_total_price' => round((float) $order->total_price * (float) $order->currency_rate, 2),
                ], 'provenance' => ['stored_rate_fallback' => true, 'rate' => (string) $order->currency_rate]];
            }

            return ['status' => 'unresolved', 'reason' => 'no_historical_rate'];
        }

        return ['status' => 'recoverable', 'fill' => [
            'currency_code' => $original,
            'base_currency_code' => $base,
            'currency_rate' => $conversion->rate,
            'currency_rate_date' => $conversion->effectiveDate,
            'converted_total_price' => round((float) $conversion->convertedAmount, 2),
        ], 'provenance' => $this->rateProvenance($original, $base, $orderDate)];
    }

    /**
     * Actual LKG rate-row dates behind a conversion (auditability).
     */
    private function rateProvenance(string $from, string $to, string $date): array
    {
        $rowDate = function (string $code) use ($date) {
            return CurrencyRate::query()
                ->whereHas('currency', fn ($q) => $q->where('code', $code))
                ->whereDate('effective_date', '<=', $date)
                ->orderByDesc('effective_date')
                ->value('effective_date');
        };

        return [
            'from_rate_date' => (string) $rowDate($from),
            'to_rate_date' => (string) $rowDate($to),
        ];
    }

    /**
     * Returns true when this run performed the write, false when a
     * concurrent run already handled the row.
     */
    private function applyRecovery(Order $order, array $fill, string $base, array $provenance): bool
    {
        $before = $order->only(['currency_code', 'base_currency_code', 'currency_rate', 'currency_rate_date', 'converted_total_price']);

        $affected = DB::transaction(function () use ($order, $fill) {
            return Order::query()->whereKey($order->id)
                ->whereNull('legacy_currency_status')
                ->where(function ($q) {
                    $q->whereNull('currency_code')->orWhereNull('converted_total_price');
                })
                ->lockForUpdate()
                ->update($fill);
        });

        if (!$affected) {
            return false;
        }

        Log::info('coupons.legacy_currency.recovered', [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'base' => $base,
            'before' => $before,
            'after' => $fill,
            'provenance' => $provenance,
        ]);

        return true;
    }

    private function applyUnresolved(Order $order, string $reason): bool
    {
        $affected = DB::transaction(function () use ($order) {
            return Order::query()->whereKey($order->id)
                ->whereNull('legacy_currency_status')
                ->where(function ($q) {
                    $q->whereNull('currency_code')->orWhereNull('converted_total_price');
                })
                ->lockForUpdate()
                ->update(['legacy_currency_status' => self::STATUS_UNRESOLVED]);
        });

        if (!$affected) {
            return false;
        }

        Log::warning('coupons.legacy_currency.unresolved', [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'reason' => $reason,
        ]);

        return true;
    }

    private function rebuildUsers(CustomerMetricsService $metricsService, array $userIds): int
    {
        $n = 0;
        foreach ($userIds as $userId) {
            $user = \Marvel\Database\Models\User::find($userId);
            if ($user) {
                $metricsService->rebuildForUser($user);
                $n++;
            }
        }

        return $n;
    }

    private function takePendingUsers(): array
    {
        if (!Storage::exists(self::PENDING_FILE)) {
            return [];
        }
        $ids = json_decode((string) Storage::get(self::PENDING_FILE), true) ?: [];
        Storage::delete(self::PENDING_FILE);

        return array_values(array_filter(array_map('intval', (array) $ids)));
    }
}
