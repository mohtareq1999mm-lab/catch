<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shipping_outbox', 'authorized_request_id')) {
            Schema::table('shipping_outbox', function (Blueprint $table): void {
                $table->string('authorized_request_id', 64)->nullable()->after('idempotency_key');
            });
        }

        if (! $this->indexExists('uq_shipping_outbox_authorized_request')) {
            Schema::table('shipping_outbox', function (Blueprint $table): void {
                $table->unique(
                    ['operation', 'aggregate_type', 'aggregate_id', 'authorized_request_id'],
                    'uq_shipping_outbox_authorized_request',
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('shipping_outbox', 'authorized_request_id')) {
            return;
        }

        if ($this->indexExists('uq_shipping_outbox_authorized_request')) {
            Schema::table('shipping_outbox', function (Blueprint $table): void {
                $table->dropUnique('uq_shipping_outbox_authorized_request');
            });
        }

        Schema::table('shipping_outbox', function (Blueprint $table): void {
            $table->dropColumn('authorized_request_id');
        });
    }

    private function indexExists(string $name): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            ['shipping_outbox', $name],
        );

        return (int) ($row->c ?? 0) > 0;
    }
};
