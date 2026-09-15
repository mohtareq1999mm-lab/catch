<?php

declare(strict_types=1);

namespace Tests\Feature\FileOperations;

use App\Events\FileOperationEvent;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Marvel\Database\Models\Import;
use Tests\Stubs\RecordingPusher;

/**
 * P0 — Unified canonical payload contract for Product/Category/Brand Import.
 *
 * Every import Pusher event must expose the same top-level keys and nested
 * progress_detail shape. Only VALUES may differ. Legacy aliases import_id/type
 * must not appear.
 */
class ImportPayloadCanonicalTest extends FileOperationBroadcastTestCase
{
    private const CANONICAL_KEYS = [
        'kind',
        'operation_type',
        'id',
        'operation_id',
        'event',
        'state',
        'status',
        'has_errors',
        'progress',
        'percentage',
        'progress_detail',
        'download_available',
        'timestamp',
        'message',
        'total_rows',
        'processed_rows',
        'success_rows',
        'failed_rows',
    ];

    private const PROGRESS_DETAIL_KEYS = ['percentage', 'processed', 'total'];

    private const NEGATIVE_KEYS = ['import_id', 'type'];

    /**
     * Helper subject that exposes the trait's protected methods.
     */
    private function subject(int $ownerId): object
    {
        return new class($ownerId) {
            use BroadcastsFileOperationProgress;

            public function __construct(int $ownerId)
            {
                $this->fileOperationOwnerId = $ownerId;
            }

            public function emitQueued(string $event, string $kind, int $opId, ?int $total = null, string $msg = ''): void
            {
                $this->broadcastFileOperationQueued($event, $kind, $opId, $total, $msg);
            }

            public function emitProgress(string $event, string $kind, int $opId, float $progress, int $processed, int $success, int $failed, ?int $total = null, string $status = 'processing'): void
            {
                $this->broadcastFileOperationProgress($event, $kind, $opId, $progress, $processed, $success, $failed, $total, $status);
            }

            public function emitTerminal(string $event, string $kind, int $opId, string $status, bool $hasErrors, array $extra = []): void
            {
                $this->broadcastFileOperationTerminal($event, $kind, $opId, $status, $hasErrors, $extra);
            }

            public function emitCancelling(string $event, string $kind, int $opId, string $msg = ''): void
            {
                $this->broadcastFileOperationCancelling($event, $kind, $opId, $msg);
            }

            public function markTerminalEmitted(bool $v): void
            {
                $this->fileOperationTerminalEmitted = $v;
            }
        };
    }

    private function assertCanonicalShape(array $payload): void
    {
        foreach (self::CANONICAL_KEYS as $key) {
            $this->assertArrayHasKey($key, $payload, "Missing canonical key [{$key}]");
        }
        foreach (self::NEGATIVE_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $payload, "Legacy key [{$key}] must not appear in canonical payload");
        }
        $this->assertIsArray($payload['progress_detail']);
        foreach (self::PROGRESS_DETAIL_KEYS as $k) {
            $this->assertArrayHasKey($k, $payload['progress_detail'], "Missing progress_detail.{$k}");
        }
        $this->assertSame($payload['progress'], $payload['percentage']);
        $this->assertSame($payload['progress'], $payload['progress_detail']['percentage']);
        $this->assertSame($payload['processed_rows'], $payload['progress_detail']['processed']);
        $this->assertSame($payload['total_rows'], $payload['progress_detail']['total']);
        $this->assertSame($payload['id'], $payload['operation_id']);
        $this->assertSame($payload['kind'], $payload['operation_type']);
        $this->assertIsString($payload['event']);
        $this->assertIsString($payload['state']);
        $this->assertSame($payload['state'], $payload['status']);
        $this->assertIsBool($payload['has_errors']);
        $this->assertIsBool($payload['download_available']);
        $this->assertIsString($payload['timestamp']);
        $this->assertIsString($payload['message']);
        // JSON serializable
        $this->assertNotFalse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function createImportFor(string $kind, int $userId, string $status = 'pending'): Import
    {
        $typeMap = [
            'product-import' => 'product',
            'category-import' => 'category',
            'brand-import' => 'brand',
        ];
        return Import::create([
            'type' => $typeMap[$kind] ?? $kind,
            'file_path' => 'imports/test.xlsx',
            'file_name' => 'test.xlsx',
            'status' => $status,
            'total_rows' => 467,
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'created_by' => $userId,
        ]);
    }

