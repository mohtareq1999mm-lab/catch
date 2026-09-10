<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Marvel\Enums\RefundPolicyStatus;
use Marvel\Enums\RefundPolicyTarget;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('refund_policies', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('target', RefundPolicyTarget::getValues())->default(RefundPolicyTarget::VENDOR);
            $table->enum('status', RefundPolicyStatus::getValues())->default(RefundPolicyStatus::PENDING);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_policy_id')->nullable()->constrained('refund_policies')->onDelete('set null');
            $table->decimal('amount', 10, 2);
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', RefundPolicyStatus::getValues())->default(RefundPolicyStatus::PENDING);
            $table->foreignId('order_id')->nullable()->constrained('orders')->onDelete('set null');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropForeign(['refund_policy_id']);
        });
        Schema::dropIfExists('refund_policies');
    }
};
