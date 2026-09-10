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

                $user->notify(new UserAbandonedCartNotification($cart));

                $cart->reminder_sent_at = now();
                $cart->save();

                $notified++;
            }
        });

        $this->info("Notified {$notified} user(s) about abandoned carts.");

        return self::SUCCESS;
    }
}
