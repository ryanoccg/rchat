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
        Schema::create('calendar_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('google'); // google, outlook, calendly
            $table->json('credentials')->nullable(); // OAuth tokens (access_token, refresh_token, expires_at)
            $table->string('calendar_id')->nullable(); // Selected calendar ID
            $table->string('calendar_name')->nullable(); // Display name for selected calendar
            $table->boolean('is_connected')->default(false);
            $table->boolean('is_enabled')->default(true); // Enable/disable appointment booking
            $table->integer('slot_duration')->default(30); // Default appointment duration in minutes
            $table->integer('buffer_time')->default(15); // Buffer between appointments in minutes
            $table->integer('advance_booking_days')->default(30); // How far in advance can book
            $table->integer('min_notice_hours')->default(24); // Minimum notice required for booking
            $table->json('working_hours')->nullable(); // Working hours per day of week
            $table->json('blocked_dates')->nullable(); // Specific dates to block
            $table->string('timezone')->default('Asia/Kuala_Lumpur');
            $table->text('booking_instructions')->nullable(); // Custom instructions for AI
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('company_id');
        });

        // Appointments table to track bookings made through the chat
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('google_event_id')->nullable(); // Google Calendar event ID
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('status')->default('confirmed'); // pending, confirmed, cancelled, completed, no_show
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable(); // Additional data
            $table->timestamps();

            $table->index(['company_id', 'start_time']);
            $table->index(['company_id', 'status']);
            $table->index('google_event_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('calendar_configurations');
    }
};
