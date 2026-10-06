<?php

namespace App\Console\Commands;

use App\Enums\UserType;
use App\Notifications\UserAbandonedCartNotification;
use Illuminate\Console\Command;
use Marvel\Database\Models\Cart;

class NotifyAbandonedCarts extends Command
{
    protected $signature = 'cart:notify-abandoned';
    protected $description = 'Notify users about carts abandoned for at least 24 hours';

    public function handle(): int
    {
        $threshold = now()->subHours(24);

        $query = Cart::query()
            ->with('user')
            ->where('status', 'active')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $threshold)
            ->where('expires_at', '>', now())
            ->whereNull('reminder_sent_at');

        $notified = 0;

        $query->chunkById(500, function ($carts) use (&$notified) {
            foreach ($carts as $cart) {
                $user = $cart->user;
                if (!$user || ($user->type ?? null) !== UserType::USER->value) {
                    continue;
                }

                // F-05: atomic multi-server claim. Exactly one worker wins
                // the NULL -> timestamp flip; losers skip without notifying.
                // The claim is a single UPDATE (no open transaction across
                // the notification), and the notification dispatch carries
                // the queue's own durability/retries once claimed.
                $claimed = Cart::query()
                    ->whereKey($cart->id)
                    ->whereNull('reminder_sent_at')
                    ->update(['reminder_sent_at' => now()]);

                if (!$claimed) {
                    continue;
                }

                $user->notify(new UserAbandonedCartNotification($cart->refresh()));

                $notified++;
            }
        });

        $this->info("Notified {$notified} user(s) about abandoned carts.");

        return self::SUCCESS;
    }
}
