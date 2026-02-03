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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('plan_name'); // free, starter, professional, enterprise
            $table->string('plan_type')->default('monthly'); // monthly, yearly
            $table->string('status')->default('active'); // active, cancelled, expired, trial
            $table->integer('message_limit')->nullable();
            $table->integer('storage_limit')->nullable(); // MB
            $table->integer('team_member_limit')->nullable();
            $table->integer('platform_limit')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('stripe_subscription_id')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('plan_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
