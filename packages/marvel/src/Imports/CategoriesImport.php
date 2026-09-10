<?php

namespace Marvel\Imports;

use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Marvel\Imports\Sheets\CategoriesSheetImport;
use Marvel\Services\Import\CategoryImportService;

class CategoriesImport implements WithMultipleSheets, SkipsUnknownSheets
{
    protected CategoryImportService $service;

    public function __construct(CategoryImportService $service)
    {
        $this->service = $service;
    }

    public function sheets(): array
    {
        return [
            0 => new CategoriesSheetImport($this->service),
        ];
    }

    public function onUnknownSheet($sheetName): void
    {
        // Ignore stray sheets
    }
}