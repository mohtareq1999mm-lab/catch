<?php

namespace Marvel\Imports\Sheets;

class ImagesSheetImport extends RowSheetImport
{
    public function title(): string
    {
        return 'images';
    }

    public function chunkSize(): int
    {
        return 200;
    }

    protected function processRow(array $row, int $excelRow): void
    {
        $sku = $row['product_sku'] ?? '';

        if (empty($sku)) {
            return;
        }

        foreach ($this->imageUrls($row) as $imageUrl) {
            $this->service->processProductImage($sku, $imageUrl, $excelRow);
        }
    }

    /**
     * A row carries either a single `image` or a pipe-separated `images` cell.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function imageUrls(array $row): array
    {
        if (!empty($row['image'])) {
            return [(string) $row['image']];
        }

        if (empty($row['images'])) {
            return [];
        }

        return array_values(array_filter(array_map(
            'trim',
            explode('|', (string) $row['images'])
        )));
    }
}
