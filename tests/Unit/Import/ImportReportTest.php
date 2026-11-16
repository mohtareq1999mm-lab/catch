<?php

namespace Tests\Unit\Import;

use Marvel\Services\Import\ImportReport;
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
}
