<?php

namespace Tests\Unit\Import;

use Illuminate\Support\Collection;
use Marvel\Imports\Sheets\RowSheetImport;
use Marvel\Services\Import\ProductImportService;
use Tests\TestCase;

class RowSheetImportTest extends TestCase
{
    public function test_chunks_keep_pointing_at_real_excel_rows_even_with_blank_rows(): void
    {
        $importer = new class(new ProductImportService()) extends RowSheetImport {
            /** @var array<int, int> */
            public array $seenRows = [];

            public function title(): string
            {
                return 'products';
            }

            public function chunkSize(): int
            {
                return 2;
            }

            protected function processRow(array $row, int $excelRow): void
            {
                $this->seenRows[] = $excelRow;
            }
        };

        // Excel rows 2, 3, 4 — row 3 is blank and must be skipped without
        // shifting row 4 (or the next chunk starting at row 5).
        $importer->collection($this->chunk([
            ['sku' => 'A'],
            [],
            ['sku' => 'B'],
        ]));

        $importer->collection($this->chunk([['sku' => 'C']]));

        $this->assertSame([2, 4, 5], $importer->seenRows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, Collection<string, mixed>>
     */
    private function chunk(array $rows): Collection
    {
        return (new Collection($rows))->map(fn (array $row) => new Collection($row));
    }
}
