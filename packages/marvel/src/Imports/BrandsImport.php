<?php

namespace Marvel\Imports;

use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Marvel\Imports\Sheets\BrandSheetImport;
use Marvel\Services\Import\BrandImportService;

class BrandsImport implements WithMultipleSheets, SkipsUnknownSheets
{
    protected BrandImportService $service;

    public function __construct(BrandImportService $service)
    {
        $this->service = $service;
    }

    public function sheets(): array
    {
        return [
            0 => new BrandSheetImport($this->service),
        ];
    }

    public function onUnknownSheet($sheetName): void
    {
        // Intentionally ignore unknown sheets (e.g., stray 'Notes' sheet)
    }
}
