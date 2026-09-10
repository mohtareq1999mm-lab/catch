<?php

namespace Tests\Feature\Invoice;

use Tests\Concerns\WithAdminInvoiceContext;
use Tests\TestCase;

/**
 * CATEGORY A — AUTHENTICATION
 * CATEGORY B — AUTHORIZATION
 *
 * Every admin invoice endpoint sits inside auth:sanctum and is gated by a
 * controller-constructor permission middleware. These tests prove the gate
 * for each endpoint independently.
 */
class AdminInvoiceAuthTest extends TestCase
{
    use WithAdminInvoiceContext;

    private object $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminInvoiceContext();
        $this->invoice = $this->seedInvoice();
    }

    // ─── A: Unauthenticated → 401 on every endpoint ────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->getJson(self::ADMIN_PREFIX)->assertStatus(401);
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson(self::ADMIN_PREFIX . '/' . $this->invoice->id)->assertStatus(401);
    }

    public function test_regenerate_requires_authentication(): void
    {
        $this->postJson(self::ADMIN_PREFIX . '/' . $this->invoice->id . '/regenerate')->assertStatus(401);
    }

    public function test_correct_requires_authentication(): void
    {
        $this->postJson(self::ADMIN_PREFIX . '/' . $this->invoice->id . '/correct', [
            'reason' => 'test',
        ])->assertStatus(401);
    }

    public function test_cancel_requires_authentication(): void
    {
        $this->postJson(self::ADMIN_PREFIX . '/' . $this->invoice->id . '/cancel', [
            'reason' => 'test',
        ])->assertStatus(401);
    }

    public function test_debit_note_requires_authentication(): void
    {
        $this->postJson(self::ADMIN_PREFIX . '/' . $this->invoice->id . '/debit-note', [
            'amount' => 10,
            'reason' => 'test',
        ])->assertStatus(401);
    }

    // ─── B: Authenticated user WITHOUT the endpoint permission → 403 ───────

    public function test_index_forbidden_without_permission(): void
    {
        $staff = $this->createStaffUser([]);
        $this->actingAsStaff($staff);

        $this->getJson(self::ADMIN_PREFIX)->assertStatus(403);
    }

    public function test_show_forbidden_without_permission(): void
    {
        $staff = $this->createStaffUser([], 'show-no-perm@invoice.test');
        $this->actingAsStaff($staff);

        $this->getJson(self::ADMIN_PREFIX . '/' . $this->invoice->id)->assertStatus(403);
    }

    public function test_regenerate_forbidden_without_permission(): void
    {
        $staff = $this->createStaffUser(['view-invoice'], 'regen-no-perm@invoice.test');
        $this->actingAsStaff($staff);

        $this->postJson(self::ADMIN_PREFIX . '/' . $this->invoice->id . '/regenerate')
            ->assertStatus(403);
    }

    public function test_correct_forbidden_without_permission(): void
    {
        $staff = $this->createStaffUser(['view-invoice'], 'correct-no-perm@invoice.test');
        $this->actingAsStaff($staff);

        $this->postJson(self::ADMIN_PREFIX . '/' . $this->invoice->id . '/correct', [
            'reason' => 'nope',
        ])->assertStatus(403);
    }

    public function test_cancel_forbidden_without_permission(): void
    {
        $staff = $this->createStaffUser(['view-invoice'], 'cancel-no-perm@invoice.test');
        $this->actingAsStaff($staff);

        $this->postJson(self::ADMIN_PREFIX . '/' . $this->invoice->id . '/cancel', [
            'reason' => 'nope',
        ])->assertStatus(403);
    }

    public function test_debit_note_forbidden_without_permission(): void
    {
        $staff = $this->createStaffUser(['view-invoice'], 'debit-no-perm@invoice.test');
        $this->actingAsStaff($staff);

        $this->postJson(self::ADMIN_PREFIX . '/' . $this->invoice->id . '/debit-note', [
            'amount' => 5,
            'reason' => 'nope',
        ])->assertStatus(403);
    }

    // ─── B: Wrong permission does not unlock another endpoint ──────────────

    public function test_view_permission_does_not_unlock_mutation_endpoints(): void
    {
        $viewer = $this->createStaffUser(['view-invoices', 'view-invoice'], 'viewer-only@invoice.test');
        $this->actingAsStaff($viewer);

        $id = $this->invoice->id;
        $this->postJson(self::ADMIN_PREFIX . "/{$id}/regenerate")->assertStatus(403);
        $this->postJson(self::ADMIN_PREFIX . "/{$id}/correct", ['reason' => 'x'])->assertStatus(403);
        $this->postJson(self::ADMIN_PREFIX . "/{$id}/cancel", ['reason' => 'x'])->assertStatus(403);
        $this->postJson(self::ADMIN_PREFIX . "/{$id}/debit-note", ['amount' => 1, 'reason' => 'x'])->assertStatus(403);
    }

    // ─── B: Permission holder passes the gate (index proven; DB untouched) ─

    public function test_authorized_user_passes_gate_on_index(): void
    {
        $staff = $this->createStaffUser(['view-invoices']);
        $this->actingAsStaff($staff);

        $this->getJson(self::ADMIN_PREFIX)
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
