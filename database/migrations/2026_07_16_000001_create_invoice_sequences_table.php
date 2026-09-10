<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->string('series', 10);
            $table->year('sequence_year');
            $table->bigInteger('last_sequence')->unsigned()->default(0);
            $table->timestamps();

            $table->primary(['series', 'sequence_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
    }
};
