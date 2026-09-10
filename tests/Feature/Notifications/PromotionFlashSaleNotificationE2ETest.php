<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\FlashSaleActivated;
use App\Events\PromotionActivated;
use App\Notifications\UserFlashSaleAvailableNotification;
use App\Notifications\UserFlashSaleEndingSoonNotification;
use App\Notifications\UserFlashSalePriceDropNotification;
use App\Notifications\UserPromotionAvailableNotification;
use App\Notifications\UserPromotionEndingSoonNotification;
use App\Notifications\UserPromotionPriceDropNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\DB;

/**
 * Real pipeline: PromotionActivated / FlashSaleActivated (as dispatched by
 * PromotionObserver / FlashSaleObserver) -> real listeners -> real
 * Notification (database + broadcast) -> notifications table ->
 * PusherBroadcaster -> RecordingPusher. Also covers the ending-soon console
 * commands that fan out through NotifyWishlistUsersOfProduct.
 */
class PromotionFlashSaleNotificationE2ETest extends NotificationE2ETestCase
{
    public function test_promotion_activated_notifies_all_users_and_wishlist_users_in_db_and_broadcast(): void
    {
        $wishlistUser = $this->createUser('user');
        $plainUser = $this->createUser('user');
        $admin = $this->createUser('admin');

        $product = $this->createProduct();
        $promotion = $this->createPromotion();
        $this->attachPromotionProduct($promotion, $product);
        $this->createWishlist($wishlistUser, $product);

        event(new PromotionActivated($promotion));

        // "available" fan-out reaches every end user.
        foreach ([$wishlistUser, $plainUser] as $user) {
            $this->assertDatabaseNotification(
                $user,
                'promotion.available',
                function ($n) use ($promotion) {
                    $this->assertEquals('promotion.available', $n->type);
                    $this->assertEquals($promotion->id, $n->data['resource_id']);
                }
            );
            $this->assertBroadcastTo('private-users.' . $user->id, 'promotion.available');
        }

        // "price drop" fan-out reaches only wishlist users.
        $this->assertDatabaseNotification($wishlistUser, 'promotion.price.drop');
        $this->assertNoDatabaseNotification($plainUser, 'promotion.price.drop');

        // Admins never receive user promotions.
        $this->assertNoDatabaseNotification($admin, 'promotion.available');
        $this->assertNoDatabaseNotification($admin, 'promotion.price.drop');
    }

    public function test_flash_sale_activated_notifies_all_users_and_wishlist_users_in_db_and_broadcast(): void
    {
        $wishlistUser = $this->createUser('user');
        $plainUser = $this->createUser('user');

        $product = $this->createProduct();
        $flashSale = $this->createFlashSale();
        $this->attachFlashSaleProduct($flashSale, $product);
        $this->createWishlist($wishlistUser, $product);

        event(new FlashSaleActivated($flashSale));

        foreach ([$wishlistUser, $plainUser] as $user) {
            $this->assertDatabaseNotification(
                $user,
                'flash_sale.available',
                function ($n) use ($flashSale) {
                    $this->assertEquals('flash_sale.available', $n->type);
                    $this->assertEquals($flashSale->id, $n->data['resource_id']);
                }
            );
            $this->assertBroadcastTo('private-users.' . $user->id, 'flash_sale.available');
        }

        $this->assertDatabaseNotification($wishlistUser, 'flash_sale.price.drop');
        $this->assertNoDatabaseNotification($plainUser, 'flash_sale.price.drop');
    }

    public function test_promotion_ending_soon_command_notifies_wishlist_users_once(): void
    {
        $user = $this->createUser('user');
        $product = $this->createProduct();
        $promotion = $this->createPromotion([
            'end_at' => now()->addDay()->toDateString(),
        ]);
        $this->attachPromotionProduct($promotion, $product);
        $this->createWishlist($user, $product);

        $this->artisan('promotions:notify-ending-soon')->assertExitCode(0);
        $this->artisan('promotions:notify-ending-soon')->assertExitCode(0);

        $this->assertDatabaseNotification(
            $user,
            'promotion.ending_soon',
            function ($n) use ($promotion) {
                $this->assertEquals('promotion.ending_soon', $n->type);
                $this->assertEquals($promotion->id, $n->data['resource_id']);
            }
        );
        $this->assertBroadcastTo('private-users.' . $user->id, 'promotion.ending_soon');

        $this->assertEquals(
            1,
            DB::table('notifications')
                ->where('notifiable_id', $user->id)
                ->where('type', 'promotion.ending_soon')
                ->count()
        );
    }

    public function test_flash_sale_ending_soon_command_notifies_wishlist_users_once(): void
    {
        $user = $this->createUser('user');
        $product = $this->createProduct();
        $flashSale = $this->createFlashSale([
            'end_date' => now()->addDay()->toDateString(),
        ]);
        $this->attachFlashSaleProduct($flashSale, $product);
        $this->createWishlist($user, $product);

        $this->artisan('flash-sales:notify-ending-soon')->assertExitCode(0);
        $this->artisan('flash-sales:notify-ending-soon')->assertExitCode(0);

        $this->assertDatabaseNotification(
            $user,
            'flash_sale.ending_soon',
            function ($n) use ($flashSale) {
                $this->assertEquals('flash_sale.ending_soon', $n->type);
                $this->assertEquals($flashSale->id, $n->data['resource_id']);
            }
        );
        $this->assertEquals(
            1,
            DB::table('notifications')
                ->where('notifiable_id', $user->id)
                ->where('type', 'flash_sale.ending_soon')
                ->count()
        );
    }
}
