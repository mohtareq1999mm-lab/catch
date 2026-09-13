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
 * Verifies that the category import progress pipeline broadcasts via the canonical
 * FileOperationEvent contract to the importing user's private channel.
 */
class CategoryImportProgressBroadcastTest extends TestCase
{
    use RefreshDatabase;

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

        $broadcaster = Broadcast::driver('pusher');
        config(['broadcasting.default' => 'pusher']);

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
        $event = new CategoryImportProgress(7, 42, ['progress' => 50.0, 'processed_rows' => 5, 'success_rows' => 4, 'failed_rows' => 1]);

        $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());
        $this->assertSame(['private-users.7'], $channels);
        $this->assertSame('category.import.progress', $event->broadcastAs());

        $payload = $event->broadcastWith();
        // Canonical payload must NOT expose legacy aliases
        $this->assertArrayNotHasKey('import_id', $payload);
        $this->assertArrayNotHasKey('type', $payload);
        $this->assertSame(42, $payload['id']);
        $this->assertSame(42, $payload['operation_id']);
        $this->assertSame('category-import', $payload['kind']);
        $this->assertSame('category-import', $payload['operation_type']);
        $this->assertSame('category.import.progress', $payload['event']);
        $this->assertSame(50.0, $payload['progress']);
        $this->assertSame(5, $payload['processed_rows']);
        $this->assertSame(4, $payload['success_rows']);
        $this->assertSame(1, $payload['failed_rows']);
        $this->assertArrayHasKey('progress_detail', $payload);
        $this->assertArrayHasKey('percentage', $payload);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertArrayHasKey('message', $payload);
    }

    public function test_write_explicit_progress_broadcasts_to_owner_channel(): void
    {
        $user = $this->createUser();
        $import = $this->createImport($user);

        $service = new CategoryImportService($import->id);
        $service->writeExplicitProgress(42.0);

        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'category.import.progress'
        );

        $this->assertSame(42.0, $broadcast['data']['progress']);
        $this->assertSame(42.0, $broadcast['data']['percentage']);
        $this->assertSame($import->id, $broadcast['data']['id']);
        $this->assertSame($import->id, $broadcast['data']['operation_id']);
        $this->assertSame('category-import', $broadcast['data']['kind']);
        $this->assertSame('category.import.progress', $broadcast['data']['event']);
        $this->assertArrayNotHasKey('import_id', $broadcast['data']);
        $this->assertArrayNotHasKey('type', $broadcast['data']);
        // Ensure no admin channel was used for canonical flow
        $adminBroadcasts = array_filter($this->pusher->broadcasts, fn ($b) => in_array('private-admin.notifications', $b['channels'], true));
        $this->assertEmpty($adminBroadcasts, 'Canonical flow must not broadcast to admin.notifications');

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

        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'category.import.progress'
        );

        $this->assertSame(100.0, $broadcast['data']['progress']);
        $this->assertSame($import->id, $broadcast['data']['id']);
        $this->assertSame('category-import', $broadcast['data']['kind']);
    }

    public function test_no_broadcast_when_import_has_no_creator(): void
    {
        $service = new CategoryImportService();
        $service->writeExplicitProgress(25.0);

        $this->assertNotNull($this->pusher, 'RecordingPusher (fake connection) was not installed.');
        $this->assertEmpty($this->pusher->broadcasts, 'Expected no broadcast for an import without a creator.');
    }
}
