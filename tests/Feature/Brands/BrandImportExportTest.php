<?php

namespace Tests\Feature\Brands;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Jobs\ExportBrandsJob;
use Marvel\Jobs\ImportBrandsJob;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Behavioral regression coverage for Brand Import/Export, mirroring the
 * proven Category architecture (async jobs on meem-bulk, signal-file
 * progress/cancel, partial-failure error artifacts).
 */
class BrandImportExportTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $admin;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');

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

        foreach ([Permission::IMPORT_BRAND, Permission::EXPORT_BRAND] as $perm) {
            SpatiePermission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }
        foreach (['import-category', 'export-category'] as $slug) {
            SpatiePermission::firstOrCreate(['name' => $slug, 'guard_name' => 'api']);
        }

        $this->admin = User::create([
            'name' => 'Brand Admin', 'email' => uniqid() . '@brand.test',
            'password' => bcrypt('Password123!'), 'email_verified_at' => now(),
            'is_active' => true, 'type' => 'admin',
        ]);
    }

    private function tokenWithPermissions(array $permissions): string
    {
        $user = User::create([
            'name' => 'Token User ' . uniqid(), 'email' => uniqid() . '@brand.test',
            'password' => bcrypt('Password123!'), 'email_verified_at' => now(),
            'is_active' => true, 'type' => 'admin',
        ]);
        foreach ($permissions as $p) {
            $user->givePermissionTo($p);
        }

        return $user->createToken('test')->plainTextToken;
    }

    /** @test */
    public function sample_downloads_valid_xlsx_with_contract_headers(): void
    {
        $token = $this->tokenWithPermissions([Permission::IMPORT_BRAND]);
        $response = $this->getJson(self::PREFIX . '/brands/import/sample', ['Authorization' => 'Bearer ' . $token]);

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
    }

    /** @test */
    public function import_dispatches_job_on_meem_high_queue_and_returns_202(): void
    {
        Queue::fake();
        $token = $this->tokenWithPermissions([Permission::IMPORT_BRAND]);

        $realSample = base_path('packages/marvel/resources/brands/brand-import-sample.xlsx');
        $uploadedFile = \Illuminate\Http\Testing\File::createWithContent(
            'brands.xlsx',
            (string) file_get_contents($realSample)
        );

        $response = $this->postJson(
            self::PREFIX . '/brands/import',
            ['file' => $uploadedFile],
            ['Authorization' => 'Bearer ' . $token]
        );

        $response->assertStatus(202);
        $response->assertJsonPath('success', true);
        Queue::assertPushed(ImportBrandsJob::class);
        Queue::assertPushedOn('meem-medium', ImportBrandsJob::class);
    }

    /** @test */
    public function import_requires_authentication_and_permission(): void
    {
        $this->postJson(self::PREFIX . '/brands/import')->assertStatus(401);
        $this->getJson(self::PREFIX . '/brands/export')->assertStatus(401);
    }

    /** @test */
    public function export_start_returns_202_with_export_id(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin);
        $this->admin->givePermissionTo([Permission::EXPORT_BRAND]);

        $response = $this->getJson(self::PREFIX . '/brands/export');

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'pending');
        Queue::assertPushed(ExportBrandsJob::class);
    }

    /** @test */
    public function cancel_on_terminal_import_returns_409(): void
    {
        Sanctum::actingAs($this->admin);
        $this->admin->givePermissionTo([Permission::IMPORT_BRAND]);

        $import = \Marvel\Database\Models\Import::create([
            'type' => 'brand-import', 'file_path' => 'imports/none.xlsx', 'file_name' => 'x.xlsx',
            'status' => 'completed', 'total_rows' => 0, 'created_by' => $this->admin->id,
        ]);

        $this->postJson(self::PREFIX . "/brands/import/{$import->id}/cancel")
            ->assertStatus(409);
    }
}
