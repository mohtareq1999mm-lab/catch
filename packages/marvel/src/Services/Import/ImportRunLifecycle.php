<?php

namespace Marvel\Services\Import;

use App\Support\OperationHeartbeat;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Throwable;

/**
 * The ONE run-lifecycle chassis for the import jobs (architecture review,
 * candidate 3): heartbeat + signal-file hygiene, workbook resolution
 * across the legacy disks, workbook cleanup, and exception-message
 * sanitization — previously byte-identical private copies in every job
 * (with one comment drift), now a single definition.
 *
 * Contract: the consuming job exposes the row id as `$this->importId`
 * (int). Workbook cleanup delegates to DeletesImportFile, the existing
 * one owner.
 */
trait ImportRunLifecycle
{
    use DeletesImportFile;

    protected function heartbeat(): OperationHeartbeat
    {
        return OperationHeartbeat::for($this->importId);
    }

    protected function removeSignalFile(string $type): void
    {
        $this->heartbeat()->remove($type);
    }

    protected function cancelSignalFileExists(): bool
    {
        return $this->heartbeat()->cancelRequested();
    }

    protected function cleanSignals(): void
    {
        $this->removeSignalFile('cancel');
        $this->removeSignalFile('progress');
    }

    protected function resolveImportFilePath(Import $import): ?string
    {
        if (empty($import->file_path)) {
            return null;
        }

        // Primary: private imports disk
        if (Storage::disk('imports')->exists($import->file_path)) {
            return Storage::disk('imports')->path($import->file_path);
        }

        // Legacy fallback: public disk
        if (Storage::disk('public')->exists($import->file_path)) {
            return Storage::disk('public')->path($import->file_path);
        }

        // Fallback: local disk
        if (Storage::disk('local')->exists($import->file_path)) {
            return Storage::disk('local')->path($import->file_path);
        }

        // Last resort: try direct path resolution via imports disk (may be stale)
        try {
            return Storage::disk('imports')->path($import->file_path);
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function sanitizeExceptionMessage(Throwable $e): string
    {
        $message = $e->getMessage();

        // Prevent leaking absolute paths, SQL, or credentials
        $message = preg_replace('#/[^ ]*storage[^ ]*#i', '[storage path]', $message) ?? $message;
        $message = preg_replace('#SQLSTATE\[[^\]]+\].*#i', 'Internal processing error', $message) ?? $message;

        // Truncate overly long messages
        if (strlen($message) > 500) {
            $message = substr($message, 0, 500) . '...';
        }

        $message = trim($message);

        return $message !== '' ? $message : 'Import failed due to an unexpected error';
    }
}
