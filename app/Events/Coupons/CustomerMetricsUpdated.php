<?php

namespace App\Events\Coupons;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Marvel\Database\Models\User;

/**
 * Fired after customer metrics are rebuilt post order completion.
 * Distribution listener fans out ONLY to coupons whose trees read metrics.
 */
class CustomerMetricsUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly ?int $orderId = null,
    ) {}
}
