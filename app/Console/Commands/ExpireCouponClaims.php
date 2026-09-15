<?php

namespace App\Console\Commands;

use App\Services\Coupon\CouponClaimService;
use Illuminate\Console\Command;

/**
 * Phase 2 Fix #3: Scheduled Expiration Command
 *
 * Expires coupon claims that have passed their TTL (time-to-live).
 *
 * Transitions:
 * - ACTIVE claims with expires_at <= now() → EXPIRED
 * - Expired claims release capacity, allowing re-claims
 *
 * Scheduling:
 * - Runs hourly via cron to catch expirations within ~60min window
 * - Lightweight, locks only affected rows via WHERE clause
 * - Safe for concurrent execution (withoutOverlapping enforces single instance)
 */
class ExpireCouponClaims extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'coupons:expire-claims';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire coupon claims that have passed their TTL';

    public function __construct(
        private readonly CouponClaimService $claimService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Expiring coupon claims with passed TTL...');

        $expiredCount = $this->claimService->expireExpiredClaims();

        if ($expiredCount === 0) {
            $this->info('No expired claims found.');
            return self::SUCCESS;
        }

        $this->info("Expired {$expiredCount} coupon claim(s).");

        return self::SUCCESS;
    }
}
