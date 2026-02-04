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
        Schema::table('conversation_summaries', function (Blueprint $table) {
            $table->json('keywords')->nullable()->after('action_items');
            $table->text('last_request')->nullable()->after('keywords');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_summaries', function (Blueprint $table) {
            $table->dropColumn(['keywords', 'last_request']);
        });
    }
};
