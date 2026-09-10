<?php

namespace Tests\Feature\Invoice;

use App\Models\Invoice;
use App\Services\Invoice\InvoiceNumberService;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\InvoiceSnapshotService;
use App\Services\Invoice\InvoiceSnapshotValidator;
use App\Services\Invoice\InvoiceTimelineService;
use App\Services\Invoice\SnapshotIntegrityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class InvoiceDownloadPermissionTest extends TestCase
{
    use CreatesTestTables, DatabaseTransactions;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';
    private const DOWNLOAD_URL = self::PREFIX . '/invoices/%s/download';

    private InvoiceService $invoiceService;
    private Invoice $invoice;
    private User $owner;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAllTestTables();

        if (config('database.default') === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        $invoiceNumberService = new InvoiceNumberService();
        $snapshotService = new InvoiceSnapshotService();
        $snapshotValidator = new InvoiceSnapshotValidator();
        $integrityService = new SnapshotIntegrityService();

        $this->invoiceService = new InvoiceService(
            $snapshotService,
            $snapshotValidator,
            $integrityService,
            $invoiceNumberService,
            new InvoiceTimelineService(),
        );

        $this->owner = $this->createUser('owner@example.com');
        $this->order = $this->createOrder($this->owner);
        $this->invoice = $this->invoiceService->generateFromOrder($this->order);
    }

    private function createUser(string $email): User
    {
        return User::create([
            'name' => 'Test User ' . $email,
            'email' => $email,
            'password' => bcrypt('password'),
        ]);
    }

    private function createOrder(User $user): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'name' => 'Test Customer',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'status' => 'completed',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'price' => 100.00,
            'total_price' => 100.00,
            'shipping_price' => 10.00,
            'payment_method' => 'online',
            'payment_gateway' => 'myfatoorah',
            'fulfillment_type' => 'delivery',
            'shipping_method' => 'SCHEDULED',
            'address' => json_encode(['street' => 'Test St', 'city' => 'Cairo']),
        ]);
    }

    private function createPermission(string $name): Permission
    {
        return Permission::create(['name' => $name, 'guard_name' => self::GUARD]);
    }

    private function clearPermissionCache(): void
    {
        app('auth')->forgetGuards();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function attachRealPdf(Invoice $invoice): Invoice
    {
        $filename = str_replace('/', '-', $invoice->invoice_number) . '.pdf';
        Storage::disk('public')->makeDirectory('invoices');
        Storage::disk('public')->put('invoices/' . $filename, '%PDF-1.4 test-invoice-content');

        $invoice->update([
            'pdf_path' => $filename,
            'pdf_generated_at' => now(),
            'status' => 'ready',
        ]);

        return $invoice->refresh();
    }

    private function actingAsUser(User $user): void
    {
        $this->clearPermissionCache();
        Sanctum::actingAs($user, ['*']);
    }

    // ─── TC-DL-001: Unauthenticated → 401 ──────────────────────────────────

    public function test_unauthenticated_user_gets_401(): void
    {
        $invoice = $this->attachRealPdf($this->invoice);

        $response = $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid));

        $response->assertStatus(401);
    }

    // ─── TC-DL-002: Owner can download without any permission ──────────────

    public function test_owner_can_download_without_permission(): void
    {
        $invoice = $this->attachRealPdf($this->invoice);
        $this->actingAsUser($this->owner);

        $response = $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'attachment; filename="' . $invoice->pdf_path . '"',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    // ─── TC-DL-003: Non-owner WITH view-invoice-download → allowed ─────────

    public function test_non_owner_with_download_permission_can_download(): void
    {
        $invoice = $this->attachRealPdf($this->invoice);
        $this->createPermission(PermissionEnum::VIEW_INVOICE_DOWNLOAD);
        $operator = $this->createUser('operator@example.com');
        $operator->givePermissionTo(PermissionEnum::VIEW_INVOICE_DOWNLOAD);

        $this->actingAsUser($operator);
        $response = $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            $invoice->pdf_path,
            (string) $response->headers->get('Content-Disposition')
        );
    }

    // ─── TC-DL-004 (CRITICAL): Non-owner with view-invoice ONLY → denied ───

    public function test_non_owner_with_view_invoice_permission_only_is_denied(): void
    {
        $invoice = $this->attachRealPdf($this->invoice);
        $this->createPermission(PermissionEnum::VIEW_INVOICE);
        $viewer = $this->createUser('viewer@example.com');
        $viewer->givePermissionTo(PermissionEnum::VIEW_INVOICE);

        $this->actingAsUser($viewer);
        $response = $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid));

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    // ─── TC-DL-005: Non-owner without any permission → denied (404) ────────

    public function test_non_owner_without_permission_is_denied(): void
    {
        $invoice = $this->attachRealPdf($this->invoice);
        $stranger = $this->createUser('stranger@example.com');

        $this->actingAsUser($stranger);
        $response = $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid));

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    // ─── TC-DL-006: Super admin can download ───────────────────────────────

    public function test_super_admin_can_download(): void
    {
        $invoice = $this->attachRealPdf($this->invoice);

        $permission = $this->createPermission(PermissionEnum::VIEW_INVOICE_DOWNLOAD);
        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'guard_name' => self::GUARD,
            'display_name' => 'Super Admin',
        ]);
        $role->givePermissionTo($permission);

        $superAdmin = $this->createUser('super@example.com');
        $superAdmin->assignRole(RoleEnum::SUPER_ADMIN);

        $this->actingAsUser($superAdmin);
        $response = $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    // ─── TC-DL-007: Real file verified (exists, readable, streamed as PDF) ──

    public function test_real_pdf_file_exists_and_is_readable(): void
    {
        $invoice = $this->attachRealPdf($this->invoice);
        $this->actingAsUser($this->owner);

        $response = $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid));

        $response->assertOk();
        $filename = str_replace('/', '-', $invoice->invoice_number) . '.pdf';

        Storage::disk('public')->assertExists('invoices/' . $filename);
        $this->assertTrue(Storage::disk('public')->exists('invoices/' . $filename));
        $this->assertTrue(is_file(Storage::disk('public')->path('invoices/' . $filename)));
        $this->assertTrue(is_readable(Storage::disk('public')->path('invoices/' . $filename)));
        $this->assertStringContainsString('%PDF-', Storage::disk('public')->get('invoices/' . $filename));

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'attachment; filename="' . $filename . '"',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    // ─── TC-DL-008: Invoice exists but PDF missing → 404 ───────────────────

    public function test_invoice_without_pdf_returns_404(): void
    {
        $this->invoice->update([
            'pdf_path' => null,
            'pdf_generated_at' => null,
            'status' => 'generated',
        ]);
        $invoice = $this->invoice->refresh();
        $this->actingAsUser($this->owner);

        $response = $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid));

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    // ─── TC-DL-009: Unknown UUID → 404 ─────────────────────────────────────

    public function test_unknown_uuid_returns_404(): void
    {
        $this->attachRealPdf($this->invoice);
        $this->actingAsUser($this->owner);

        $response = $this->getJson(sprintf(
            self::DOWNLOAD_URL,
            '00000000-0000-0000-0000-000000000000'
        ));

        $response->assertStatus(404);
    }

    // ─── TC-DL-010: Invalid UUID format → route not matched (404) ──────────

    public function test_invalid_uuid_format_returns_404(): void
    {
        $this->attachRealPdf($this->invoice);
        $this->actingAsUser($this->owner);

        $response = $this->getJson(sprintf(self::DOWNLOAD_URL, 'not-a-uuid'));

        $response->assertStatus(404);
    }

    // ─── TC-DL-011: downloaded_at set on first download ────────────────────

    public function test_downloaded_at_is_set_on_first_download(): void
    {
        $invoice = $this->attachRealPdf($this->invoice);
        $this->assertNull($invoice->downloaded_at);
        $this->actingAsUser($this->owner);

        $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid))->assertOk();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
        ]);
        $this->assertNotNull($invoice->refresh()->downloaded_at);
    }

    // ─── TC-DL-012: Repeated download does not reset downloaded_at ─────────

    public function test_repeated_download_preserves_original_downloaded_at(): void
    {
        $invoice = $this->attachRealPdf($this->invoice);
        $this->actingAsUser($this->owner);

        $firstResponse = $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid));
        $firstResponse->assertOk();

        $firstDownloadedAt = $invoice->refresh()->downloaded_at;
        $this->assertNotNull($firstDownloadedAt);

        $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid))->assertOk();

        $this->assertEquals(
            $firstDownloadedAt->format('Y-m-d H:i:s'),
            $invoice->refresh()->downloaded_at->format('Y-m-d H:i:s')
        );
    }

    // ─── TC-DL-013: Timeline event recorded on download ────────────────────

    public function test_timeline_download_event_is_recorded(): void
    {
        $invoice = $this->attachRealPdf($this->invoice);
        $this->actingAsUser($this->owner);

        $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid))->assertOk();

        $this->assertDatabaseHas('invoice_timeline', [
            'invoice_id' => $invoice->id,
            'event' => 'downloaded',
        ]);
    }

    // ─── TC-DL-014: Permission exists in DB (name, guard, no duplicates) ───

    public function test_permission_record_exists_in_database(): void
    {
        $permission = $this->createPermission(PermissionEnum::VIEW_INVOICE_DOWNLOAD);

        $this->assertDatabaseHas('permissions', [
            'name' => PermissionEnum::VIEW_INVOICE_DOWNLOAD,
            'guard_name' => self::GUARD,
        ]);

        $this->assertDatabaseCount('permissions', 1);
        $this->assertSame(PermissionEnum::VIEW_INVOICE_DOWNLOAD, $permission->name);
        $this->assertSame(self::GUARD, $permission->guard_name);
    }

    // ─── TC-DL-015: Super admin role is assigned the permission ────────────

    public function test_super_admin_role_has_download_permission(): void
    {
        $permission = $this->createPermission(PermissionEnum::VIEW_INVOICE_DOWNLOAD);
        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'guard_name' => self::GUARD,
            'display_name' => 'Super Admin',
        ]);
        $role->givePermissionTo($permission);

        $this->assertTrue($role->hasPermissionTo(PermissionEnum::VIEW_INVOICE_DOWNLOAD));
        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);
    }

    // ─── TC-DL-016: Enum constant used (not hardcoded string) ──────────────

    public function test_enum_constant_maps_to_expected_value(): void
    {
        $this->assertSame('view-invoice-download', PermissionEnum::VIEW_INVOICE_DOWNLOAD);
        $this->assertNotSame('view-invoice', PermissionEnum::VIEW_INVOICE_DOWNLOAD);
        $this->assertNotSame('view-invoices', PermissionEnum::VIEW_INVOICE_DOWNLOAD);
    }

    // ─── TC-DL-017: Auth error does not leak invoice existence ─────────────

    public function test_authorization_failure_does_not_leak_invoice_existence(): void
    {
        $invoice = $this->attachRealPdf($this->invoice);

        $this->createPermission(PermissionEnum::VIEW_INVOICE);
        $viewer = $this->createUser('viewer2@example.com');
        $viewer->givePermissionTo(PermissionEnum::VIEW_INVOICE);

        $this->actingAsUser($viewer);
        $deniedResponse = $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid));

        $this->actingAsUser($this->owner);
        $allowedResponse = $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid));

        $deniedResponse->assertStatus(404)->assertJsonPath('success', false);
        $allowedResponse->assertOk();
    }

    // ─── TC-DL-018: Owner with permission still works (no regression) ──────

    public function test_owner_with_download_permission_still_downloads(): void
    {
        $invoice = $this->attachRealPdf($this->invoice);
        $this->createPermission(PermissionEnum::VIEW_INVOICE_DOWNLOAD);
        $this->owner->givePermissionTo(PermissionEnum::VIEW_INVOICE_DOWNLOAD);

        $this->actingAsUser($this->owner);
        $response = $this->getJson(sprintf(self::DOWNLOAD_URL, $invoice->uuid));

        $response->assertOk();
    }
}
