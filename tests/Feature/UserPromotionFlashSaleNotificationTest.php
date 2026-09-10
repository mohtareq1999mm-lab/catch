<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\FlashSaleActivated;
use App\Events\PromotionActivated;
use App\Listeners\SendUserFlashSaleAvailableNotification;
use App\Listeners\SendUserPromotionAvailableNotification;
use App\Notifications\UserFlashSaleAvailableNotification;
use App\Notifications\UserPromotionAvailableNotification;
use App\Observers\FlashSaleObserver;
use App\Observers\PromotionObserver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\User;
use Tests\TestCase;

class UserPromotionFlashSaleNotificationTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('users')) {
            $this->createTables();
        }

        if (config('database.default') === 'sqlite') {
            \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = ON;');
        }

        $this->beginDatabaseTransaction();
    }

    private function createTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->boolean('is_active')->default(true);
            $table->string('type')->default('user');
            $table->string('phone_number')->unique();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    private function createRegularUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Regular User',
            'email' => 'user-' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user',
            'phone_number' => '01' . rand(100000000, 999999999),
        ], $attributes));
    }

    private function createAdminUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Admin User',
            'email' => 'admin-' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin',
            'phone_number' => '02' . rand(100000000, 999999999),
        ], $attributes));
    }

    private function makePromotion(array $attributes = []): Promotion
    {
        $promotion = new Promotion(array_merge([
            'name' => 'Summer Promo',
            'code' => 'PROMO1',
            'type_amount' => 'percentage',
            'discount' => 15,
            'status' => true,
        ], $attributes));

        $promotion->id = $attributes['id'] ?? 1;

        return $promotion;
    }

    private function makeFlashSale(array $attributes = []): FlashSale
    {
        $flashSale = new FlashSale(array_merge([
            'title' => 'Mega Flash Sale',
            'type' => 'percentage',
            'discount' => 20,
            'status' => true,
        ], $attributes));

        $flashSale->id = $attributes['id'] ?? 1;

        return $flashSale;
    }

    private function assertLocaleMap($notification): void
    {
        $data = $notification->toDatabase(new \stdClass());
        $this->assertIsArray($data['title']);
        $this->assertArrayHasKey('en', $data['title']);
        $this->assertArrayHasKey('ar', $data['title']);
        $this->assertIsArray($data['message']);
        $this->assertArrayHasKey('en', $data['message']);
        $this->assertArrayHasKey('ar', $data['message']);
    }

    // ==================== PROMOTION OBSERVER DISPATCH ====================

    public function test_promotion_created_active_dispatches_event(): void
    {
        Event::fake();
        Queue::fake();
        $promotion = $this->makePromotion(['status' => true]);

        (new PromotionObserver())->created($promotion);

        Event::assertDispatched(PromotionActivated::class, function ($e) use ($promotion) {
            return $e->promotion === $promotion;
        });
    }

    public function test_promotion_created_inactive_does_not_dispatch_event(): void
    {
        Event::fake();
        Queue::fake();
        $promotion = $this->makePromotion(['status' => false]);

        (new PromotionObserver())->created($promotion);

        Event::assertNotDispatched(PromotionActivated::class);
    }

    public function test_promotion_created_active_with_future_start_date_dispatches_immediately(): void
    {
        Event::fake();
        Queue::fake();
        $promotion = $this->makePromotion(['status' => true, 'start_at' => now()->addDays(5)]);

        (new PromotionObserver())->created($promotion);

        Event::assertDispatched(PromotionActivated::class);
    }

    public function test_promotion_activated_false_to_true_dispatches_event(): void
    {
        Event::fake();
        Queue::fake();
        $promotion = $this->makePromotion(['status' => false]);
        $promotion->syncOriginal();
        $promotion->status = true;

        (new PromotionObserver())->updated($promotion);

        Event::assertDispatched(PromotionActivated::class);
    }

    public function test_promotion_true_to_true_does_not_dispatch_event(): void
    {
        Event::fake();
        Queue::fake();
        $promotion = $this->makePromotion(['status' => true]);
        $promotion->syncOriginal();
        $promotion->status = true;

        (new PromotionObserver())->updated($promotion);

        Event::assertNotDispatched(PromotionActivated::class);
    }

    public function test_promotion_true_to_false_does_not_dispatch_event(): void
    {
        Event::fake();
        Queue::fake();
        $promotion = $this->makePromotion(['status' => true]);
        $promotion->syncOriginal();
        $promotion->status = false;

        (new PromotionObserver())->updated($promotion);

        Event::assertNotDispatched(PromotionActivated::class);
    }

    // ==================== FLASH SALE OBSERVER DISPATCH ====================

    public function test_flash_sale_created_active_dispatches_event(): void
    {
        Event::fake();
        Queue::fake();
        $flashSale = $this->makeFlashSale(['status' => true]);

        (new FlashSaleObserver())->created($flashSale);

        Event::assertDispatched(FlashSaleActivated::class, function ($e) use ($flashSale) {
            return $e->flashSale === $flashSale;
        });
    }

    public function test_flash_sale_created_inactive_does_not_dispatch_event(): void
    {
        Event::fake();
        Queue::fake();
        $flashSale = $this->makeFlashSale(['status' => false]);

        (new FlashSaleObserver())->created($flashSale);

        Event::assertNotDispatched(FlashSaleActivated::class);
    }

    public function test_flash_sale_created_active_with_future_start_date_dispatches_immediately(): void
    {
        Event::fake();
        Queue::fake();
        $flashSale = $this->makeFlashSale(['status' => true, 'start_date' => now()->addDays(5)]);

        (new FlashSaleObserver())->created($flashSale);

        Event::assertDispatched(FlashSaleActivated::class);
    }

    public function test_flash_sale_activated_false_to_true_dispatches_event(): void
    {
        Event::fake();
        Queue::fake();
        $flashSale = $this->makeFlashSale(['status' => false]);
        $flashSale->syncOriginal();
        $flashSale->status = true;

        (new FlashSaleObserver())->updated($flashSale);

        Event::assertDispatched(FlashSaleActivated::class);
    }

    public function test_flash_sale_true_to_true_does_not_dispatch_event(): void
    {
        Event::fake();
        Queue::fake();
        $flashSale = $this->makeFlashSale(['status' => true]);
        $flashSale->syncOriginal();
        $flashSale->status = true;

        (new FlashSaleObserver())->updated($flashSale);

        Event::assertNotDispatched(FlashSaleActivated::class);
    }

    public function test_flash_sale_true_to_false_does_not_dispatch_event(): void
    {
        Event::fake();
        Queue::fake();
        $flashSale = $this->makeFlashSale(['status' => true]);
        $flashSale->syncOriginal();
        $flashSale->status = false;

        (new FlashSaleObserver())->updated($flashSale);

        Event::assertNotDispatched(FlashSaleActivated::class);
    }

    // ==================== PROMOTION LISTENER + NOTIFICATION ====================

    public function test_promotion_listener_notifies_users_only(): void
    {
        Notification::fake();
        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $promotion = $this->makePromotion();

        (new SendUserPromotionAvailableNotification())->handle(new PromotionActivated($promotion));

        Notification::assertSentTo($user, UserPromotionAvailableNotification::class, function ($n) {
            $this->assertLocaleMap($n);
            $this->assertEquals('promotion.available', $n->broadcastType());
            return true;
        });
        Notification::assertNotSentTo($admin, UserPromotionAvailableNotification::class);
    }

    public function test_promotion_listener_fan_out_to_multiple_users(): void
    {
        Notification::fake();
        $users = [
            $this->createRegularUser(),
            $this->createRegularUser(),
            $this->createRegularUser(),
        ];
        $promotion = $this->makePromotion();

        (new SendUserPromotionAvailableNotification())->handle(new PromotionActivated($promotion));

        Notification::assertSentToTimes($users[0], UserPromotionAvailableNotification::class, 1);
        Notification::assertSentToTimes($users[1], UserPromotionAvailableNotification::class, 1);
        Notification::assertSentToTimes($users[2], UserPromotionAvailableNotification::class, 1);
    }

    public function test_promotion_notification_payload_and_channels(): void
    {
        $promotion = $this->makePromotion(['id' => 7, 'name' => 'Winter Promo', 'code' => 'WINTER', 'type_amount' => 'fixed_rate', 'discount' => 10]);
        $notification = new UserPromotionAvailableNotification($promotion);
        $notifiable = new \stdClass();

        $this->assertEquals(['database', 'broadcast'], $notification->via($notifiable));
        $this->assertEquals('meem-medium', $notification->queue);

        $data = $notification->toDatabase($notifiable);
        $this->assertEquals('promotion', $data['resource_type']);
        $this->assertEquals(7, $data['resource_id']);
        $this->assertEquals('/promotions/7', $data['action_url']);
        $this->assertEquals('tag', $data['icon']);
        $this->assertEquals('WINTER', $data['promotion_code']);
        $this->assertEquals('fixed_rate', $data['discount_type']);
        $this->assertEquals(10, $data['discount_value']);
        $this->assertInstanceOf(\Illuminate\Notifications\Messages\BroadcastMessage::class, $notification->toBroadcast($notifiable));
    }

    // ==================== FLASH SALE LISTENER + NOTIFICATION ====================

    public function test_flash_sale_listener_notifies_users_only(): void
    {
        Notification::fake();
        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $flashSale = $this->makeFlashSale();

        (new SendUserFlashSaleAvailableNotification())->handle(new FlashSaleActivated($flashSale));

        Notification::assertSentTo($user, UserFlashSaleAvailableNotification::class, function ($n) {
            $this->assertLocaleMap($n);
            $this->assertEquals('flash_sale.available', $n->broadcastType());
            return true;
        });
        Notification::assertNotSentTo($admin, UserFlashSaleAvailableNotification::class);
    }

    public function test_flash_sale_listener_fan_out_to_multiple_users(): void
    {
        Notification::fake();
        $users = [
            $this->createRegularUser(),
            $this->createRegularUser(),
            $this->createRegularUser(),
        ];
        $flashSale = $this->makeFlashSale();

        (new SendUserFlashSaleAvailableNotification())->handle(new FlashSaleActivated($flashSale));

        Notification::assertSentToTimes($users[0], UserFlashSaleAvailableNotification::class, 1);
        Notification::assertSentToTimes($users[1], UserFlashSaleAvailableNotification::class, 1);
        Notification::assertSentToTimes($users[2], UserFlashSaleAvailableNotification::class, 1);
    }

    public function test_flash_sale_notification_payload_and_channels(): void
    {
        $flashSale = $this->makeFlashSale(['id' => 9, 'title' => 'Summer Flash', 'type' => 'percentage', 'discount' => 25]);
        $notification = new UserFlashSaleAvailableNotification($flashSale);
        $notifiable = new \stdClass();

        $this->assertEquals(['database', 'broadcast'], $notification->via($notifiable));
        $this->assertEquals('meem-medium', $notification->queue);

        $data = $notification->toDatabase($notifiable);
        $this->assertEquals('flash_sale', $data['resource_type']);
        $this->assertEquals(9, $data['resource_id']);
        $this->assertEquals('/flash-sales/9', $data['action_url']);
        $this->assertEquals('bolt', $data['icon']);
        $this->assertEquals('percentage', $data['discount_type']);
        $this->assertEquals(25, $data['discount_value']);
        $this->assertInstanceOf(\Illuminate\Notifications\Messages\BroadcastMessage::class, $notification->toBroadcast($notifiable));
    }
}
