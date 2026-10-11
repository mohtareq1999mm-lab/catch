<?php

namespace App\Console\Commands;

use Database\Seeders\DigitalTestingSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Safe entry point for the digital testing dataset.
 *
 * Usage (local only):
 *   php artisan digital:testing-seed --confirm-local
 *
 * The command first prints the active environment + database so the
 * operator can verify it is local, then requires an explicit flag.
 * Production is refused unconditionally.
 */
class SeedDigitalTestingData extends Command
{
    protected $signature = 'digital:testing-seed
        {--confirm-local : Confirm the printed database is a disposable LOCAL database}';

    protected $description = 'Seed the synthetic Digital Assets & Entitlements testing dataset (local only, repeatable, non-destructive).';

    public function handle(): int
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        $host = config("database.connections.{$connection}.host");

        $this->info('Active environment : ' . app()->environment());
        $this->info("Active database    : [{$connection}] {$database} @ {$host}");
        $this->info('Storage (private)  : ' . config('filesystems.disks.private.root', '(default storage path)'));

        if (app()->environment('production')) {
            $this->error('Refusing to seed testing data in production.');

            return self::FAILURE;
        }

        if (!$this->option('confirm-local')) {
            $this->warn('Verify the database above is a disposable LOCAL database, then rerun with --confirm-local.');

            return self::FAILURE;
        }

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Database unreachable: ' . substr($e->getMessage(), 0, 200));

            return self::FAILURE;
        }

        $this->call('db:seed', ['--class' => DigitalTestingSeeder::class]);

        return self::SUCCESS;
    }
}
