<?php

namespace Tests\Feature;

use App\Audit\ActivityAuditService;
use App\Audit\ActivityRedactor;
use App\Audit\ActivitySnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogForensicTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('activity_log')) {
            Schema::create('activity_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('log_name')->nullable();
                $table->text('description');
                $table->nullableMorphs('subject', 'subject');
                $table->nullableMorphs('causer', 'causer');
                $table->string('event')->nullable();
                $table->json('properties')->nullable();
                $table->uuid('batch_uuid')->nullable();
                $table->timestamps();
                $table->index('log_name');
            });
        }

        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->nullable();
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->nullable();
                $table->string('product_type')->default('simple');
                $table->string('item_type')->nullable();
                $table->unsignedBigInteger('type_id')->nullable();
                $table->string('sku')->nullable();
                $table->integer('stock_quantity')->default(0);
                $table->integer('quantity')->default(0);
                $table->integer('reserved_quantity')->default(0);
                $table->integer('sold_quantity')->default(0);
                $table->boolean('in_stock')->default(true);
                $table->integer('status')->default(1);
                $table->string('height')->nullable();
                $table->string('width')->nullable();
                $table->string('length')->nullable();
                $table->string('weight')->nullable();
                $table->boolean('has_flash_sale')->default(false);
                $table->boolean('is_fast_shipping_available')->default(false);
                $table->boolean('has_discount')->default(false);
                $table->integer('pieces')->nullable();
                $table->string('discount_type')->nullable();
                $table->decimal('discount_amount', 10, 2)->nullable();
                $table->boolean('discount_status')->nullable();
                $table->dateTime('start_date')->nullable();
                $table->dateTime('end_date')->nullable();
                $table->decimal('price_after_discount', 10, 2)->nullable();
                $table->decimal('price_after_flash_sale', 10, 2)->nullable();
                $table->boolean('tax_enabled')->default(false);
                $table->decimal('tax_rate', 8, 2)->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('media')) {
            Schema::create('media', function (Blueprint $table) {
                $table->id();
                $table->morphs('model');
                $table->uuid('uuid')->nullable()->unique();
                $table->string('collection_name');
                $table->string('name');
                $table->string('file_name');
                $table->string('mime_type')->nullable();
                $table->string('disk');
                $table->string('conversions_disk')->nullable();
                $table->unsignedBigInteger('size');
                $table->json('manipulations');
                $table->json('custom_properties');
                $table->json('generated_conversions');
                $table->json('responsive_images');
                $table->unsignedInteger('order_column')->nullable()->index();
                $table->nullableTimestamps();
            });
        }

        DB::table('activity_log')->delete();
        DB::table('products')->delete();
    }

    public function test_snapshot_survives_deleted_subject(): void
    {
        ActivityAuditService::record(new ActivitySnapshot(
            logName: 'products',
            event: 'deleted',
            description: 'Product deleted',
            subjectType: Product::class,
            subjectId: 123,
            old: ['name' => 'Foo'],
        ));

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Product::class,
            'subject_id' => 123,
            'event' => 'deleted',
        ]);
    }

    public function test_record_model_does_not_require_persisted_subject(): void
    {
        $product = new Product();
        $product->id = 999;

        ActivityAuditService::recordModel(
            $product,
            'forceDeleted',
            'products',
            'Permanently deleted',
            old: ['sku' => 'PRD-001'],
        );

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Product::class,
            'subject_id' => 999,
            'event' => 'forceDeleted',
        ]);
    }

    public function test_record_subject_preserves_business_actor(): void
    {
        ActivityAuditService::recordSubject(
            Product::class,
            7,
            'deleted',
            'products',
            'Product deleted',
            causerId: 42,
            causerType: User::class,
        );

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Product::class,
            'subject_id' => 7,
            'event' => 'deleted',
            'causer_id' => 42,
            'causer_type' => User::class,
        ]);
    }

    public function test_redaction_removes_sensitive_keys_recursively(): void
    {
        $redacted = ActivityRedactor::redact([
            'name' => 'x',
            'password' => 'secret',
            'api_key' => 'k',
            'remember_token' => 't',
            'nested' => ['access_token' => 't', 'ok' => 'v', 'deep' => ['webhook_secret' => 's']],
        ]);

        $this->assertSame('[REDACTED]', $redacted['password']);
        $this->assertSame('[REDACTED]', $redacted['api_key']);
        $this->assertSame('[REDACTED]', $redacted['remember_token']);
        $this->assertSame('[REDACTED]', $redacted['nested']['access_token']);
        $this->assertSame('[REDACTED]', $redacted['nested']['deep']['webhook_secret']);
        $this->assertSame('v', $redacted['nested']['ok']);
        $this->assertSame('x', $redacted['name']);
    }

    public function test_redaction_applied_to_persisted_properties(): void
    {
        ActivityAuditService::recordModel(
            new Product(),
            'updated',
            'products',
            'Product updated',
            old: ['password' => 'hunter2', 'name' => 'Foo'],
            new: ['password' => 'hunter3', 'name' => 'Bar'],
        );

        $activity = Activity::latest('id')->first();
        $props = $activity->properties->toArray();

        $this->assertSame('[REDACTED]', $props['old']['password'] ?? null);
        $this->assertSame('[REDACTED]', $props['new']['password'] ?? null);
        $this->assertSame('Foo', $props['old']['name'] ?? null);
    }

    public function test_batch_record_with_import_id(): void
    {
        ActivityAuditService::recordBatch(
            'imports',
            'import_completed',
            'Product import completed',
            context: ['source' => 'import', 'import_id' => 456, 'batch_uuid' => 'batch-xyz'],
            properties: ['import_id' => 456, 'success_rows' => 10],
            batchUuid: 'batch-xyz',
            causerId: 5,
            causerType: User::class,
        );

        $activity = Activity::where('batch_uuid', 'batch-xyz')->first();
        $this->assertNotNull($activity);
        $this->assertSame('import_completed', $activity->event);
        $this->assertSame('batch-xyz', $activity->batch_uuid);

        $context = $activity->properties['context'] ?? [];
        $this->assertSame('import', $context['source']);
        $this->assertSame(456, $context['import_id']);
    }

    public function test_retention_prunes_old_and_preserves_recent(): void
    {
        $cutoff = now()->subDays(90);

        DB::table('activity_log')->insert([
            [
                'log_name' => 'products',
                'description' => 'Old entry',
                'event' => 'deleted',
                'subject_type' => Product::class,
                'subject_id' => 1,
                'properties' => json_encode([]),
                'created_at' => $cutoff->copy()->subDay(),
                'updated_at' => $cutoff->copy()->subDay(),
            ],
            [
                'log_name' => 'products',
                'description' => 'Recent entry',
                'event' => 'updated',
                'subject_type' => Product::class,
                'subject_id' => 2,
                'properties' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->artisan('activitylog:prune --days=90')->assertExitCode(0);

        $this->assertDatabaseMissing('activity_log', ['description' => 'Old entry']);
        $this->assertDatabaseHas('activity_log', ['description' => 'Recent entry']);
        $this->assertDatabaseHas('activity_log', ['event' => 'activity_pruned']);
    }

    public function test_retention_is_idempotent(): void
    {
        $cutoff = now()->subDays(90);

        DB::table('activity_log')->insert([
            'log_name' => 'products',
            'description' => 'Old entry',
            'event' => 'deleted',
            'subject_type' => Product::class,
            'subject_id' => 1,
            'properties' => json_encode([]),
            'created_at' => $cutoff->copy()->subDay(),
            'updated_at' => $cutoff->copy()->subDay(),
        ]);

        $this->artisan('activitylog:prune --days=90')->assertExitCode(0);
        $this->artisan('activitylog:prune --days=90')->assertExitCode(0);

        $this->assertDatabaseMissing('activity_log', ['description' => 'Old entry']);
    }

    public function test_product_soft_delete_creates_surviving_activity(): void
    {
        $product = Product::create([
            'name' => 'Forensic Product',
            'slug' => 'forensic-product',
            'price' => 99.50,
            'status' => 1,
            'in_stock' => true,
            'stock_quantity' => 10,
        ]);

        $productId = $product->id;
        $product->delete();

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Product::class,
            'subject_id' => $productId,
            'event' => 'deleted',
        ]);

        $activity = \Spatie\Activitylog\Models\Activity::where('subject_type', Product::class)
            ->where('subject_id', $productId)
            ->where('event', 'deleted')
            ->first();

        $this->assertNotNull($activity);
        $this->assertArrayHasKey('name', $activity->properties['old'] ?? []);

        // Subject is soft-deleted but the audit survives.
        $this->assertNull(Product::find($productId));
        $this->assertNotNull(Product::withTrashed()->find($productId));
    }

    public function test_product_mass_assignment_whitelist_excludes_accounting_fields(): void
    {
        $ref = new \ReflectionClass(\Marvel\Database\Repositories\ProductRepository::class);
        $fields = $ref->getConstant('WRITABLE_FIELDS');

        $this->assertIsArray($fields);
        $this->assertNotContains('sku', $fields);
        $this->assertNotContains('stock_quantity', $fields);
        $this->assertNotContains('reserved_quantity', $fields);
        $this->assertNotContains('sold_quantity', $fields);
        $this->assertContains('status', $fields);
        $this->assertContains('price', $fields);
    }

    public function test_product_force_delete_creates_surviving_activity(): void
    {
        $product = Product::create([
            'name' => 'Permanent Product',
            'slug' => 'permanent-product',
            'price' => 50.00,
            'status' => 1,
            'in_stock' => true,
            'stock_quantity' => 5,
        ]);

        $productId = $product->id;
        $product->forceDelete();

        // Row is gone, but the audit survives.
        $this->assertNull(Product::withTrashed()->find($productId));

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Product::class,
            'subject_id' => $productId,
            'event' => 'forceDeleted',
        ]);

        $activity = \Spatie\Activitylog\Models\Activity::where('subject_type', Product::class)
            ->where('subject_id', $productId)
            ->where('event', 'forceDeleted')
            ->first();

        $this->assertNotNull($activity);
        $this->assertArrayHasKey('name', $activity->properties['old'] ?? []);
        $this->assertStringContainsString('Permanent Product', (string) ($activity->properties['old']['name'] ?? ''));
    }
}
