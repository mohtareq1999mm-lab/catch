<?php

namespace Marvel\Imports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Services\Import\CategoryImportService;

class CategoriesSheetImport implements ToCollection, WithTitle, WithHeadingRow, SkipsEmptyRows
{
    protected CategoryImportService $service;

    public function __construct(CategoryImportService $service)
    {
        $this->service = $service;
    }

    public function title(): string
    {
        return 'categories';
    }

    public function collection(Collection $rows): void
    {
        $this->service->processRows($rows);
    }
}
