<?php

namespace App\Listeners\Coupons;

use App\Enums\CouponDistributionTriggerType;
use App\Enums\QueueName;
use App\Events\Coupons\CustomerMetricsUpdated;
use App\Events\Coupons\UserAddressChanged;
use App\Services\Coupon\Distribution\Triggers\DistributionTriggerService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Database\Models\User;

/**
 * User-level triggers → single-user scoped runs, family-gated:
 * registration hits registration-family coupons, address changes hit
 * area_in coupons, metrics updates hit metrics-family coupons.
 */
class StartUserCouponDistribution implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return QueueName::high();
    }

    public function handle(Registered|UserAddressChanged|CustomerMetricsUpdated $event): void
    {
        $service = app(DistributionTriggerService::class);

        if ($event instanceof Registered) {
            $user = $event->user;

            if ($user instanceof User) {
                $service->distributeForUser($user, CouponDistributionTriggerType::USER_REGISTERED, 'registration');
            }

            return;
        }

        if ($event instanceof UserAddressChanged) {
            $user = User::query()->find($event->userId);

            if ($user !== null) {
                $service->distributeForUser(
                    $user,
                    CouponDistributionTriggerType::ADDRESS_CHANGED,
                    'area',
                    $event->addressId !== null ? 'address:'.$event->addressId : null,
                );
            }

            return;
        }

        $service->distributeForUser(
            $event->user,
            CouponDistributionTriggerType::ORDER_COMPLETED,
            'metrics',
            $event->orderId !== null ? 'order:'.$event->orderId : null,
        );
    }
}
