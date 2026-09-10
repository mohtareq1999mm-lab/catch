<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

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
 * REAL Pusher connection check for the category import progress pipeline.
 *
 * The REAL CategoryImportService is executed so the REAL broadcast pipeline
 * (service -> CategoryImportProgress event -> BroadcastingManager ->
 * PusherBroadcaster) runs untouched. RecordingPusher captures the exact
 * channels/event/payload the broadcaster produces, then those exact values
 * are re-sent to the REAL api-*.pusher.com broker with the .env credentials.
 *
 * If Pusher is unreachable the test is skipped (honesty-gated), so the suite
 * stays green offline and never claims a connection that didn't happen.
 */
class CategoryImportProgressRealPusherTest extends TestCase
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

    private function realPusher(): \Pusher\Pusher
    {
        $config = config('broadcasting.connections.pusher');

        return new \Pusher\Pusher(
            $config['key'],
            $config['secret'],
            $config['app_id'],
            $config['options']
        );
    }

    private function assertPusherReachable(\Pusher\Pusher $pusher): void
    {
        try {
            $pusher->get_channels();
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'Pusher unreachable from this environment: ' . $e->getMessage()
            );
        }
    }

    private function createAdmin(): User
    {
        return User::create([
            'name' => 'Import Admin',
            'email' => 'import-admin-' . uniqid() . '@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin',
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

    private function lastRecordedBroadcast(): array
    {
        $this->assertNotNull($this->pusher, 'RecordingPusher (fake connection) was not installed.');
        $this->assertNotEmpty($this->pusher->broadcasts, 'No broadcast was recorded from the real pipeline.');

        return $this->pusher->broadcasts[array_key_last($this->pusher->broadcasts)];
    }

    public function test_real_pusher_receives_explicit_progress_object(): void
    {
        $user = $this->createAdmin();
        $import = $this->createImport($user);

        $service = new CategoryImportService($import->id);
        $service->writeExplicitProgress(42.0);

        $broadcast = $this->lastRecordedBroadcast();
        $this->assertSame(
            ['private-admin.notifications', 'private-users.' . $user->id],
            $broadcast['channels']
        );
        $this->assertSame('category.import.progress', $broadcast['event']);

        $pusher = $this->realPusher();
        $this->assertPusherReachable($pusher);

        $result = $pusher->trigger(
            $broadcast['channels'],
            $broadcast['event'],
            $broadcast['data']
        );

        $this->assertIsObject($result, 'Pusher rejected the category import progress payload.');
    }

    public function test_real_pusher_receives_terminal_100_percent_object(): void
    {
        $user = $this->createAdmin();
        $import = $this->createImport($user);

        $service = new CategoryImportService($import->id);
        $service->finalizeProgress();

        $broadcast = $this->lastRecordedBroadcast();
        $this->assertSame(100.0, $broadcast['data']['progress']);
        $this->assertSame('category.import.progress', $broadcast['event']);

        $pusher = $this->realPusher();
        $this->assertPusherReachable($pusher);

        $result = $pusher->trigger(
            $broadcast['channels'],
            $broadcast['event'],
            $broadcast['data']
        );

        $this->assertIsObject($result, 'Pusher rejected the category import progress payload.');
    }
}