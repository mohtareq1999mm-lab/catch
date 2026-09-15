<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Enums\FileOperationType;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class ExportCancelTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAllTestTables();
        if (!Schema::hasTable('imports')) {
            Schema::create('imports', function (Blueprint $table) {
                $table->id();
                $table->string('type')->default('product');
                $table->string('file_path')->nullable();
                $table->string('file_name')->nullable();
                $table->string('images_source')->default('none');
                $table->string('zip_file_path')->nullable();
                $table->string('status')->default('pending');
                $table->integer('total_rows')->default(0);
                $table->integer('processed_rows')->default(0);
                $table->integer('success_rows')->default(0);
                $table->integer('failed_rows')->default(0);
                $table->json('errors')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    private function makeAdmin(array $perms = []): User
    {
        $user = User::create([
            'name' => 'Admin '.uniqid(), 'email' => uniqid().'@test.com',
            'password' => bcrypt('Password123!'), 'email_verified_at' => now(),
            'is_active' => true, 'type' => 'admin',
        ]);
        foreach ($perms as $perm) {
            $p = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
            $user->givePermissionTo($p);
        }
        return $user;
    }

    public function test_product_export_cancel_pending(): void
    {
        $admin = $this->makeAdmin(['export-product']);
        $op = Import::create([
            'type' => FileOperationType::PRODUCT_EXPORT,
            'file_path' => '',
            'file_name' => '',
            'status' => 'pending',
            'total_rows' => 0,
            'created_by' => $admin->id,
        ]);
        Sanctum::actingAs($admin);
        $resp = $this->postJson("/api/v1/products/export/{$op->id}/cancel");
        $resp->assertStatus(200)->assertJsonPath('data.status', 'cancelled');
        $op->refresh();
        $this->assertEquals('cancelled', $op->status);
        // download should be 409
        $resp2 = $this->getJson("/api/v1/products/export/{$op->id}/download");
        $resp2->assertStatus(409);
        // duplicate cancel should be 409
        $resp3 = $this->postJson("/api/v1/products/export/{$op->id}/cancel");
        $resp3->assertStatus(409);
    }

    public function test_brand_export_cancel_pending(): void
    {
        $admin = $this->makeAdmin(['export-brand']);
        $op = Import::create([
            'type' => FileOperationType::BRAND_EXPORT,
            'file_path' => '',
            'file_name' => '',
            'status' => 'pending',
            'total_rows' => 0,
            'created_by' => $admin->id,
        ]);
        Sanctum::actingAs($admin);
        $resp = $this->postJson("/api/v1/brands/export/{$op->id}/cancel");
        $resp->assertStatus(200);
        $op->refresh();
        $this->assertEquals('cancelled', $op->status);
        $resp2 = $this->postJson("/api/v1/brands/export/{$op->id}/cancel");
        $resp2->assertStatus(409);
    }

    public function test_category_export_cancel_pending(): void
    {
        $admin = $this->makeAdmin(['export-category']);
        $op = Import::create([
            'type' => FileOperationType::CATEGORY_EXPORT,
            'file_path' => '',
            'file_name' => '',
            'status' => 'pending',
            'total_rows' => 0,
            'created_by' => $admin->id,
        ]);
        Sanctum::actingAs($admin);
        $resp = $this->postJson("/api/v1/categories/export/{$op->id}/cancel");
        $resp->assertStatus(200);
        $op->refresh();
        $this->assertEquals('cancelled', $op->status);
    }

    public function test_export_cancel_completed_returns_409(): void
    {
        $admin = $this->makeAdmin(['export-product']);
        $op = Import::create([
            'type' => FileOperationType::PRODUCT_EXPORT,
            'file_path' => 'products-export-1.xlsx',
            'file_name' => 'products-export-1.xlsx',
            'status' => 'completed',
            'total_rows' => 5,
            'created_by' => $admin->id,
        ]);
        Sanctum::actingAs($admin);
        $resp = $this->postJson("/api/v1/products/export/{$op->id}/cancel");
        $resp->assertStatus(409);
    }

    public function test_export_job_respects_cancel_signal(): void
    {
        $admin = $this->makeAdmin(['export-brand']);
        $op = Import::create([
            'type' => FileOperationType::BRAND_EXPORT,
            'file_path' => '',
            'file_name' => '',
            'status' => 'pending',
            'total_rows' => 0,
            'created_by' => $admin->id,
        ]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/brands/export/{$op->id}/cancel")->assertStatus(200);
        // Signal file should exist
        $signal = storage_path("app/imports/cancel_{$op->id}.json");
        $this->assertFileExists($signal);
        // Now run job - it should early return and not mark completed
        $job = new \Marvel\Jobs\ExportBrandsJob($op->id);
        $job->handle();
        $op->refresh();
        $this->assertEquals('cancelled', $op->status);
        $this->assertEmpty($op->file_path);
        // Cleanup signal
        @unlink($signal);
    }
}
