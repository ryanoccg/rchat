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
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('active_workflow_execution_id')->nullable()->constrained('workflow_executions')->nullOnDelete()->after('closed_reason');
            $table->json('workflow_metadata')->nullable()->after('active_workflow_execution_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['active_workflow_execution_id']);
            $table->dropColumn(['active_workflow_execution_id', 'workflow_metadata']);
        });
    }
};
