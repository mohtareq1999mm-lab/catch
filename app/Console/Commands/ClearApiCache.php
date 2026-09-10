<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearApiCache extends Command
{
    protected $signature = 'api:cache-clear';
    protected $description = 'Clear all cached API responses';

    public function handle()
    {
        Cache::increment('api_cache_version');
        $this->info('API cache cleared successfully.');
        return Command::SUCCESS;
    }
}
