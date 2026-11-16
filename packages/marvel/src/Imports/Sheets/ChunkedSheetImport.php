<?php

namespace Marvel\Imports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Workbook\ProductWorkbookSchema;
use Marvel\Services\Import\ProductImportService;

/**
 * Shared plumbing for one chunk of a product workbook sheet.
 *
 * Sheet identity (title, chunk size) comes from ProductWorkbookSchema; this
 * class only owns chunk delivery and the physical Excel row offset. The offset
 * is owned here because chunks restart their index at 0; empty rows are skipped
 * by subclasses while keeping the physical index, so error rows stay accurate.
 */
abstract class ChunkedSheetImport implements ToCollection, WithTitle, WithHeadingRow, WithChunkReading
{
    protected const SHEET = '';

    protected ProductImportService $service;

    protected int $rowOffset;

    public function __construct(ProductImportService $service, int $rowOffset = 0)
    {
        $this->service = $service;
        $this->rowOffset = $rowOffset;
    }

    public function title(): string
    {
        return ProductWorkbookSchema::title(static::SHEET);
    }

    public function chunkSize(): int
    {
        return ProductWorkbookSchema::chunkSize(static::SHEET);
    }

    final public function collection(Collection $rows): void
    {
        $this->handleChunk($rows);
        $this->rowOffset += $rows->count();
    }

    abstract protected function handleChunk(Collection $rows): void;
}
