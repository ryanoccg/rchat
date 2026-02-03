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
        Schema::table('messages', function (Blueprint $table) {
            // Add intent classification columns
            $table->string('intent')->nullable()->after('ai_processed_at');
            $table->decimal('intent_confidence', 3, 2)->nullable()->after('intent');
            $table->timestamp('intent_classified_at')->nullable()->after('intent_confidence');

            // Add indexes for analytics queries
            $table->index('intent');
            $table->index(['intent', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['intent', 'created_at']);
            $table->dropIndex('intent');
            $table->dropColumn(['intent', 'intent_confidence', 'intent_classified_at']);
        });
    }
};
