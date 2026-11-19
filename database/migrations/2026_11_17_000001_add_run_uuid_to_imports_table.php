<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One import row can be processed by several attempts (queue retries,
     * endpoint retry). run_uuid stamps the attempt that wrote the core
     * counters, so a deferred finalizer can refuse to terminalize a run it
     * does not belong to — even when the retried run produces identical
     * counters.
     */
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->string('run_uuid', 64)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn('run_uuid');
        });
    }
};
