<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddActivityLogIndexes extends Migration
{
    public function up()
    {
        Schema::connection(config('activitylog.database_connection'))->table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->index(['log_name', 'event', 'created_at'], 'activity_log_log_event_created_idx');
            $table->index(['causer_type', 'causer_id', 'created_at'], 'activity_log_causer_created_idx');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'activity_log_subject_created_idx');
            $table->index('created_at', 'activity_log_created_at_idx');
        });
    }

    public function down()
    {
        Schema::connection(config('activitylog.database_connection'))->table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->dropIndex('activity_log_log_event_created_idx');
            $table->dropIndex('activity_log_causer_created_idx');
            $table->dropIndex('activity_log_subject_created_idx');
            $table->dropIndex('activity_log_created_at_idx');
        });
    }
}
