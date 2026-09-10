<?php

namespace App\Console\Commands;

use App\Actions\NotifyWishlistUsersOfProduct;
use App\Notifications\UserFlashSaleEndingSoonNotification;
use Illuminate\Console\Command;
use Marvel\Database\Models\FlashSale;

class NotifyFlashSalesEndingSoon extends Command
{
    protected $signature = 'flash-sales:notify-ending-soon';
    protected $description = 'Notify wishlist users about flash sales ending within 24 hours';

    public function handle(): int
    {
        $notifyAction = new NotifyWishlistUsersOfProduct();

        $query = FlashSale::query()
            ->where('status', true)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', today()->toDateString())
            ->whereDate('end_date', '<=', now()->addDay()->toDateString())
            ->whereNull('ending_soon_notified_at');

        $notifiedFlashSales = 0;

        $query->chunkById(500, function ($flashSales) use ($notifyAction, &$notifiedFlashSales) {
            foreach ($flashSales as $flashSale) {
                $flashSale->products()
                    ->with('wishlists')
                    ->chunkById(500, function ($products) use ($notifyAction, $flashSale) {
                        foreach ($products as $product) {
                            $notifyAction->handle($product, new UserFlashSaleEndingSoonNotification($flashSale));
                        }
                    });

                $flashSale->ending_soon_notified_at = now();
                $flashSale->save();

                $notifiedFlashSales++;
            }
        });

        $this->info("Processed {$notifiedFlashSales} ending-soon flash sale(s).");

        return self::SUCCESS;
    }
}
