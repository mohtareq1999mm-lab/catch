<?php

namespace Tests\Unit\Import;

use Marvel\Services\Import\ImportReport;
use Marvel\Services\Import\RunOutcome;
use Tests\TestCase;

class ImportReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
    }

    public function test_repeated_reads_are_idempotent_and_merge_later_skus(): void
    {
        $report = new ImportReport();

        $report->noteUnknownTaxonomySlugs('categories', 'category', ['missing-slug'], 'SKU-1');
        $first = $report->all();

        $report->noteUnknownTaxonomySlugs('categories', 'category', ['missing-slug'], 'SKU-2');
        $second = $report->all();

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertStringContainsString('missing-slug', $second[0]['error_message']);
        $this->assertStringContainsString('SKU-1', $second[0]['error_message']);
        $this->assertStringContainsString('SKU-2', $second[0]['error_message']);
        $this->assertStringContainsString('2 product SKU', $second[0]['error_message']);
    }

    public function test_in_stock_warning_precedes_individual_errors(): void
    {
        $report = new ImportReport();
        $report->noteInStockInconsistency();
        $report->addProductRowError(4, 'SKU-X', 'row failed');

        $errors = $report->all();

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('Warning:', $errors[0]['error_message']);
        $this->assertSame('SKU-X', $errors[1]['sku']);
    }

    public function test_merge_persisted_appends_incoming_after_persisted(): void
    {
        $persisted = [['sheet' => 'products', 'row' => 2, 'sku' => 'SKU-A', 'error_message' => 'persisted']];
        $incoming = [['sheet' => 'images', 'row' => 3, 'sku' => 'SKU-B', 'error_message' => 'incoming']];

        $merged = ImportReport::mergePersisted($persisted, $incoming);

        $this->assertCount(2, $merged);
        $this->assertSame('persisted', $merged[0]['error_message']);
        $this->assertSame('incoming', $merged[1]['error_message']);
    }

    public function test_merge_persisted_decodes_a_json_string(): void
    {
        $persisted = json_encode([['sheet' => 'products', 'row' => 1, 'sku' => 'SKU-A', 'error_message' => 'kept']]);

        $merged = ImportReport::mergePersisted($persisted, []);

        $this->assertSame('kept', $merged[0]['error_message']);
    }

    public function test_merge_persisted_caps_at_the_single_error_cap(): void
    {
        $persisted = array_fill(0, RunOutcome::ERROR_CAP - 1, ['error_message' => 'old']);
        $incoming = array_fill(0, 5, ['error_message' => 'new']);

        $merged = ImportReport::mergePersisted($persisted, $incoming);

        $this->assertCount(RunOutcome::ERROR_CAP, $merged);
        $this->assertSame('old', $merged[0]['error_message']);
        $this->assertSame('new', $merged[RunOutcome::ERROR_CAP - 1]['error_message']);
    }

    public function test_merge_persisted_tolerates_null_and_scalars(): void
    {
        $this->assertSame([], ImportReport::mergePersisted(null, []));
        $this->assertSame([], ImportReport::mergePersisted(42, []));
        $this->assertSame([], ImportReport::mergePersisted('{"broken":', []));
    }
}
