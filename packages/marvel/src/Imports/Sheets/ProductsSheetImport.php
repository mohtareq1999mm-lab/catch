<?php

namespace Marvel\Imports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Services\Import\ProductImportService;

class ProductsSheetImport implements ToCollection, WithTitle, WithHeadingRow, WithChunkReading, SkipsEmptyRows
{
    protected ProductImportService $service;

    protected int $rowOffset = 0;

    public function __construct(ProductImportService $service, int $rowOffset = 0)
    {
        $this->service = $service;
        $this->rowOffset = $rowOffset;
    }

    public function title(): string
    {
        return 'products';
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $rowIndex = $this->rowOffset + $index + 2;
            $this->service->processProductRow($row->toArray(), $rowIndex);
        }
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
