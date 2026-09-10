<?php

namespace App\Console\Commands;

use App\Models\CouponReservation;
use Illuminate\Console\Command;

/**
 * Cleanup expired coupon reservations.
 *
 * Runs every 5 minutes to delete reservations that have passed their 30min TTL.
 * This makes those coupons available for other users again.
 */
class ExpireCouponReservations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'coupons:expire-reservations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete expired coupon reservations to free up capacity';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $deleted = CouponReservation::where('expires_at', '<=', now())->delete();

        if ($deleted > 0) {
            $this->info("Expired {$deleted} coupon reservation(s).");
        }

        return Command::SUCCESS;
    }
}