    public function test_queued_payload_is_canonical_for_all_import_types(): void
    {
        foreach (['product-import', 'category-import', 'brand-import'] as $kind) {
            $this->pusher->broadcasts = [];
            $user = $this->createOwnerUser();
            $import = $this->createImportFor($kind, $user->id);
            $event = match ($kind) {
                'product-import' => FileOperationEvent::PRODUCT_IMPORT_QUEUED,
                'category-import' => FileOperationEvent::CATEGORY_IMPORT_QUEUED,
                'brand-import' => FileOperationEvent::BRAND_IMPORT_QUEUED,
            };
            $subject = $this->subject($user->id);
            $subject->emitQueued($event, $kind, $import->id, 467, ucfirst(str_replace('-', ' ', $kind)) . ' queued.');
            $broadcast = $this->assertBroadcast($event, $user->id);
            $this->assertCanonicalShape($broadcast['data']);
            $this->assertSame('pending', $broadcast['data']['state']);
            $this->assertSame(0.0, $broadcast['data']['progress']);
        }
    }

    public function test_progress_payload_is_canonical_for_all_import_types(): void
    {
        foreach (['product-import', 'category-import', 'brand-import'] as $kind) {
            $this->pusher->broadcasts = [];
            $user = $this->createOwnerUser();
            $import = $this->createImportFor($kind, $user->id, 'processing');
            $event = match ($kind) {
                'product-import' => FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
                'category-import' => FileOperationEvent::CATEGORY_IMPORT_PROGRESS,
                'brand-import' => FileOperationEvent::BRAND_IMPORT_PROGRESS,
            };
            $subject = $this->subject($user->id);
            $subject->emitProgress($event, $kind, $import->id, 45.0, 450, 440, 10, 1000);
            $broadcast = $this->assertBroadcast($event, $user->id);
            $this->assertCanonicalShape($broadcast['data']);
            $this->assertSame(45.0, $broadcast['data']['progress']);
            $this->assertSame(450, $broadcast['data']['processed_rows']);
            $this->assertSame(440, $broadcast['data']['success_rows']);
            $this->assertSame(10, $broadcast['data']['failed_rows']);
            $this->assertSame(1000, $broadcast['data']['total_rows']);
        }
    }

    public function test_completed_payload_is_canonical_for_all_import_types(): void
    {
        foreach (['product-import', 'category-import', 'brand-import'] as $kind) {
            $this->pusher->broadcasts = [];
            $user = $this->createOwnerUser();
            $import = $this->createImportFor($kind, $user->id, 'processing');
            $event = match ($kind) {
                'product-import' => FileOperationEvent::PRODUCT_IMPORT_COMPLETED,
                'category-import' => FileOperationEvent::CATEGORY_IMPORT_COMPLETED,
                'brand-import' => FileOperationEvent::BRAND_IMPORT_COMPLETED,
            };
            $subject = $this->subject($user->id);
            $subject->emitTerminal($event, $kind, $import->id, 'completed', false, [
                'total_rows' => 467,
                'processed_rows' => 467,
                'success_rows' => 467,
                'failed_rows' => 0,
                'download_available' => false,
                'message' => 'Completed.',
            ]);
            // Reset terminal guard for next iteration (subject is new each loop so not needed)
            $broadcast = $this->assertBroadcast($event, $user->id);
            $this->assertCanonicalShape($broadcast['data']);
            $this->assertSame('completed', $broadcast['data']['state']);
            $this->assertSame(100.0, $broadcast['data']['progress']);
        }
    }

    public function test_completed_with_errors_payload_is_canonical(): void
    {
        foreach (['product-import', 'category-import', 'brand-import'] as $kind) {
            $this->pusher->broadcasts = [];
            $user = $this->createOwnerUser();
            $import = $this->createImportFor($kind, $user->id, 'processing');
            $event = match ($kind) {
                'product-import' => FileOperationEvent::PRODUCT_IMPORT_COMPLETED,
                'category-import' => FileOperationEvent::CATEGORY_IMPORT_COMPLETED,
                'brand-import' => FileOperationEvent::BRAND_IMPORT_COMPLETED,
            };
            $subject = $this->subject($user->id);
            $subject->emitTerminal($event, $kind, $import->id, 'completed_with_errors', true, [
                'total_rows' => 467,
                'processed_rows' => 467,
                'success_rows' => 399,
                'failed_rows' => 68,
                'download_available' => true,
            ]);
            $broadcast = $this->assertBroadcast($event, $user->id);
            $this->assertCanonicalShape($broadcast['data']);
            $this->assertSame('completed_with_errors', $broadcast['data']['state']);
            $this->assertTrue($broadcast['data']['has_errors']);
            $this->assertTrue($broadcast['data']['download_available']);
            $this->assertSame(68, $broadcast['data']['failed_rows']);
        }
    }

