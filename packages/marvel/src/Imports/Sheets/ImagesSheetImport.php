<?php

namespace Marvel\Imports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Services\Import\ProductImportService;

class ImagesSheetImport implements ToCollection, WithTitle, WithHeadingRow, SkipsEmptyRows, WithChunkReading
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
        return 'images';
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $sku = $row['product_sku'] ?? '';
            if (empty($sku)) {
                continue;
            }
            $rowIndex = $this->rowOffset + $index + 2;
            $images = [];
            if (!empty($row['image'])) {
                $images[] = $row['image'];
            } elseif (!empty($row['images'])) {
                $items = explode('|', $row['images']);
                foreach ($items as $item) {
                    $item = trim($item);
                    if (!empty($item)) {
                        $images[] = $item;
                    }
                }
            }
            foreach ($images as $imageUrl) {
                $this->service->processProductImage($sku, $imageUrl, $rowIndex);
            }
        }
    }

    public function chunkSize(): int
    {
        return 200;
    }
}
