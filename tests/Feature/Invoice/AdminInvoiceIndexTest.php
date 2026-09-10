<?php

namespace Tests\Feature\Invoice;

use App\Models\Invoice;
use Tests\Concerns\WithAdminInvoiceContext;
use Tests\TestCase;

/**
 * CATEGORY D — HAPPY PATH (index)
 * CATEGORY O — RESPONSE CONTRACT for AdminInvoiceCollection
 *
 * Contract (verified from source):
 *   envelope: { status, message, success, data }
 *   data.data[]  → AdminInvoiceResource items
 *   data.links{} → custom pagination meta (per_page, total, last_page, ...)
 */
class AdminInvoiceIndexTest extends TestCase
{
    use WithAdminInvoiceContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminInvoiceContext();

        $staff = $this->createStaffUser(['view-invoices']);
        $this->actingAsStaff($staff);
    }

    public function test_index_returns_paginated_invoices_with_contract_shape(): void
    {
        $invoice = $this->seedInvoice();

        $response = $this->getJson(self::ADMIN_PREFIX);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'status',
                'message',
                'success',
                'data' => [
                    'data' => [
                        [
                            'id',
                            'uuid',
                            'order_id',
                            'invoice_number',
                            'status',
                            'total',
                            'currency',
                            'is_correction',
                        ],
                    ],
                    'links' => ['per_page', 'total', 'last_page', 'path'],
                ],
            ]);

        $response->assertJsonPath('data.data.0.id', $invoice->id);
        $response->assertJsonPath('data.links.total', 1);
        $response->assertJsonPath('data.links.per_page', 15);
    }

    public function test_limit_is_clamped_to_100(): void
    {
        $this->seedInvoice();

        $response = $this->getJson(self::ADMIN_PREFIX . '?limit=500');

        $response->assertOk();
        $response->assertJsonPath('data.links.per_page', 100);
    }

    public function test_filter_by_status_excludes_other_statuses(): void
    {
        $open = $this->seedInvoice();                       // status generated
        $done = $this->seedInvoice('second@invoice.test');  // second invoice
        Invoice::whereKey($done->id)->update(['status' => 'cancelled']);

        $response = $this->getJson(self::ADMIN_PREFIX . '?status=generated');

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertContains($open->id, $ids);
        $this->assertNotContains($done->id, $ids);
    }

    public function test_filter_by_user_id(): void
    {
        $mine = $this->seedInvoice();
        $other = $this->seedInvoice('other@invoice.test');

        $response = $this->getJson(self::ADMIN_PREFIX . '?user_id=' . $this->customer->id);

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_search_matches_invoice_number(): void
    {
        $target = $this->seedInvoice();

        $response = $this->getJson(self::ADMIN_PREFIX . '?search=' . substr($target->invoice_number, -6));

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertContains($target->id, $ids);
    }

    public function test_sort_by_whitelist_falls_back_to_created_at_for_unknown_field(): void
    {
        $first = $this->seedInvoice();
        $second = $this->seedInvoice('sort@invoice.test');

        // Distinct timestamps — same-second rows have no deterministic order
        // because the query lacks a secondary tiebreaker (see audit report).
        $first->forceFill(['created_at' => now()->subMinute()])->save();
        $second->forceFill(['created_at' => now()])->save();

        // Unknown sort field must not SQL-error and must fall back to created_at desc.
        $response = $this->getJson(self::ADMIN_PREFIX . '?sort_by=evil_column;--');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->values();
        $this->assertSame([$second->id, $first->id], $ids->all());
    }

    public function test_sort_direction_asc_supported(): void
    {
        $first = $this->seedInvoice();
        $second = $this->seedInvoice('asc@invoice.test');

        $first->forceFill(['created_at' => now()->subMinute()])->save();
        $second->forceFill(['created_at' => now()])->save();

        $response = $this->getJson(self::ADMIN_PREFIX . '?sort_by=created_at&sort_direction=asc');

        $ids = collect($response->json('data.data'))->pluck('id')->values();
        $this->assertSame([$first->id, $second->id], $ids->all());
    }
}
