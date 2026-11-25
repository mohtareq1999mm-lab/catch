<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #185: private settlement import file metadata.
 *
 * This is read-only evidence. Rows are pending until explicit finance review;
 * there is no acceptance, reconciliation, payment, or closure state here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_import_files', function (Blueprint $table) {
            $table->id();
            $table->string('statement_key', 191);
            $table->string('provider_code', 32);
            $table->string('mode', 16);
            $table->string('account_code', 64);
            $table->string('original_name', 191);
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->char('file_hash', 64);
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('parser_class', 191);
            $table->string('parser_version', 32);
            $table->unsignedInteger('parsed_line_count')->default(0);
            $table->json('malformed_rows');
            $table->timestamps();

            $table->unique(
                ['provider_code', 'mode', 'account_code', 'statement_key', 'file_hash'],
                'uq_settlement_import_file_identity'
            );
            $table->unique(['disk', 'path'], 'uq_settlement_import_file_path');
            $table->index(['provider_code', 'mode', 'account_code'], 'idx_settlement_import_file_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_import_files');
    }
};
