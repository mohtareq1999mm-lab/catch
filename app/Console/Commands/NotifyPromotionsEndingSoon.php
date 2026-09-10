<?php

namespace App\Console\Commands;

use App\Actions\NotifyWishlistUsersOfProduct;
use App\Notifications\UserPromotionEndingSoonNotification;
use Illuminate\Console\Command;
use Marvel\Database\Models\Promotion;

class NotifyPromotionsEndingSoon extends Command
{
    protected $signature = 'promotions:notify-ending-soon';
    protected $description = 'Notify wishlist users about promotions ending within 24 hours';

    public function handle(): int
    {
        $notifyAction = new NotifyWishlistUsersOfProduct();

        $query = Promotion::query()
            ->where('status', true)
            ->whereNotNull('end_at')
            ->whereDate('end_at', '>=', today()->toDateString())
            ->whereDate('end_at', '<=', now()->addDay()->toDateString())
            ->whereNull('ending_soon_notified_at');

        $notifiedPromotions = 0;

        $query->chunkById(500, function ($promotions) use ($notifyAction, &$notifiedPromotions) {
            foreach ($promotions as $promotion) {
                if ($promotion->appliesToAllProducts()) {
                    $promotion->ending_soon_notified_at = now();
                    $promotion->save();
                    continue;
                }

                $promotion->products()
                    ->with('wishlists')
                    ->chunkById(500, function ($products) use ($notifyAction, $promotion) {
                        foreach ($products as $product) {
                            $notifyAction->handle($product, new UserPromotionEndingSoonNotification($promotion));
                        }
                    });

                $promotion->ending_soon_notified_at = now();
                $promotion->save();

                $notifiedPromotions++;
            }
        });

        $this->info("Processed {$notifiedPromotions} ending-soon promotion(s).");

        return self::SUCCESS;
    }
}
