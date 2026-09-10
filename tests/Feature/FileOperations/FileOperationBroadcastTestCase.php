<?php

declare(strict_types=1);

namespace Tests\Feature\FileOperations;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Tests\Stubs\RecordingPusher;
use Tests\TestCase;

/**
 * Shared harness for realtime file-operation broadcast tests.
 *
 * Mirrors the established CategoryImportProgressBroadcastTest approach:
 * the real PusherBroadcaster stays in place while its HTTP client is
 * swapped for RecordingPusher so the full app-to-Pusher contract is
 * exercised without network calls. The testing-environment gate is lifted
 * for the duration of each test (config(['app.env' => 'local'])).
 */
abstract class FileOperationBroadcastTestCase extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected ?RecordingPusher $pusher = null;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = storage_path('app/imports');
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                @unlink($file);
            }
        }

        $broadcaster = Broadcast::driver();

        if ($broadcaster instanceof PusherBroadcaster) {
            $this->pusher = new RecordingPusher();
            $broadcaster->setPusher($this->pusher);
        }

        config(['app.env' => 'local']);
    }

    protected function tearDown(): void
    {
        config(['app.env' => 'testing']);

        parent::tearDown();
    }

    /**
     * All recorded broadcasts for one event name on one user's channel.
     */
    protected function broadcastsTo(string $event, int $userId): array
    {
        $this->assertNotNull($this->pusher, 'RecordingPusher (fake connection) was not installed.');

        return array_values(array_filter(
            $this->pusher->broadcasts,
            fn (array $broadcast) => in_array('private-users.' . $userId, $broadcast['channels'], true)
                && $broadcast['event'] === $event
        ));
    }

    protected function assertBroadcast(string $event, int $userId): array
    {
        $matches = array_values(array_filter(
            $this->pusher->broadcasts ?? [],
            fn (array $broadcast) => in_array('private-users.' . $userId, $broadcast['channels'], true)
                && $broadcast['event'] === $event
        ));

        $this->assertNotEmpty($matches, "No broadcast to private-users.{$userId} with event {$event} was recorded.");

        return $matches[0];
    }

    protected function assertNoBroadcastTo(int $userId): void
    {
        $this->assertNotNull($this->pusher, 'RecordingPusher (fake connection) was not installed.');

        foreach ($this->pusher->broadcasts as $broadcast) {
            $this->assertNotContains(
                'private-users.' . $userId,
                $broadcast['channels'],
                "Unexpected broadcast to private-users.{$userId}: {$broadcast['event']}"
            );
        }
    }

    protected function createOwnerUser(): \Marvel\Database\Models\User
    {
        return \Marvel\Database\Models\User::create([
            'name' => 'File Op User',
            'email' => 'fileop-' . uniqid() . '@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user',
        ]);
    }

    protected function createOperation(
        \Marvel\Database\Models\User $user,
        string $type = 'product',
        string $status = 'processing',
        int $totalRows = 0,
    ): \Marvel\Database\Models\Import {
        return \Marvel\Database\Models\Import::create([
            'type' => $type,
            'file_path' => 'imports/test.xlsx',
            'file_name' => 'test.xlsx',
            'status' => $status,
            'total_rows' => $totalRows,
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Assert the payload only contains safe whitelisted keys.
     */
    protected function assertPayloadIsSafe(array $payload): void
    {
        $allowed = [
            'kind', 'id', 'status', 'progress', 'processed_rows',
            'success_rows', 'failed_rows', 'total_rows', 'has_errors',
            'type', 'import_id',
        ];

        foreach (array_keys($payload) as $key) {
            $this->assertContains($key, $allowed, "Unsafe payload key [{$key}] detected.");
        }

        foreach ($payload as $value) {
            $this->assertNotFalse(
                json_encode($value, JSON_THROW_ON_ERROR),
                'Payload value is not JSON serializable.'
            );
        }
    }
}
