<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Channel authorization tests for the channels that real notifications are
 * broadcast to.
 *
 * VERIFIED PRODUCTION GAP: user notifications are broadcast to
 * PrivateChannel('users.{id}') (from User::receivesBroadcastNotificationsOn)
 * which becomes the private 'users.{id}' channel on the wire. No
 * Broadcast::channel('users.{id}', ...) registration exists anywhere, so the
 * broadcaster's verifyUserCanAccessChannel() cannot match it and ALWAYS
 * denies with AccessDeniedHttpException (HTTP 403). This means a frontend
 * socket can never subscribe to the private channel carrying user
 * notifications, even though the events are successfully pushed to Pusher.
 *
 * The tests below are written to the REQUIRED contract and therefore expose
 * the defect by failing (403 instead of 200).
 */
class NotificationAuthorizationTest extends NotificationE2ETestCase
{
    public function test_user_can_authorize_their_own_notification_channel(): void
    {
        $user = $this->createUser('user');

        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-users.' . $user->id,
            'socket_id' => '123.456',
        ]);
        $request->setUserResolver(fn ($guard = null) => $user);

        $broadcaster = Broadcast::driver();

        // REQUIRED: subscribing to the user's own notification channel must be
        // allowed. Today this throws 403 because 'users.{id}' is unregistered.
        $response = $broadcaster->auth($request);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('auth', $response);
    }

    public function test_user_cannot_authorize_another_users_notification_channel(): void
    {
        $owner = $this->createUser('user');
        $other = $this->createUser('user');

        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-users.' . $owner->id,
            'socket_id' => '123.456',
        ]);
        $request->setUserResolver(fn ($guard = null) => $other);

        $this->assertThrows(
            fn () => Broadcast::driver()->auth($request),
            AccessDeniedHttpException::class
        );
    }

    public function test_admin_can_authorize_admin_notifications_channel(): void
    {
        $admin = $this->createUser('admin');

        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-admin.notifications',
            'socket_id' => '123.456',
        ]);
        $request->setUserResolver(fn ($guard = null) => $admin);

        $response = Broadcast::driver()->auth($request);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('auth', $response);
    }

    public function test_regular_user_cannot_authorize_admin_notifications_channel(): void
    {
        $user = $this->createUser('user');

        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-admin.notifications',
            'socket_id' => '123.456',
        ]);
        $request->setUserResolver(fn ($guard = null) => $user);

        $this->assertThrows(
            fn () => Broadcast::driver()->auth($request),
            AccessDeniedHttpException::class
        );
    }

    public function test_user_can_authorize_own_order_created_channel(): void
    {
        $user = $this->createUser('user');

        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-order.created.' . $user->id,
            'socket_id' => '123.456',
        ]);
        $request->setUserResolver(fn ($guard = null) => $user);

        $response = Broadcast::driver()->auth($request);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('auth', $response);
    }

    public function test_user_cannot_authorize_other_users_order_created_channel(): void
    {
        $owner = $this->createUser('user');
        $other = $this->createUser('user');

        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-order.created.' . $owner->id,
            'socket_id' => '123.456',
        ]);
        $request->setUserResolver(fn ($guard = null) => $other);

        $this->assertThrows(
            fn () => Broadcast::driver()->auth($request),
            AccessDeniedHttpException::class
        );
    }

    /**
     * HTTP-level proof that the real /broadcasting/auth endpoint rejects the
     * private channel carrying user notifications (the users.{id} gap).
     */
    public function test_http_broadcasting_auth_rejects_user_notification_channel(): void
    {
        $user = $this->createUser('user');
        Sanctum::actingAs($user);

        $response = $this->withoutMiddleware(
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class
        )->postJson('/broadcasting/auth', [
            'channel_name' => 'private-users.' . $user->id,
            'socket_id' => '123.456',
        ]);

        // Today: 403 because no 'users.{id}' channel is registered.
        // Required: 200 with an auth signature.
        $response->assertStatus(200);
        $response->assertJsonStructure(['auth']);
    }

    public function test_http_broadcasting_auth_allows_admin_notifications_channel(): void
    {
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);

        $response = $this->withoutMiddleware(
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class
        )->postJson('/broadcasting/auth', [
            'channel_name' => 'private-admin.notifications',
            'socket_id' => '123.456',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['auth']);
    }
}
