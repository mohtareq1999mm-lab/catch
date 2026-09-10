<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use App\Events\CategoryImportProgress;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Services\Import\CategoryImportService;
use Tests\Stubs\RecordingPusher;
use Tests\TestCase;

/**
 * Verifies that the category import progress pipeline broadcasts real-time
 * progress events to the importing user's private channel. The real
 * PusherBroadcaster stays in place while its HTTP client is swapped for the
 * RecordingPusher fake so the full app-to-Pusher contract (private channel,
 * event name, payload) is exercised without performing network calls.
 *
 * Run with:  php artisan test --filter=CategoryImportProgressBroadcastTest
 */
class CategoryImportProgressBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected ?RecordingPusher $pusher = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Signal files are shared filesystem artifacts; clear leftovers so
        // stale cancel/progress signals never leak between test suites.
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

        // Force the import service to broadcast even though APP_ENV=testing.
        config(['app.env' => 'local']);
    }

    protected function tearDown(): void
    {
        config(['app.env' => 'testing']);

        parent::tearDown();
    }

    private function createUser(): User
    {
        return User::create([
            'name' => 'Import User',
            'email' => 'import-' . uniqid() . '@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user',
        ]);
    }

    private function createImport(User $user): Import
    {
        return Import::create([
            'type' => 'category',
            'file_path' => 'imports/test.xlsx',
            'file_name' => 'test.xlsx',
            'status' => 'processing',
            'total_rows' => 10,
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'created_by' => $user->id,
        ]);
    }

    private function assertBroadcastTo(string $channel, string $event): array
    {
        $this->assertNotNull($this->pusher, 'RecordingPusher (fake connection) was not installed.');

        $matches = array_values(array_filter(
            $this->pusher->broadcasts,
            fn (array $broadcast) => in_array($channel, $broadcast['channels'], true)
                && $broadcast['event'] === $event
        ));

        $this->assertNotEmpty($matches, "No broadcast to {$channel} with event {$event} was recorded.");

        return $matches[0];
    }

    public function test_event_contract_targets_private_user_channel(): void
    {
        $event = new CategoryImportProgress(7, 42, ['progress' => 50.0, 'processed_rows' => 5]);

        $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());
        $this->assertSame(['private-admin.notifications', 'private-users.7'], $channels);
        $this->assertSame('category.import.progress', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame(50.0, $payload['progress']);
        $this->assertSame(5, $payload['processed_rows']);
        $this->assertSame(42, $payload['import_id']);
        $this->assertSame('category', $payload['type']);
    }

    public function test_write_explicit_progress_broadcasts_to_owner_channel(): void
    {
        $user = $this->createUser();
        $import = $this->createImport($user);

        $service = new CategoryImportService($import->id);
        $service->writeExplicitProgress(42.0);

        $adminBroadcast = $this->assertBroadcastTo(
            'private-admin.notifications',
            'category.import.progress'
        );

        $this->assertSame(42.0, $adminBroadcast['data']['progress']);
        $this->assertSame($import->id, $adminBroadcast['data']['import_id']);
        $this->assertSame('category', $adminBroadcast['data']['type']);
        $this->assertSame(0, $adminBroadcast['data']['processed_rows']);

        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'category.import.progress'
        );

        $this->assertSame(42.0, $broadcast['data']['progress']);
        $this->assertSame($import->id, $broadcast['data']['import_id']);
        $this->assertSame('category', $broadcast['data']['type']);
        $this->assertSame(0, $broadcast['data']['processed_rows']);

        $decoded = json_decode(json_encode($broadcast['data']), true);
        $this->assertIsArray($decoded, 'Broadcast data is not JSON serializable for Pusher.');
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function test_finalize_progress_broadcasts_terminal_100_percent(): void
    {
        $user = $this->createUser();
        $import = $this->createImport($user);

        $service = new CategoryImportService($import->id);
        $service->finalizeProgress();

        $adminBroadcast = $this->assertBroadcastTo(
            'private-admin.notifications',
            'category.import.progress'
        );

        $this->assertSame(100.0, $adminBroadcast['data']['progress']);
        $this->assertSame($import->id, $adminBroadcast['data']['import_id']);

        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'category.import.progress'
        );

        $this->assertSame(100.0, $broadcast['data']['progress']);
        $this->assertSame($import->id, $broadcast['data']['import_id']);
    }

    public function test_no_broadcast_when_import_has_no_creator(): void
    {
        $service = new CategoryImportService();
        $service->writeExplicitProgress(25.0);

        $this->assertNotNull($this->pusher, 'RecordingPusher (fake connection) was not installed.');
        $this->assertEmpty($this->pusher->broadcasts, 'Expected no broadcast for an import without a creator.');
    }
}