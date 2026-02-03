<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Performance indexes for faster API queries
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!$this->hasIndex('customers', 'customers_name_index')) {
                $table->index('name');
            }
        });

        Schema::table('conversations', function (Blueprint $table) {
            if (!$this->hasIndex('conversations', 'conversations_company_id_status_last_message_at_index')) {
                $table->index(['company_id', 'status', 'last_message_at']);
            }
            if (!$this->hasIndex('conversations', 'conversations_company_id_last_message_at_index')) {
                $table->index(['company_id', 'last_message_at']);
            }
        });

        Schema::table('messages', function (Blueprint $table) {
            if (!$this->hasIndex('messages', 'messages_conversation_id_created_at_index')) {
                $table->index(['conversation_id', 'created_at']);
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($idx) => $idx['name'] === $index);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status', 'last_message_at']);
            $table->dropIndex(['company_id', 'last_message_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'created_at']);
        });
    }
};
