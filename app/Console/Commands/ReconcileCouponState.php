<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3 read-only reconciliation detectors (INV-01–INV-07, INV-03).
 *
 * Detects impossible/diverged coupon states WITHOUT repairing anything.
 * Any repair must be explicit, authorized, auditable and idempotent
 * (see COUPON_REMEDIATION_FINAL_REPORT.md runbook). Exit code 1 when
 * issues are found so schedulers/monitors can alert.
 */
class ReconcileCouponState extends Command
{
    protected $signature = 'coupons:reconcile';
    protected $description = 'Report-only detection of impossible/diverged coupon states (never repairs)';

    public function handle(): int
    {
        $issues = [];

        $issues['completed_coupon_order_without_usage'] = $this->completedWithoutUsage();
        $issues['blocked_completion_paid_pending_with_coupon'] = $this->blockedCompletion();
        $issues['redeemed_claim_without_usage'] = $this->redeemedWithoutUsage();
        $issues['usage_without_order'] = $this->usageWithoutOrder();
        $issues['orphan_or_stale_reservations'] = $this->orphanReservations();
        $issues['coupon_counter_mismatch'] = $this->couponCounterMismatch();
        $issues['assignment_counter_mismatch'] = $this->assignmentCounterMismatch();
        $issues['duplicate_active_claims'] = $this->duplicateActiveClaims();
        $issues['orphan_claims'] = $this->orphanClaims();

        $total = 0;
        foreach ($issues as $name => $rows) {
            $count = is_countable($rows) ? count($rows) : (int) $rows;
            $total += $count;
            $this->line(sprintf('%-38s %d', $name, $count));
            if (is_array($rows) && $count > 0 && $count <= 20) {
                foreach (array_slice($rows, 0, 20) as $row) {
                    $this->line('  - ' . json_encode($row));
                }
            }
        }

        $this->line("TOTAL ISSUES: {$total}");

        return $total > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * INV-03: completed orders carrying a coupon snapshot but no usage proof.
     * Historical rows completed before fail-closed enforcement are expected
     * noise; investigate recent rows first (ordered by id desc).
     */
    private function completedWithoutUsage(): array
    {
        if (!Schema::hasTable('orders') || !Schema::hasColumn('orders', 'coupon')) {
            return [];
        }

        return DB::table('orders as o')
            ->leftJoin('coupon_usages as cu', function ($j) {
                $j->on('cu.order_id', '=', 'o.id');
            })
            ->leftJoin('coupon_assignment_usages as au', 'au.order_id', '=', 'o.id')
            ->where('o.status', 'completed')
            ->whereNotNull('o.coupon')
            ->whereNull('cu.id')
            ->whereNull('au.id')
            ->orderByDesc('o.id')
            ->limit(100)
            ->get(['o.id as order_id', 'o.user_id', 'o.coupon', 'o.coupon_discount', 'o.coupon_consumed'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * M1: failed transactions whose coupon order is still pending — includes
     * fail-closed coupon completions (quota/capacity/eligibility refused;
     * the gateway may still have captured funds, so the error_message must
     * be triaged). Requires manual ops handling: re-grant capacity and
     * retry, or refund. Genuinely declined payments also appear here;
     * distinguish via the recorded error_message.
     */
    private function blockedCompletion(): array
    {
        if (!Schema::hasTable('transactions') || !Schema::hasTable('orders')) {
            return [];
        }

        return DB::table('transactions as t')
            ->join('orders as o', 'o.id', '=', 't.order_id')
            ->where('t.status', 'failed')
            ->where('o.status', 'pending')
            ->whereNotNull('o.coupon')
            ->orderByDesc('t.id')
            ->limit(100)
            ->get(['t.id as transaction_id', 't.order_id', 'o.user_id', 'o.coupon', 'o.total_price', 't.error_message'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * INV-01: REDEEMED claims with no usage row for the same (coupon, user).
     * Note (S5): this checks (coupon, user) lifetime history, not the
     * claim's specific order — a REDEEMED claim whose user consumed via a
     * different order still counts as covered. Order-level linkage is
     * proven by the completedWithoutUsage detector above.
     */
    private function redeemedWithoutUsage(): array
    {
        if (!Schema::hasTable('coupon_claims')) {
            return [];
        }

        $redeemed = DB::table('coupon_claims')
            ->where('status', 'redeemed')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'coupon_id', 'user_id']);

        $bad = [];
        foreach ($redeemed as $claim) {
            $hasPublic = Schema::hasTable('coupon_usages') && DB::table('coupon_usages')
                ->where('coupon_id', $claim->coupon_id)
                ->where('user_id', $claim->user_id)
                ->exists();
            $hasAssigned = Schema::hasTable('coupon_assignment_usages') && DB::table('coupon_assignment_usages as au')
                ->join('coupon_assignments as a', 'a.id', '=', 'au.coupon_assignment_id')
                ->where('a.coupon_id', $claim->coupon_id)
                ->where('a.user_id', $claim->user_id)
                ->exists();
            if (!$hasPublic && !$hasAssigned) {
                $bad[] = (array) $claim;
            }
        }

        return $bad;
    }

    /**
     * Usage rows pointing at missing orders (assignment usages) plus public
     * usages recorded without an order context (order_id IS NULL).
     */
    private function usageWithoutOrder(): array
    {
        $bad = [];

        if (Schema::hasTable('coupon_assignment_usages')) {
            $orphans = DB::table('coupon_assignment_usages as au')
                ->leftJoin('orders as o', 'o.id', '=', 'au.order_id')
                ->whereNull('o.id')
                ->limit(100)
                ->get(['au.id', 'au.coupon_assignment_id', 'au.order_id'])
                ->map(fn ($r) => (array) $r)
                ->all();
            foreach ($orphans as $row) {
                $bad[] = ['type' => 'assignment_usage', ...$row];
            }
        }

        // S4: public usages without an order context.
        if (Schema::hasTable('coupon_usages')) {
            $nullOrder = DB::table('coupon_usages')
                ->whereNull('order_id')
                ->limit(100)
                ->get(['id', 'coupon_id', 'user_id', 'used_at'])
                ->map(fn ($r) => ['type' => 'public_usage_null_order', ...(array) $r])
                ->all();
            foreach ($nullOrder as $row) {
                $bad[] = $row;
            }
        }

        return $bad;
    }

    /**
     * Reservation rows that can never be consumed: order missing, order no
     * longer pending, or TTL long past (sweeper gap).
     */
    private function orphanReservations(): array
    {
        if (!Schema::hasTable('coupon_reservations')) {
            return [];
        }

        return DB::table('coupon_reservations as r')
            ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
            ->where(function ($q) {
                $q->whereNull('o.id')
                    ->orWhere('o.status', '!=', 'pending')
                    ->orWhere('r.expires_at', '<', now()->subHours(2));
            })
            ->limit(100)
            ->get(['r.id', 'r.coupon_id', 'r.user_id', 'r.order_id', 'r.expires_at'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * INV-07: coupons.used (denormalized) vs authoritative usage rows.
     * Compares global public usages + assignment usages against the counter.
     */
    private function couponCounterMismatch(): array
    {
        if (!Schema::hasTable('coupons')) {
            return [];
        }

        $bad = [];
        $coupons = DB::table('coupons')->get(['id', 'code', 'used']);
        foreach ($coupons as $coupon) {
            $public = Schema::hasTable('coupon_usages')
                ? DB::table('coupon_usages')->where('coupon_id', $coupon->id)->count() : 0;
            $assigned = 0;
            if (Schema::hasTable('coupon_assignment_usages') && Schema::hasTable('coupon_assignments')) {
                $assigned = DB::table('coupon_assignment_usages as au')
                    ->join('coupon_assignments as a', 'a.id', '=', 'au.coupon_assignment_id')
                    ->where('a.coupon_id', $coupon->id)
                    ->count();
            }
            if ((int) $coupon->used !== $public + $assigned) {
                $bad[] = [
                    'coupon_id' => $coupon->id,
                    'code' => $coupon->code,
                    'counter' => (int) $coupon->used,
                    'authoritative' => $public + $assigned,
                ];
            }
            if (count($bad) >= 100) {
                break;
            }
        }

        return $bad;
    }

    /**
     * assignments.used vs coupon_assignment_usages rows.
     */
    private function assignmentCounterMismatch(): array
    {
        if (!Schema::hasTable('coupon_assignments') || !Schema::hasTable('coupon_assignment_usages')) {
            return [];
        }

        return DB::table('coupon_assignments as a')
            ->leftJoin('coupon_assignment_usages as au', 'au.coupon_assignment_id', '=', 'a.id')
            ->groupBy('a.id', 'a.coupon_id', 'a.user_id', 'a.used', 'a.max_uses')
            ->havingRaw('COUNT(au.id) != a.used')
            ->limit(100)
            ->get(['a.id', 'a.coupon_id', 'a.user_id', 'a.used', 'a.max_uses', DB::raw('COUNT(au.id) as usage_rows')])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * INV-01/INV-12: more than one ACTIVE unexpired claim per (coupon, user)
     * indicates a serialization failure (unique was dropped in 2026_09_14).
     */
    private function duplicateActiveClaims(): array
    {
        if (!Schema::hasTable('coupon_claims')) {
            return [];
        }

        return DB::table('coupon_claims')
            ->select('coupon_id', 'user_id', DB::raw('COUNT(*) as active_count'))
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->groupBy('coupon_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(100)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * Claims referencing deleted coupons/users.
     */
    private function orphanClaims(): array
    {
        if (!Schema::hasTable('coupon_claims')) {
            return [];
        }

        // S5: grouped so future AND constraints cannot leak through the OR.
        return DB::table('coupon_claims as c')
            ->leftJoin('coupons as k', 'k.id', '=', 'c.coupon_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where(function ($q) {
                $q->whereNull('k.id')->orWhereNull('u.id');
            })
            ->limit(100)
            ->get(['c.id', 'c.coupon_id', 'c.user_id', 'c.status'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }
}
