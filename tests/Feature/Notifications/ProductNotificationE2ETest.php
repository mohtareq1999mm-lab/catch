<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Notifications\UserProductBackInStockNotification;
use App\Notifications\UserProductDiscountChangedNotification;
use App\Notifications\UserProductPriceDropNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;

/**
 * Real pipeline: ProductObserver (updated) -> ProductPriceDrop /
 * ProductBackInStock / ProductDiscountChanged -> real listeners ->
 * NotifyWishlistUsersOfProduct fan-out -> real Notification (database +
 * broadcast) -> notifications table -> PusherBroadcaster -> RecordingPusher.
 */
class ProductNotificationE2ETest extends NotificationE2ETestCase
{
    public function test_price_drop_notifies_wishlist_user_in_db_and_broadcast(): void
    {
        $user = $this->createUser('user');
        $product = $this->createProduct(['price' => 100.00]);
        $this->createWishlist($user, $product);

        $product = \Marvel\Database\Models\Product::find($product->id);
        $product->price = 80.00;
        $product->save();

        $this->assertDatabaseNotification(
            $user,
            'price.drop',
            function ($n) use ($product) {
                $this->assertEquals('price.drop', $n->type);
                $this->assertEquals($product->id, $n->data['resource_id']);
            }
        );
        $this->assertBroadcastTo('private-users.' . $user->id, 'price.drop');
    }

    public function test_price_increase_does_not_notify(): void
    {
        $user = $this->createUser('user');
        $product = $this->createProduct(['price' => 80.00]);
        $this->createWishlist($user, $product);

        $product = \Marvel\Database\Models\Product::find($product->id);
        $product->price = 100.00;
        $product->save();

        $this->assertNoDatabaseNotification($user, 'price.drop');
        $this->assertEmpty($this->recordedBroadcasts());
    }

    public function test_price_drop_skips_non_wishlist_user(): void
    {
        $user = $this->createUser('user');
        $product = $this->createProduct(['price' => 100.00]);

        $product = \Marvel\Database\Models\Product::find($product->id);
        $product->price = 80.00;
        $product->save();

        $this->assertNoDatabaseNotification($user, 'price.drop');
    }

    public function test_price_drop_excludes_admin_wishlist_member(): void
    {
        $admin = $this->createUser('admin');
        $product = $this->createProduct(['price' => 100.00]);
        $this->createWishlist($admin, $product);

        $product = \Marvel\Database\Models\Product::find($product->id);
        $product->price = 80.00;
        $product->save();

        $this->assertNoDatabaseNotification($admin, 'price.drop');
    }

    public function test_back_in_stock_notifies_wishlist_user_in_db_and_broadcast(): void
    {
        $user = $this->createUser('user');
        $product = $this->createProduct(['stock_quantity' => 0, 'reserved_quantity' => 0]);
        $this->createWishlist($user, $product);

        $product = \Marvel\Database\Models\Product::find($product->id);
        $product->stock_quantity = 5;
        $product->save();

        $this->assertDatabaseNotification(
            $user,
            'back.in.stock',
            function ($n) use ($product) {
                $this->assertEquals('back.in.stock', $n->type);
                $this->assertEquals($product->id, $n->data['resource_id']);
            }
        );
        $this->assertBroadcastTo('private-users.' . $user->id, 'back.in.stock');
    }

    public function test_stock_increase_when_never_out_of_stock_does_not_notify(): void
    {
        $user = $this->createUser('user');
        $product = $this->createProduct(['stock_quantity' => 5, 'reserved_quantity' => 0]);
        $this->createWishlist($user, $product);

        $product = \Marvel\Database\Models\Product::find($product->id);
        $product->stock_quantity = 8;
        $product->save();

        $this->assertNoDatabaseNotification($user, 'back.in.stock');
    }

    public function test_discount_changed_notifies_wishlist_user_in_db_and_broadcast(): void
    {
        $user = $this->createUser('user');
        $product = $this->createProduct([
            'price' => 100.00,
            'has_discount' => false,
            'discount_type' => null,
            'discount_amount' => null,
            'price_after_discount' => null,
        ]);
        $this->createWishlist($user, $product);

        $product = \Marvel\Database\Models\Product::find($product->id);
        $product->has_discount = true;
        $product->discount_type = 'fixed';
        $product->discount_amount = 20.00;
        $product->price_after_discount = 80.00;
        $product->save();

        $this->assertDatabaseNotification(
            $user,
            'discount.changed',
            function ($n) use ($product) {
                $this->assertEquals('discount.changed', $n->type);
                $this->assertEquals($product->id, $n->data['resource_id']);
            }
        );
        $this->assertBroadcastTo('private-users.' . $user->id, 'discount.changed');
    }
}
