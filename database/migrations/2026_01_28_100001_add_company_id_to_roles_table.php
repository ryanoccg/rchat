<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('guard_name')->constrained()->cascadeOnDelete();
            $table->boolean('is_custom')->default(false)->after('company_id');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_id', 'is_custom']);
        });
    }
};
