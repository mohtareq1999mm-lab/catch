<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;

class PruneImports extends Command
{
    protected $signature = 'imports:prune {--days=30 : Retention days for completed/failed/cancelled imports} {--dry-run : Show what would be deleted}';

    protected $description = 'Prune old import/export files and signal files';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $query = Import::whereIn('status', ['completed', 'completed_with_errors', 'failed', 'cancelled'])
            ->where('updated_at', '<', $cutoff);

        $count = $query->count();
        $fileDeleted = 0;
        $signalDeleted = 0;
        $errors = 0;

        $this->info("Found {$count} imports older than {$days} days (cutoff {$cutoff})");

        if ($count > 0) {
            $imports = $query->get(['id', 'file_path', 'file_name', 'status', 'type']);
            foreach ($imports as $import) {
                if (!empty($import->file_path)) {
                    foreach (['imports', 'public', 'local'] as $disk) {
                        try {
                            if (Storage::disk($disk)->exists($import->file_path)) {
                                if (!$dryRun) {
                                    Storage::disk($disk)->delete($import->file_path);
                                }
                                $fileDeleted++;
                                $this->line(" - Would delete file {$import->file_path} from {$disk} (import {$import->id})");
                                break;
                            }
                        } catch (\Throwable $e) {
                            $errors++;
                            Log::warning("Failed to delete import file {$import->file_path} on {$disk}: " . $e->getMessage());
                        }
                    }
                }
                // For completed exports, file is the export itself - keep logic same: delete after retention
                // For failed imports, file already deleted on terminal, but ensure
            }

            if (!$dryRun) {
                // Delete DB rows after files? Keep rows for audit but prune files only? Spec says prune imports: delete files, not necessarily rows.
                // We will not delete rows, only files, to preserve audit.
                // If you want to delete rows, uncomment:
                // $query->delete();
            }
        }

        // Signal files: progress_{id}.json, cancel_{id}.json
        $signalDir = storage_path('app/imports');
        if (is_dir($signalDir)) {
            $files = glob($signalDir . '/*.json');
            foreach ($files as $file) {
                $mtime = filemtime($file);
                if ($mtime !== false && $mtime < $cutoff->getTimestamp()) {
                    $basename = basename($file);
                    // Extract id
                    if (preg_match('/_(\\d+)\\.json$/', $basename, $m)) {
                        $id = (int) $m[1];
                        $import = Import::find($id);
                        if ($import && ! $import->isTerminal()) {
                            // Skip active
                            continue;
                        }
                    }
                    if (!$dryRun) {
                        @unlink($file);
                    }
                    $signalDeleted++;
                    $this->line(" - Would delete signal {$basename}");
                }
            }
        }

        // Excel temp
        $excelTemp = storage_path('framework/cache/laravel-excel');
        if (is_dir($excelTemp)) {
            $tempFiles = glob($excelTemp . '/*');
            foreach ($tempFiles as $tf) {
                if (is_file($tf) && filemtime($tf) < $cutoff->getTimestamp()) {
                    if (!$dryRun) {
                        @unlink($tf);
                    }
                    $signalDeleted++;
                }
            }
        }

        $this->info("Prune complete: {$fileDeleted} files, {$signalDeleted} signals, {$errors} errors" . ($dryRun ? ' (dry-run)' : ''));
        Log::info("imports:prune completed", ['days' => $days, 'files' => $fileDeleted, 'signals' => $signalDeleted, 'dryRun' => $dryRun]);

        return 0;
    }
}
