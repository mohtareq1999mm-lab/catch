<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->string('rate_mode', 10)->default('manual')->after('sort_order');
            $table->decimal('manual_rate', 20, 10)->nullable()->after('rate_mode');
            $table->decimal('provider_rate', 20, 10)->nullable()->after('manual_rate');
            $table->string('provider', 50)->nullable()->after('provider_rate');
            $table->timestamp('last_synced_at')->nullable()->after('provider');
            $table->timestamp('provider_rate_at')->nullable()->after('last_synced_at');
            $table->timestamp('effective_rate_updated_at')->nullable()->after('provider_rate_at');
        });

        $today = now()->toDateString();

        DB::table('currencies')
            ->select(['id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $currency) use ($today): void {
                $rate = DB::table('currency_rates')
                    ->where('currency_id', $currency->id)
                    ->whereDate('effective_date', '<=', $today)
                    ->orderByDesc('effective_date')
                    ->orderByDesc('id')
                    ->first(['exchange_rate', 'updated_at']);

                $values = ['rate_mode' => 'manual'];

                if ($rate) {
                    $values['manual_rate'] = $rate->exchange_rate;
                    $values['effective_rate_updated_at'] = $rate->updated_at;
                }

                DB::table('currencies')->where('id', $currency->id)->update($values);
            });
    }

    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn([
                'rate_mode',
                'manual_rate',
                'provider_rate',
                'provider',
                'last_synced_at',
                'provider_rate_at',
                'effective_rate_updated_at',
            ]);
        });
    }
};
