<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\FileOperationEvent;
use PHPUnit\Framework\TestCase;

/**
 * Contract test for the generic realtime file-operation event.
 * Pins the channel, the event name passthrough and the safe payload shape
 * that every producer (imports / exports / bulk delete) must deliver.
 */
class FileOperationEventContractTest extends TestCase
{
    public function test_broadcasts_only_to_the_owner_private_user_channel(): void
    {
        $event = new FileOperationEvent(7, FileOperationEvent::PRODUCT_IMPORT_PROGRESS, [
            'kind' => 'product-import',
            'id' => 42,
            'status' => 'processing',
        ]);

        $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

        $this->assertSame(['private-users.7'], $channels);
    }

    public function test_broadcast_as_returns_the_configured_event_name(): void
    {
        $names = [
            FileOperationEvent::PRODUCT_IMPORT_PROGRESS => 'product.import.progress',
            FileOperationEvent::BRAND_IMPORT_PROGRESS => 'brand.import.progress',
            FileOperationEvent::CATEGORY_IMPORT_PROGRESS => 'category.import.progress',
            FileOperationEvent::CATEGORY_EXPORT_COMPLETED => 'category.export.completed',
            FileOperationEvent::CATEGORY_EXPORT_FAILED => 'category.export.failed',
            FileOperationEvent::BRAND_EXPORT_COMPLETED => 'brand.export.completed',
            FileOperationEvent::BRAND_EXPORT_FAILED => 'brand.export.failed',
            FileOperationEvent::CATEGORY_BULK_DELETE_PROGRESS => 'category.bulk-delete.progress',
            FileOperationEvent::CATEGORY_BULK_DELETE_COMPLETED => 'category.bulk-delete.completed',
            FileOperationEvent::CATEGORY_BULK_DELETE_CANCELLED => 'category.bulk-delete.cancelled',
            FileOperationEvent::CATEGORY_BULK_DELETE_FAILED => 'category.bulk-delete.failed',
        ];

        foreach ($names as $constant => $expected) {
            $event = new FileOperationEvent(1, $constant);
            $this->assertSame($expected, $event->broadcastAs());
        }
    }

    public function test_payload_is_passed_through_untouched(): void
    {
        $payload = [
            'kind' => 'category-export',
            'id' => 58,
            'status' => 'completed',
            'has_errors' => false,
        ];

        $event = new FileOperationEvent(3, FileOperationEvent::CATEGORY_EXPORT_COMPLETED, $payload);

        $this->assertSame($payload, $event->broadcastWith());
        $this->assertJsonStringEqualsJsonString(
            json_encode($payload, JSON_THROW_ON_ERROR),
            json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR)
        );
    }

    public function test_event_names_use_the_project_naming_convention(): void
    {
        foreach ([
            FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
            FileOperationEvent::CATEGORY_IMPORT_PROGRESS,
            FileOperationEvent::BRAND_IMPORT_PROGRESS,
            FileOperationEvent::CATEGORY_EXPORT_COMPLETED,
            FileOperationEvent::CATEGORY_EXPORT_FAILED,
            FileOperationEvent::BRAND_EXPORT_COMPLETED,
            FileOperationEvent::BRAND_EXPORT_FAILED,
            FileOperationEvent::CATEGORY_BULK_DELETE_PROGRESS,
            FileOperationEvent::CATEGORY_BULK_DELETE_COMPLETED,
            FileOperationEvent::CATEGORY_BULK_DELETE_CANCELLED,
            FileOperationEvent::CATEGORY_BULK_DELETE_FAILED,
        ] as $name) {
            $this->assertMatchesRegularExpression(
                '/^[a-z-]+\.[a-z-]+(\.[a-z-]+)?$/',
                $name,
                "Event name {$name} must follow the dot-separated lowercase convention."
            );
        }
    }
}
