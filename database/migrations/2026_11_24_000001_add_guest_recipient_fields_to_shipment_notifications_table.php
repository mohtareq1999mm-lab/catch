<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_notifications', function (Blueprint $table) {
            $table->text('recipient_contact_encrypted')->nullable()->after('recipient_id');
            $table->string('recipient_fingerprint_version', 16)->nullable()->after('recipient_contact_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_notifications', function (Blueprint $table) {
            $table->dropColumn(['recipient_contact_encrypted', 'recipient_fingerprint_version']);
        });
    }
};
