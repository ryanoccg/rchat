<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_configurations', function (Blueprint $table) {
            // Response delay in seconds (3 = instant, 30 = 30s recommended, 60 = 1min, etc.)
            $table->integer('response_delay_seconds')->default(30)->after('auto_respond');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_configurations', function (Blueprint $table) {
            $table->dropColumn('response_delay_seconds');
        });
    }
};
