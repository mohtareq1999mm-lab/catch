<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flow Input definitions (Dynamic Flow Input system).
 *
 * One row per required/optional input per order flow. Array order
 * (sort_order) drives frontend rendering; required_at decides WHEN the
 * input is demanded: 'checkout' or 'transition:<status_code>'.
 *
 * Labels are admin-authored bilingual JSON ({en,ar}) via Spatie
 * HasTranslations on the model — same convention as countries/
 * governorates — so custom inputs need no lang-file deploy.
 * Validation messages stay in resources/lang/{en,ar}/checkout.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('order_flows')->cascadeOnDelete();
            $table->string('key', 50);
            $table->json('label');
            $table->json('placeholder')->nullable();
            $table->json('help_text')->nullable();
            $table->string('type', 20);
            $table->string('source', 30)->nullable();
            $table->boolean('required')->default(false);
            $table->string('required_at', 60)->default('checkout');
            $table->unsignedInteger('sort_order');
            $table->json('validation')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['flow_id', 'key'], 'flow_inputs_flow_key_unique');
            $table->unique(['flow_id', 'sort_order'], 'flow_inputs_flow_order_unique');
            $table->index(['flow_id', 'sort_order'], 'flow_inputs_flow_order_idx');
            $table->index('is_active', 'flow_inputs_is_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_inputs');
    }
};
