<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Product;

class PurgeOldSoftDeletedProducts extends Command
{
    protected $signature = 'products:purge-old-deleted {--days=30 : Minimum age of the soft-delete timestamp} {--chunk=100}';

    protected $description = 'Permanently delete products that have been soft-deleted for longer than the given number of days (default 30).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $chunk = max(1, (int) $this->option('chunk'));
        $cutoff = now()->subDays($days);

        $count = 0;

        Product::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->select(['id'])
            ->chunkById($chunk, function ($products) use (&$count): void {
                /** @var Product $product */
                foreach ($products as $product) {
                    // forceDelete() triggers MediaCleanupObserver so media rows
                    // and physical files are cleaned alongside the row.
                    $product->forceDelete();
                    $count++;
                }
            });

        // Cached public/admin product payloads reference these rows.
        Cache::tags(['products'])->flush();

        $this->info("Purged {$count} soft-deleted product(s) older than {$days} day(s) (deleted_at <= {$cutoff->toDateTimeString()}).");

        return self::SUCCESS;
    }
}