    public function test_failed_payload_is_canonical(): void
    {
        foreach (['product-import', 'category-import', 'brand-import'] as $kind) {
            $this->pusher->broadcasts = [];
            $user = $this->createOwnerUser();
            $import = $this->createImportFor($kind, $user->id, 'processing');
            $event = match ($kind) {
                'product-import' => FileOperationEvent::PRODUCT_IMPORT_FAILED,
                'category-import' => FileOperationEvent::CATEGORY_IMPORT_FAILED,
                'brand-import' => FileOperationEvent::BRAND_IMPORT_FAILED,
            };
            $subject = $this->subject($user->id);
            $subject->emitTerminal($event, $kind, $import->id, 'failed', true, [
                'total_rows' => 467,
                'processed_rows' => 200,
                'success_rows' => 150,
                'failed_rows' => 50,
            ]);
            $broadcast = $this->assertBroadcast($event, $user->id);
            $this->assertCanonicalShape($broadcast['data']);
            $this->assertSame('failed', $broadcast['data']['state']);
        }
    }

    public function test_cancelled_payload_is_canonical(): void
    {
        foreach (['product-import', 'category-import', 'brand-import'] as $kind) {
            $this->pusher->broadcasts = [];
            $user = $this->createOwnerUser();
            $import = $this->createImportFor($kind, $user->id, 'processing');
            $event = match ($kind) {
                'product-import' => FileOperationEvent::PRODUCT_IMPORT_CANCELLED,
                'category-import' => FileOperationEvent::CATEGORY_IMPORT_CANCELLED,
                'brand-import' => FileOperationEvent::BRAND_IMPORT_CANCELLED,
            };
            $subject = $this->subject($user->id);
            $subject->emitTerminal($event, $kind, $import->id, 'cancelled', false, [
                'total_rows' => 467,
                'processed_rows' => 100,
                'success_rows' => 90,
                'failed_rows' => 10,
            ]);
            $broadcast = $this->assertBroadcast($event, $user->id);
            $this->assertCanonicalShape($broadcast['data']);
            $this->assertSame('cancelled', $broadcast['data']['state']);
        }
    }

    public function test_cancelling_payload_is_canonical(): void
    {
        foreach (['product-import', 'category-import', 'brand-import'] as $kind) {
            $this->pusher->broadcasts = [];
            $user = $this->createOwnerUser();
            $import = $this->createImportFor($kind, $user->id, 'processing');
            $event = match ($kind) {
                'product-import' => FileOperationEvent::PRODUCT_IMPORT_CANCELLING,
                'category-import' => FileOperationEvent::CATEGORY_IMPORT_CANCELLING,
                'brand-import' => FileOperationEvent::BRAND_IMPORT_CANCELLING,
            };
            $subject = $this->subject($user->id);
            $subject->emitCancelling($event, $kind, $import->id, 'Cancelling.');
            $broadcast = $this->assertBroadcast($event, $user->id);
            $this->assertCanonicalShape($broadcast['data']);
            $this->assertSame('cancelling', $broadcast['data']['state']);
        }
    }

