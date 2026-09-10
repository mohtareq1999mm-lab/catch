<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Guarded rollback: if NULL emails exist the migration cannot safely
     * restore NOT NULL without inventing fake emails (which would corrupt
     * identity). The rollback therefore fails fast with a clear prerequisite
     * message. Before rolling back, either delete phone-only users or assign
     * them a real email. Example manual cleanup:
     *   UPDATE users SET email = CONCAT('user-', id, '@example.invalid') WHERE email IS NULL;
     */
    public function down(): void
    {
        if (Schema::hasTable('users') && DB::table('users')->whereNull('email')->exists()) {
            throw new \RuntimeException(
                'Cannot rollback make_users_email_nullable: users with NULL email exist. ' .
                'Assign emails or delete phone-only users before rollback. ' .
                'Example: UPDATE users SET email = CONCAT(\'user-\', id, \'@example.invalid\') WHERE email IS NULL;'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
