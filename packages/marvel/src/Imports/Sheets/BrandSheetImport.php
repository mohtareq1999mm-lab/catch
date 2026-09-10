<?php

namespace Marvel\Imports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Services\Import\BrandImportService;

class BrandSheetImport implements ToCollection, WithTitle, WithHeadingRow, SkipsEmptyRows
{
    protected BrandImportService $service;

    public function __construct(BrandImportService $service)
    {
        $this->service = $service;
    }

    public function title(): string
    {
        return 'brands';
    }

    public function collection(Collection $rows): void
    {
        $this->service->processRows($rows);
    }
}
