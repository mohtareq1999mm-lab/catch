<?php

namespace Marvel\Imports\Sheets;

use Illuminate\Support\Collection;

/**
 * A sheet whose rows are processed independently by an importer method.
 */
abstract class RowSheetImport extends ChunkedSheetImport
{
    protected function handleChunk(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $rowData = $row->toArray();

            if ($this->isEmptyRow($rowData)) {
                continue;
            }

            $this->processRow($rowData, $this->rowOffset + $index + 2);
        }
    }

    /**
     * Same definition as the Excel engine's SkipsEmptyRows filter.
     *
     * @param  array<string, mixed>  $rowData
     */
    private function isEmptyRow(array $rowData): bool
    {
        return count(array_filter($rowData)) === 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    abstract protected function processRow(array $row, int $excelRow): void;
}
