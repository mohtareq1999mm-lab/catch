<?php

namespace Marvel\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Marvel\Workbook\ProductWorkbookSchema;

class ProductsExport implements WithMultipleSheets
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function sheets(): array
    {
        $sheets = [];

        foreach (ProductWorkbookSchema::exporters() as $key => $exporterClass) {
            $sheets[ProductWorkbookSchema::title($key)] = new $exporterClass($this->filters);
        }

        return $sheets;
    }

    public function download(string $filename)
    {
        return \Maatwebsite\Excel\Facades\Excel::download($this, $filename);
    }

    public function store(string $filename, string $disk)
    {
        return \Maatwebsite\Excel\Facades\Excel::store($this, $filename, $disk);
    }
}
