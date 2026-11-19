<?php

namespace Marvel\Services\Import;

use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Throwable;

/**
 * One owner for workbook cleanup: an import's stored file may live on any of
 * the legacy disks, and every terminal path must delete it the same way.
 */
trait DeletesImportFile
{
    protected function deleteImportFile(Import $import): void
    {
        if (empty($import->file_path)) {
            return;
        }

        try {
            Storage::disk('imports')->delete($import->file_path);
        } catch (Throwable $e) {
            report($e);
        }

        try {
            Storage::disk('public')->delete($import->file_path);
        } catch (Throwable $e) {
        }

        try {
            Storage::disk('local')->delete($import->file_path);
        } catch (Throwable $e) {
        }
    }
}
