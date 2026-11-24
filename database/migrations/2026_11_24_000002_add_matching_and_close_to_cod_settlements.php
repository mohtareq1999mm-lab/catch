<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_settlement_statements', function (Blueprint $table) {
            if (!Schema::hasColumn('cod_settlement_statements', 'status')) {
                $table->string('status', 32)->default('open')->after('metadata');
                $table->foreignId('closed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
                $table->timestamp('closed_at')->nullable()->after('closed_by');
                $table->text('close_reason')->nullable()->after('closed_at');
                $table->index('status', 'idx_cod_settlement_status');
            }
        });

        Schema::table('cod_settlement_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('cod_settlement_lines', 'match_status')) {
                $table->string('match_status', 32)->default('matched')->after('variance_minor');
                $table->string('match_method', 32)->nullable()->after('match_status');
                $table->string('awb', 191)->nullable()->after('match_method');
                $table->string('provider_shipment_id', 191)->nullable()->after('awb');
                $table->string('order_number', 191)->nullable()->after('provider_shipment_id');
                $table->foreignId('matched_by')->nullable()->after('order_number')->constrained('users')->nullOnDelete();
                $table->timestamp('matched_at')->nullable()->after('matched_by');
                $table->text('match_reason')->nullable()->after('matched_at');
                $table->index('match_status', 'idx_cod_settlement_line_match_status');
                $table->index('awb', 'idx_cod_settlement_line_awb');
                $table->index('provider_shipment_id', 'idx_cod_settlement_line_provider_shipment');
            }
        });

        Schema::create('cod_settlement_match_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_id')->constrained('cod_settlement_statements')->cascadeOnDelete();
            $table->foreignId('line_id')->constrained('cod_settlement_lines')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('previous_match_status', 32)->nullable();
            $table->string('new_match_status', 32);
            $table->string('match_method', 32);
            $table->text('reason');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['statement_id', 'created_at'], 'idx_cod_settlement_match_audit_stmt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_settlement_match_audits');
        Schema::table('cod_settlement_lines', function (Blueprint $table) {
            foreach (['idx_cod_settlement_line_match_status','idx_cod_settlement_line_awb','idx_cod_settlement_line_provider_shipment'] as $idx) {
                try { $table->dropIndex($idx); } catch (\Throwable $e) {}
            }
            $cols = ['match_status','match_method','awb','provider_shipment_id','order_number','matched_by','matched_at','match_reason'];
            foreach ($cols as $c) { if (Schema::hasColumn('cod_settlement_lines', $c)) { if ($c==='matched_by') $table->dropConstrainedForeignId('matched_by'); else $table->dropColumn($c); } }
        });
        Schema::table('cod_settlement_statements', function (Blueprint $table) {
            try { $table->dropIndex('idx_cod_settlement_status'); } catch (\Throwable $e) {}
            foreach (['close_reason','closed_at','closed_by','status'] as $c) {
                if (Schema::hasColumn('cod_settlement_statements', $c)) {
                    if ($c==='closed_by') $table->dropConstrainedForeignId('closed_by'); else $table->dropColumn($c);
                }
            }
        });
    }
};
