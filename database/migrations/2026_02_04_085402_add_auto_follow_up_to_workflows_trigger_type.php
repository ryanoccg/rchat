<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add auto_follow_up to the trigger_type ENUM
        // Only MySQL supports MODIFY COLUMN with ENUM - SQLite stores enums as text
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE workflows MODIFY COLUMN trigger_type ENUM(
                'customer_created', 'customer_returning', 'first_message',
                'conversation_created', 'conversation_closed', 'message_received',
                'no_response', 'scheduled', 'auto_follow_up'
            ) NOT NULL");
        }
        // SQLite doesn't enforce ENUM constraints, so no action needed
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove auto_follow_up from the trigger_type ENUM
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE workflows MODIFY COLUMN trigger_type ENUM(
                'customer_created', 'customer_returning', 'first_message',
                'conversation_created', 'conversation_closed', 'message_received',
                'no_response', 'scheduled'
            ) NOT NULL");
        }
    }
};