    public function test_structural_parity_across_imports_for_same_lifecycle(): void
    {
        $lifecycles = [
            'queued' => fn ($s, $uid, $iid, $kind, $event) => $s->emitQueued($event, $kind, $iid, 100, 'queued'),
            'progress' => fn ($s, $uid, $iid, $kind, $event) => $s->emitProgress($event, $kind, $iid, 45.0, 450, 440, 10, 1000),
            'completed' => fn ($s, $uid, $iid, $kind, $event) => $s->emitTerminal($event, $kind, $iid, 'completed', false, ['total_rows'=>467,'processed_rows'=>467,'success_rows'=>467,'failed_rows'=>0]),
        ];

        foreach ($lifecycles as $stage => $emitter) {
            $payloads = [];
            foreach (['product-import', 'category-import', 'brand-import'] as $kind) {
                $this->pusher->broadcasts = [];
                $user = $this->createOwnerUser();
                $import = $this->createImportFor($kind, $user->id, 'processing');
                $event = match ([$kind, $stage]) {
                    ['product-import','queued'] => FileOperationEvent::PRODUCT_IMPORT_QUEUED,
                    ['product-import','progress'] => FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
                    ['product-import','completed'] => FileOperationEvent::PRODUCT_IMPORT_COMPLETED,
                    ['category-import','queued'] => FileOperationEvent::CATEGORY_IMPORT_QUEUED,
                    ['category-import','progress'] => FileOperationEvent::CATEGORY_IMPORT_PROGRESS,
                    ['category-import','completed'] => FileOperationEvent::CATEGORY_IMPORT_COMPLETED,
                    ['brand-import','queued'] => FileOperationEvent::BRAND_IMPORT_QUEUED,
                    ['brand-import','progress'] => FileOperationEvent::BRAND_IMPORT_PROGRESS,
                    ['brand-import','completed'] => FileOperationEvent::BRAND_IMPORT_COMPLETED,
                };
                $subject = $this->subject($user->id);
                $emitter($subject, $user->id, $import->id, $kind, $event);
                $broadcast = $this->assertBroadcast($event, $user->id);
                $payloads[$kind] = array_keys($broadcast['data']);
                sort($payloads[$kind]);
                // Also verify no legacy keys
                $this->assertArrayNotHasKey('import_id', $broadcast['data']);
                $this->assertArrayNotHasKey('type', $broadcast['data']);
            }
            $this->assertSame($payloads['product-import'], $payloads['category-import'], "Key set mismatch product vs category for {$stage}");
            $this->assertSame($payloads['category-import'], $payloads['brand-import'], "Key set mismatch category vs brand for {$stage}");
            // progress_detail parity
            // Re-emit to check nested keys are identical sets
        }
    }

    public function test_negative_no_legacy_aliases_in_any_canonical_event(): void
    {
        $user = $this->createOwnerUser();
        $import = $this->createImportFor('category-import', $user->id, 'processing');
        $subject = $this->subject($user->id);

        $subject->emitProgress(FileOperationEvent::CATEGORY_IMPORT_PROGRESS, 'category-import', $import->id, 2.0, 0, 0, 0, null);
        $broadcast = $this->assertBroadcast(FileOperationEvent::CATEGORY_IMPORT_PROGRESS, $user->id);
        $this->assertArrayNotHasKey('import_id', $broadcast['data']);
        $this->assertArrayNotHasKey('type', $broadcast['data']);
        $this->assertArrayHasKey('operation_id', $broadcast['data']);
        $this->assertArrayHasKey('kind', $broadcast['data']);

        $this->pusher->broadcasts = [];
        $import2 = $this->createImportFor('product-import', $user->id, 'processing');
        $subject2 = $this->subject($user->id);
        $subject2->emitProgress(FileOperationEvent::PRODUCT_IMPORT_PROGRESS, 'product-import', $import2->id, 2.0, 0, 0, 0, null);
        $b2 = $this->assertBroadcast(FileOperationEvent::PRODUCT_IMPORT_PROGRESS, $user->id);
        $this->assertArrayNotHasKey('import_id', $b2['data']);
    }

    public function test_real_pusher_serialization_delivers_canonical_payload(): void
    {
        // Verify the actual payload that reaches Pusher (via RecordingPusher) is canonical
        $user = $this->createOwnerUser();
        $import = $this->createImportFor('brand-import', $user->id);
        $subject = $this->subject($user->id);
        $subject->emitProgress(FileOperationEvent::BRAND_IMPORT_PROGRESS, 'brand-import', $import->id, 45.0, 450, 440, 10, 1000);
        $broadcast = $this->assertBroadcast(FileOperationEvent::BRAND_IMPORT_PROGRESS, $user->id);
        $this->assertSame(['private-users.' . $user->id], $broadcast['channels']);
        $this->assertSame('brand.import.progress', $broadcast['event']);
        $this->assertCanonicalShape($broadcast['data']);

        // Verify JSON serialization preserves all keys through broadcastWith -> pusher JSON
        $json = json_encode($broadcast['data'], JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCanonicalShape($decoded);
        $this->assertArrayNotHasKey('import_id', $decoded);
    }
}
