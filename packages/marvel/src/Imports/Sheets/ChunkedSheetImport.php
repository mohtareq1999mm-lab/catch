<?php

namespace Marvel\Imports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Services\Import\ProductImportService;

/**
 * Shared plumbing for one chunk of a product workbook sheet.
 *
 * The offset is owned here because chunks restart their index at 0; without it
 * error rows in chunk 2+ point at the wrong Excel row. Empty rows are NOT
 * filtered by the engine (SkipsEmptyRows is deliberately absent): filtering
 * before collection() would shrink the rows delivered per chunk and shift the
 * accumulated offset. Subclasses skip empties themselves while keeping the
 * physical index.
 */
abstract class ChunkedSheetImport implements ToCollection, WithTitle, WithHeadingRow, WithChunkReading
{
    protected ProductImportService $service;

    protected int $rowOffset;

    public function __construct(ProductImportService $service, int $rowOffset = 0)
    {
        $this->service = $service;
        $this->rowOffset = $rowOffset;
    }

    final public function collection(Collection $rows): void
    {
        $this->handleChunk($rows);
        $this->rowOffset += $rows->count();
    }

    abstract protected function handleChunk(Collection $rows): void;
}
