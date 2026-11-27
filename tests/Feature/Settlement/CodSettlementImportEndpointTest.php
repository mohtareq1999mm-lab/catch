<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\SettlementMode;
use App\Models\Settlement\SettlementImportFile;
use App\Support\FinanceAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * #240: the manual statement import endpoint is finance-only HTTP intake.
 *
 * It stores private, hash-idempotent evidence and must never create, accept,
 * reconcile, or close a statement (Round 2 #121 boundary): shipping
 * administrators stay read-only and a duplicate file hash never creates a
 * second row.
 */
class CodSettlementImportEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1/admin/cod-settlements/statements/import';
    private const VALID_CSV = "order_id,expected,received\n101,10.00,9.50\n";

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('private');

        foreach (FinanceAccess::permissions() as $name) {
            SpatiePermission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $financeRole = Role::firstOrCreate(
            ['name' => FinanceAccess::ROLE, 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Finance', 'ar' => 'مالية']],
        );
        $financeRole->syncPermissions(FinanceAccess::permissions());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function financeUser(): User
    {
        $user = User::factory()->create(['type' => 'staff']);
        $user->assignRole(FinanceAccess::ROLE);

        return $user;
    }

    /** A staff user whose only settlement ability is read-only. */
    private function viewOnlyUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'shipping-admin', 'guard_name' => 'api']);
        $role->syncPermissions([Permission::COD_SETTLEMENT_VIEW]);

        $user = User::factory()->create(['type' => 'staff']);
        $user->assignRole('shipping-admin');

        return $user;
    }

    private function payload(array $overrides = [], string $name = 'statement.csv'): array
    {
        return array_merge([
            'file' => UploadedFile::fake()->createWithContent($name, self::VALID_CSV),
            'statement_key' => 'stmt-240-01',
            'provider_code' => 'carrier_a',
            'mode' => SettlementMode::SANDBOX->value,
            'account_code' => 'account-01',
        ], $overrides);
    }

    public function test_finance_user_imports_csv_to_private_storage_and_record_row_only(): void
    {
        $response = $this->actingAs($this->financeUser(), 'sanctum')
            ->postJson(self::PREFIX, $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('cod_settlement.imported'))
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.file.parsed_line_count', 1);

        $row = SettlementImportFile::query()->firstOrFail();

        $this->assertSame('private', config('settlement.imports.disk'));
        $this->assertSame('private', $row->disk);
        $this->assertStringStartsWith(
            (string) config('settlement.imports.directory', 'settlement-statements') . '/',
            $row->path
        );
        $this->assertTrue(Storage::disk('private')->exists($row->path));
        $this->assertSame(self::VALID_CSV, Storage::disk('private')->get($row->path));
        $this->assertSame(hash('sha256', self::VALID_CSV), $row->file_hash);
        $this->assertSame('csv-v1', $row->parser_version);
        $this->assertSame([], $row->malformed_rows);

        $this->assertSame(1, SettlementImportFile::query()->count());
        $response->assertJsonPath('data.file.id', $row->id);

        // #121: import is evidence intake only; no statement or line state.
        $this->assertSame(0, DB::table('cod_settlement_statements')->count());
        $this->assertSame(0, DB::table('cod_settlement_lines')->count());
    }

    public function test_reimporting_same_bytes_returns_existing_record_without_a_second_row(): void
    {
        $finance = $this->financeUser();

        $first = $this->actingAs($finance, 'sanctum')->postJson(self::PREFIX, $this->payload());
        $first->assertStatus(201)->assertJsonPath('data.duplicate', false);

        $second = $this->actingAs($finance, 'sanctum')->postJson(self::PREFIX, $this->payload());
        $second->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('cod_settlement.imported_duplicate'))
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.file.id', $first->json('data.file.id'));

        $this->assertSame(1, SettlementImportFile::query()->count());
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_same_bytes_under_new_statement_identity_is_distinct_evidence(): void
    {
        $finance = $this->financeUser();

        $first = $this->actingAs($finance, 'sanctum')
            ->postJson(self::PREFIX, $this->payload());
        $otherStatement = $this->actingAs($finance, 'sanctum')
            ->postJson(self::PREFIX, $this->payload(['statement_key' => 'stmt-240-02']));

        $first->assertStatus(201);
        $otherStatement->assertStatus(201)->assertJsonPath('data.duplicate', false);

        $this->assertSame(2, SettlementImportFile::query()->count());
    }

    public function test_invalid_extension_is_rejected_with_422_and_no_evidence(): void
    {
        $response = $this->actingAs($this->financeUser(), 'sanctum')
            ->postJson(self::PREFIX, $this->payload([], 'statement.txt'));

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(0, SettlementImportFile::query()->count());
        $this->assertCount(0, Storage::disk('private')->allFiles());
    }

    public function test_oversized_file_is_rejected_with_422(): void
    {
        $maxBytes = (int) config('settlement.imports.max_bytes', 2097152);
        $oversized = str_repeat('a', $maxBytes + 1);

        $response = $this->actingAs($this->financeUser(), 'sanctum')
            ->postJson(self::PREFIX, $this->payload(
                ['file' => UploadedFile::fake()->createWithContent('statement.csv', $oversized)]
            ));

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.file.0', __('cod_settlement.file_too_large', ['max' => $maxBytes]));

        $this->assertSame(0, SettlementImportFile::query()->count());
        $this->assertCount(0, Storage::disk('private')->allFiles());
    }

    public function test_import_is_finance_only_and_read_only_surfaces_stay_read_only(): void
    {
        // Unauthenticated requests stay unauthenticated.
        $this->postJson(self::PREFIX, $this->payload())->assertStatus(401);

        // A shipping administrator with only the read-only settlement
        // permission must not import (Round 2 #121: finance-owned intake).
        $this->actingAs($this->viewOnlyUser(), 'sanctum')
            ->postJson(self::PREFIX, $this->payload())
            ->assertStatus(403);

        // A plain administrator without the finance role has no finance
        // authority; broad seeding never grants settlement abilities (#191).
        $plainAdmin = User::factory()->create(['type' => 'staff']);
        $this->actingAs($plainAdmin, 'sanctum')
            ->postJson(self::PREFIX, $this->payload())
            ->assertStatus(403);

        $this->assertSame(0, SettlementImportFile::query()->count());
        $this->assertCount(0, Storage::disk('private')->allFiles());
    }
}
