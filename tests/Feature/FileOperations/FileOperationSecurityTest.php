<?php

declare(strict_types=1);

namespace Tests\Feature\FileOperations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Security contract for the realtime file-operation channel.
 *
 * - The owner can subscribe to private-users.{ownId}.
 * - Any other authenticated user is denied (IDOR protection).
 * - The removed /test-pusher debug route must stay unreachable
 *   (it previously leaked the Pusher key/cluster and triggered
 *   admin-channel broadcasts anonymously).
 */
class FileOperationSecurityTest extends FileOperationBroadcastTestCase
{
    private function authRequest(int $channelUserId, $asUser): Request
    {
        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-users.' . $channelUserId,
            'socket_id' => '123.456',
        ]);
        $request->setUserResolver(fn ($guard = null) => $asUser);

        return $request;
    }

    public function test_owner_can_authorize_own_file_operation_channel(): void
    {
        $user = $this->createOwnerUser();

        $response = Broadcast::driver()->auth($this->authRequest($user->id, $user));

        $this->assertIsArray($response);
        $this->assertArrayHasKey('auth', $response);
    }

    public function test_foreign_user_is_denied_file_operation_channel(): void
    {
        $owner = $this->createOwnerUser();
        $attacker = $this->createOwnerUser();

        $this->assertThrows(
            fn () => Broadcast::driver()->auth($this->authRequest($owner->id, $attacker)),
            AccessDeniedHttpException::class
        );
    }

    public function test_debug_pusher_route_is_removed(): void
    {
        $response = $this->get('/test-pusher');

        $this->assertContains(
            $response->getStatusCode(),
            [404, 405],
            '/test-pusher must not be reachable anonymously (Pusher key leak).'
        );
    }

    public function test_broadcasting_auth_requires_authentication(): void
    {
        $user = $this->createOwnerUser();

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-users.' . $user->id,
            'socket_id' => '123.456',
        ]);

        $this->assertSame(
            401,
            $response->getStatusCode(),
            'Unauthenticated clients must not obtain channel authorization.'
        );
    }
}
