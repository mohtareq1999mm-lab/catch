<?php

namespace App\Services\Coupon\Distribution\Selection;

use App\Enums\EligibilityRuleType;
use App\Enums\UserType;
use Illuminate\Database\Eloquent\Builder;
use Marvel\Database\Models\Address;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\User;

/**
 * Rule-aware candidate discovery.
 *
 * Finds LIKELY candidates efficiently — never the final authority (the
 * EligibilityEngine decides per user). Strategy: push safe range/area
 * constraints into indexed SQL; relational rules (claimed / not_claimed /
 * has_assignment) are suppression semi-joins only, never sole fan-out
 * drivers; anything unparseable degrades to a bounded broad scan plus the
 * Engine (correctness over optimization).
 */
class CouponCandidateSelector
{
    /**
     * Build the candidate query for a coupon. Caller chunks with chunkById().
     */
    public function queryFor(Coupon $coupon, int $audienceCap): Builder
    {
        $targeting = $coupon->targeting;
        $extracted = RuleFamilyExtractor::extract($targeting?->rule_tree);

        $query = User::query()
            ->where('users.type', UserType::USER->value)
            ->select('users.*');

        if (! $extracted['parseable']) {
            return $query->orderBy('users.id');
        }

        if ($extracted['operator'] === 'OR') {
            return $this->applyOr($query, $coupon, $extracted)->orderBy('users.id');
        }

        $this->applyAndLeaves($query, $coupon, $extracted['leaves']);

        foreach ($extracted['groups'] as $group) {
            // Nested groups: only AND-subgroups narrow safely; OR-subgroups
            // are ignored here (broad) so no eligible user is ever excluded.
            if ($group['operator'] === 'AND' && $group['groups'] === []) {
                $this->applyAndLeaves($query, $coupon, $group['leaves']);
            }
        }

        return $query->orderBy('users.id');
    }

    private function applyOr(Builder $query, Coupon $coupon, array $extracted): Builder
    {
        $query->where(function ($or) use ($coupon, $extracted) {
            $branches = [];

            foreach ($extracted['leaves'] as $leaf) {
                $branches[] = [$leaf];
            }

            foreach ($extracted['groups'] as $group) {
                if ($group['operator'] === 'AND' && $group['groups'] === []) {
                    $branches[] = $group['leaves'];
                }
            }

            if ($branches === []) {
                // Unparseable OR shape: no constraint (broad, Engine filters).
                $or->whereRaw('1 = 1');

                return;
            }

            foreach ($branches as $i => $leaves) {
                if ($i === 0) {
                    $or->where(function ($b) use ($coupon, $leaves) {
                        $this->applyAndLeaves($b, $coupon, $leaves, true);
                    });
                } else {
                    $or->orWhere(function ($b) use ($coupon, $leaves) {
                        $this->applyAndLeaves($b, $coupon, $leaves, true);
                    });
                }
            }
        });

        return $query;
    }

    /**
     * @param  list<array{type: string, value: mixed}>  $leaves
     */
    private function applyAndLeaves(Builder $query, Coupon $coupon, array $leaves, bool $inOrBranch = false): void
    {
        $relationalOnly = true;

        foreach ($leaves as $leaf) {
            if (! $this->isRelational($leaf['type'])) {
                $relationalOnly = false;
                break;
            }
        }

        foreach ($leaves as $leaf) {
            $this->applyLeaf($query, $coupon, $leaf, $relationalOnly && ! $inOrBranch);
        }
    }

    private function isRelational(string $type): bool
    {
        return in_array($type, [
            EligibilityRuleType::CLAIMED->value,
            EligibilityRuleType::NOT_CLAIMED->value,
            EligibilityRuleType::HAS_ASSIGNMENT->value,
        ], true);
    }

