<?php

namespace App\Actions;

use App\Enums\UserType;
use Illuminate\Notifications\Notification;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Wishlist;

/**
 * Centralizes wishlist-based fan-out for product-centric notifications
 * (Discount Changed, Price Drop, Back in Stock, Ending Soon).
 *
 * Reuses the existing meem-medium queue + database/broadcast architecture.
 * Only targets end users (UserType::USER) and chunks to avoid memory blowups.
 */
class NotifyWishlistUsersOfProduct
{
    public function handle(Product $product, Notification $notification): void
    {
        Wishlist::query()
            ->where('product_id', $product->id)
            ->with('user')
            ->chunkById(500, function ($wishlists) use ($notification) {
                foreach ($wishlists as $wishlist) {
                    $user = $wishlist->user;
                    if (!$user) {
                        continue;
                    }
                    if (($user->type ?? null) !== UserType::USER->value) {
                        continue;
                    }
                    $user->notify(clone $notification);
                }
            });
    }
}
