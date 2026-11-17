<?php

namespace Tests\Unit\Import;

use Marvel\Services\Import\ImportReport;
use Marvel\Services\Import\RunOutcome;
use Tests\TestCase;

class RunOutcomeTest extends TestCase
{
    public function test_invariant_is_clamped_to_the_known_total(): void
    {
        $report = new ImportReport();
        $outcome = new RunOutcome(fn () => $report->failedRowCount());
        $outcome->setTotalRows(3);

        for ($i = 0; $i < 5; $i++) {
            $outcome->recordSuccess();
        }

        $this->assertSame(5, $outcome->processed());
        $this->assertSame(3, $outcome->clampedProcessed());
        $this->assertSame(3, $outcome->clampedSuccess());
        $this->assertSame(0, $outcome->clampedFailed());
    }

    public function test_status_derivation_matches_product_rows(): void
    {
        $report = new ImportReport();
        $outcome = new RunOutcome(fn () => $report->failedRowCount());

        $this->assertSame('completed', $outcome->status());

        $outcome->recordSuccess();
        $report->addProductRowError(3, 'SKU-1', 'row failed');
        $this->assertSame('completed_with_errors', $outcome->status());
    }

    public function test_all_failed_rows_derive_failed_status(): void
    {
        $report = new ImportReport();
        $report->addProductRowError(2, 'SKU-1', 'row failed');
        $outcome = new RunOutcome(fn () => $report->failedRowCount());

        $this->assertSame(0, $outcome->successCount());
        $this->assertSame(1, $outcome->failedCount());
        $this->assertSame('failed', $outcome->status());
    }

    public function test_error_cap_and_warning_first_ordering(): void
    {
        app()->setLocale('en');

        $report = new ImportReport();
        $report->noteInStockInconsistency();
        $report->addProductRowError(2, 'SKU-1', 'row failed');

        $outcome = new RunOutcome(
            fn () => $report->failedRowCount(),
            fn () => $report->all()
        );
        $outcome->recordSuccess();

        $errors = $outcome->errors();

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('Warning:', $errors[0]['error_message']);
        $this->assertSame('SKU-1', $errors[1]['sku']);
        $this->assertTrue($outcome->downloadAvailable());
    }

    public function test_terminal_total_falls_back_to_known_total_for_empty_files(): void
    {
        $outcome = new RunOutcome(fn () => 0);
        $outcome->setTotalRows(7);

        $this->assertSame(7, $outcome->terminalTotalRows());

        $outcome->recordSuccess();
        $this->assertSame(1, $outcome->terminalTotalRows());
    }

    public function test_family_without_error_provider_reports_no_row_errors(): void
    {
        $failedRows = [['sheet' => 'brands', 'row' => 2, 'error_message' => 'duplicate']];
        $outcome = new RunOutcome(fn () => count($failedRows));

        $outcome->recordSuccess();
        $failedRows[] = ['sheet' => 'brands', 'row' => 3, 'error_message' => 'bad name'];

        $this->assertSame('completed_with_errors', $outcome->status());
        $this->assertSame([], $outcome->errors());
        $this->assertFalse($outcome->downloadAvailable());

        $attributes = $outcome->rowAttributes();
        $this->assertArrayNotHasKey('errors', $attributes);
        $this->assertSame(1, $attributes['success_rows']);
        $this->assertSame(1, $attributes['failed_rows']);
    }
}