    /**
     * @param  array{type: string, value: mixed}  $leaf
     */
    private function applyLeaf(Builder $query, Coupon $coupon, array $leaf, bool $soleDriver): void
    {
        $type = $leaf['type'];
        $value = $leaf['value'];
        $couponId = $coupon->getKey();

        switch ($type) {
            case EligibilityRuleType::MIN_COMPLETED_ORDERS->value:
                $query->whereHas('metrics', static fn ($q) => $q->where('completed_orders', '>=', (int) $value));
                break;

            case EligibilityRuleType::MAX_COMPLETED_ORDERS->value:
                // NULL metrics = 0 orders: include users without a metrics row.
                $query->where(static function ($q) use ($value) {
                    $q->whereHas('metrics', static fn ($m) => $m->where('completed_orders', '<=', (int) $value))
                        ->orWhereDoesntHave('metrics');
                });
                break;

            case EligibilityRuleType::MIN_TOTAL_SPEND->value:
                $query->whereHas('metrics', static fn ($q) => $q->where('total_qualifying_order_value', '>=', (float) $value));
                break;

            case EligibilityRuleType::MAX_TOTAL_SPEND->value:
                $query->where(static function ($q) use ($value) {
                    $q->whereHas('metrics', static fn ($m) => $m->where('total_qualifying_order_value', '<=', (float) $value))
                        ->orWhereDoesntHave('metrics');
                });
                break;

            case EligibilityRuleType::FIRST_ORDER_AFTER->value:
                $query->whereHas('metrics', static fn ($q) => $q->where('first_order_at', '>', $value));
                break;

            case EligibilityRuleType::FIRST_ORDER_BEFORE->value:
                $query->whereHas('metrics', static fn ($q) => $q->where('first_order_at', '<', $value));
                break;

            case EligibilityRuleType::LAST_ORDER_AFTER->value:
                $query->whereHas('metrics', static fn ($q) => $q->where('last_order_at', '>', $value));
                break;

            case EligibilityRuleType::LAST_ORDER_BEFORE->value:
                $query->whereHas('metrics', static fn ($q) => $q->where('last_order_at', '<', $value));
                break;

            case EligibilityRuleType::MIN_COUPONS_USED->value:
                $query->whereHas('metrics', static fn ($q) => $q->where('coupons_used', '>=', (int) $value));
                break;

            case EligibilityRuleType::MAX_COUPONS_USED->value:
                $query->where(static function ($q) use ($value) {
                    $q->whereHas('metrics', static fn ($m) => $m->where('coupons_used', '<=', (int) $value))
                        ->orWhereDoesntHave('metrics');
                });
                break;

            case EligibilityRuleType::REGISTERED_AFTER->value:
                $query->where('users.created_at', '>', $value);
                break;

            case EligibilityRuleType::REGISTERED_BEFORE->value:
                $query->where('users.created_at', '<', $value);
                break;

            case EligibilityRuleType::HAS_EMAIL->value:
                $query->whereNotNull('users.email')->where('users.email', '!=', '');
                break;

            case EligibilityRuleType::AREA_IN->value:
                $ids = array_values(array_filter(
                    array_map(static fn ($id) => is_numeric($id) ? (int) $id : 0, (array) (is_array($value) ? $value : [$value])),
                    static fn ($id) => $id > 0
                ));

                if ($ids === []) {
                    // Malformed area list can never match — the Engine fails
                    // closed too; force empty candidate set safely.
                    $query->whereRaw('1 = 0');

                    break;
                }

                // Saved-address ANY-match: user has ≥1 address in an ACTIVE
                // allowed governorate. NULL governorates never match.
                $query->whereExists(function ($exists) use ($ids) {
                    $exists->selectRaw('1')
                        ->from('address')
                        ->join('governorates', 'governorates.id', '=', 'address.governorate_id')
                        ->whereColumn('address.customer_id', 'users.id')
                        ->whereIn('address.governorate_id', $ids)
                        ->where('governorates.status', true);
                });
                break;

            case EligibilityRuleType::NOT_CLAIMED->value:
                // Suppression only: drop users blocked by an active/redeemed claim.
                $query->whereNotExists(function ($exists) use ($couponId) {
                    $exists->selectRaw('1')
                        ->from('coupon_claims')
                        ->whereColumn('coupon_claims.user_id', 'users.id')
                        ->where('coupon_claims.coupon_id', $couponId)
                        ->where(function ($blocked) {
                            $blocked->where('coupon_claims.status', 'redeemed')
                                ->orWhere(function ($active) {
                                    $active->where('coupon_claims.status', 'active')
                                        ->where(function ($unexpired) {
                                            $unexpired->whereNull('coupon_claims.expires_at')
                                                ->orWhere('coupon_claims.expires_at', '>', now());
                                        });
                                });
                        });
                });
                break;

            case EligibilityRuleType::CLAIMED->value:
                if ($soleDriver) {
                    // Never the sole fan-out driver: broad + Engine decides.
                    break;
                }
                $query->whereExists(function ($exists) use ($couponId) {
                    $exists->selectRaw('1')
                        ->from('coupon_claims')
                        ->whereColumn('coupon_claims.user_id', 'users.id')
                        ->where('coupon_claims.coupon_id', $couponId)
                        ->where(function ($has) {
                            $has->where('coupon_claims.status', 'redeemed')
                                ->orWhere(function ($active) {
                                    $active->where('coupon_claims.status', 'active')
                                        ->where(function ($unexpired) {
                                            $unexpired->whereNull('coupon_claims.expires_at')
                                                ->orWhere('coupon_claims.expires_at', '>', now());
                                        });
                                });
                        });
                });
                break;

            case EligibilityRuleType::HAS_ASSIGNMENT->value:
                if ($soleDriver) {
                    break;
                }
                $query->whereExists(function ($exists) use ($couponId) {
                    $exists->selectRaw('1')
                        ->from('coupon_assignments')
                        ->whereColumn('coupon_assignments.user_id', 'users.id')
                        ->where('coupon_assignments.coupon_id', $couponId)
                        ->where(function ($usable) {
                            $usable->whereNull('coupon_assignments.expires_at')
                                ->orWhere('coupon_assignments.expires_at', '>', now());
                        })
                        ->whereColumn('coupon_assignments.used', '<', 'coupon_assignments.max_uses');
                });
                break;

            default:
                // Unknown rule: NO constraint (broad). The Engine fail-closes
                // per user; the selector must never exclude on ignorance.
                break;
        }
    }
}
