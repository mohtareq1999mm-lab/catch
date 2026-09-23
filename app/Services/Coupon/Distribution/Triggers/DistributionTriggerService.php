<?php

namespace App\Services\Coupon\Distribution\Triggers;

use App\Enums\CouponDistributionTriggerType;
use App\Enums\EligibilityRuleType;
use App\Services\Coupon\Distribution\DistributionService;
use App\Services\Coupon\Distribution\Selection\RuleFamilyExtractor;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\User;

/**
 * "Can this event change a user's eligibility?" gate.
 *
 * Per-user triggers fan out ONLY to distributable coupons whose rule trees
 * actually read the changed family. Coupon count is admin-curated (small),
 * so family filtering in PHP is cheap and exact.
 */
class DistributionTriggerService
{
    /**
     * @return array<string, list<string>>
     */
    public static function families(): array
    {
        return [
            'area' => [EligibilityRuleType::AREA_IN->value],
            'metrics' => [
                EligibilityRuleType::MIN_COMPLETED_ORDERS->value,
                EligibilityRuleType::MAX_COMPLETED_ORDERS->value,
                EligibilityRuleType::MIN_TOTAL_SPEND->value,
                EligibilityRuleType::MAX_TOTAL_SPEND->value,
                EligibilityRuleType::FIRST_ORDER_AFTER->value,
                EligibilityRuleType::FIRST_ORDER_BEFORE->value,
                EligibilityRuleType::LAST_ORDER_AFTER->value,
                EligibilityRuleType::LAST_ORDER_BEFORE->value,
                EligibilityRuleType::MIN_COUPONS_USED->value,
                EligibilityRuleType::MAX_COUPONS_USED->value,
            ],
            'registration' => [
                EligibilityRuleType::REGISTERED_AFTER->value,
                EligibilityRuleType::REGISTERED_BEFORE->value,
            ],
            'claim' => [
                EligibilityRuleType::CLAIMED->value,
                EligibilityRuleType::NOT_CLAIMED->value,
            ],
            'assignment' => [EligibilityRuleType::HAS_ASSIGNMENT->value],
            'email' => [EligibilityRuleType::HAS_EMAIL->value],
        ];
    }

    public function __construct(
        private readonly DistributionService $distributions,
    ) {}

    /**
     * Distributable coupons whose trees read the given family.
     *
     * @return list<Coupon>
     */
    public function affectedCoupons(string $family): array
    {
        $wanted = self::families()[$family] ?? null;

        if ($wanted === null) {
            return [];
        }

        $coupons = Coupon::query()
            ->where('status', true)
            ->whereHas('targeting', static function ($q) {
                $q->whereIn('mode', ['dynamic', 'assignment_and_dynamic', 'assignment_or_dynamic']);
            })
            ->with('targeting')
            ->get();

        $affected = [];

        foreach ($coupons as $coupon) {
            $types = RuleFamilyExtractor::leafTypes(
                RuleFamilyExtractor::extract($coupon->targeting?->rule_tree)
            );

            if (array_intersect($types, $wanted) !== []) {
                $affected[] = $coupon;
            }
        }

        return $affected;
    }

    /**
     * Fan one user out to every affected coupon (single-user scope, so the
     * start handler evaluates just this user). Double-fires converge via
     * run dedupe + cross-run notified state.
     *
     * @return list<int> run ids started (created or joined)
     */
    public function distributeForUser(
        User $user,
        CouponDistributionTriggerType $trigger,
        string $family,
        ?string $triggerId = null,
    ): array {
        $runIds = [];

        try {
            $coupons = $this->affectedCoupons($family);
        } catch (\Throwable $e) {
            // Distribution triggers must never break the originating flow
            // (checkout/registration/address). Log and skip the fan-out.
            Log::warning('coupon.trigger.affected_lookup_failed', [
                'user_id' => $user->getKey(),
                'trigger' => $trigger->value,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        foreach ($coupons as $coupon) {
            try {
                $result = $this->distributions->startDistribution(
                    $coupon,
                    $trigger,
                    'user:'.$user->getKey(),
                    $triggerId,
                    1,
                    null,
                );

                $runIds[] = $result['run']->getKey();
            } catch (\Throwable $e) {
                Log::warning('coupon.trigger.distribution_failed', [
                    'coupon_id' => $coupon->getKey(),
                    'user_id' => $user->getKey(),
                    'trigger' => $trigger->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $runIds;
    }
}
