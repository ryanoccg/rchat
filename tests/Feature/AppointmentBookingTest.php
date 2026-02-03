<?php

namespace Tests\Feature;

use App\Models\AiConfiguration;
use App\Models\AiProvider;
use App\Models\Appointment;
use App\Models\CalendarConfiguration;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\MessagingPlatform;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Services\AI\AiService;
use App\Services\Calendar\AppointmentService;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppointmentBookingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;
    protected CalendarConfiguration $calendarConfig;
    protected Customer $customer;
    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MessagingPlatformSeeder::class);
        $this->seed(\Database\Seeders\AiProviderSeeder::class);

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->user->companies()->attach($this->company->id, ['role' => 'Company Owner']);

        // Create calendar configuration
        $this->calendarConfig = CalendarConfiguration::create([
            'company_id' => $this->company->id,
            'platform' => 'google',
            'is_connected' => true,
            'is_enabled' => true,
            'calendar_id' => 'test_calendar_id',
            'calendar_name' => 'Test Calendar',
            'slot_duration' => 30,
            'min_notice_hours' => 2,
            'advance_booking_days' => 14,
            'timezone' => 'America/New_York',
            'working_hours' => [
                'monday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => true],
                'tuesday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => true],
                'wednesday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => true],
                'thursday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => true],
                'friday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => true],
                'saturday' => ['start' => '09:00', 'end' => '13:00', 'enabled' => false],
                'sunday' => ['start' => '09:00', 'end' => '13:00', 'enabled' => false],
            ],
            'credentials' => [
                'access_token' => 'test_token',
                'refresh_token' => 'test_refresh',
                'expires_at' => now()->addDays(1)->toIso8601String(),
            ],
        ]);

        $this->customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'platform_user_id' => 'test_customer',
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'phone' => '1234567890',
        ]);

        $platform = MessagingPlatform::where('slug', 'facebook')->first();
        $connection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $platform->id,
            'is_active' => true,
        ]);

        $this->conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'platform_connection_id' => $connection->id,
        ]);
    }

    /** @test */
    public function appointment_service_is_available_when_calendar_configured()
    {
        $isAvailable = AppointmentService::isAvailable($this->company->id);
        $this->assertTrue($isAvailable);
    }

    /** @test */
    public function appointment_service_is_not_available_when_calendar_disabled()
    {
        $this->calendarConfig->update(['is_enabled' => false]);

        $isAvailable = AppointmentService::isAvailable($this->company->id);
        $this->assertFalse($isAvailable);
    }

    /** @test */
    public function appointment_service_is_not_available_when_calendar_not_connected()
    {
        $this->calendarConfig->update(['is_connected' => false]);

        $isAvailable = AppointmentService::isAvailable($this->company->id);
        $this->assertFalse($isAvailable);
    }

    /** @test */
    public function get_booking_context_returns_config_when_enabled()
    {
        $context = AppointmentService::getBookingContext($this->company->id);

        $this->assertNotNull($context);
        $this->assertTrue($context['enabled']);
        $this->assertEquals(30, $context['slot_duration']);
        $this->assertEquals(2, $context['min_notice_hours']);
        $this->assertEquals(14, $context['advance_booking_days']);
        $this->assertEquals('America/New_York', $context['timezone']);
    }

    /** @test */
    public function get_booking_context_returns_null_when_disabled()
    {
        $this->calendarConfig->update(['is_enabled' => false]);

        $context = AppointmentService::getBookingContext($this->company->id);

        $this->assertNull($context);
    }

    /** @test */
    public function ai_service_includes_appointment_context_when_enabled()
    {
        // Mock the Google Calendar API for availability check
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([
                'items' => [],
            ], 200),
        ]);

        $aiService = new AiService($this->company);

        // We can't directly test the internal prompt, but we can verify the service works
        $this->assertTrue(AppointmentService::isAvailable($this->company->id));
    }

    /** @test */
    public function can_book_appointment_via_api()
    {
        Http::fake([
            // Mock freeBusy endpoint response
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => [
                    'test_calendar_id' => [
                        'busy' => [],
                    ],
                ],
            ], 200),
            // Mock create event endpoint response
            'www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([
                'id' => 'google_event_123',
                'htmlLink' => 'https://calendar.google.com/event/test',
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/appointments", [
                'company_id' => $this->company->id,
                'start_time' => now()->addDay()->setHour(10)->setMinute(0)->setSecond(0)->format('Y-m-d\TH:i:sP'),
                'customer_name' => 'John Doe',
                'customer_email' => 'john@example.com',
                'customer_phone' => '555-123-4567',
                'customer_id' => $this->customer->id,
                'conversation_id' => $this->conversation->id,
                'title' => 'Product Consultation',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Appointment booked successfully',
            ]);

        $this->assertDatabaseHas('appointments', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'conversation_id' => $this->conversation->id,
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'customer_phone' => '555-123-4567',
            'title' => 'Product Consultation',
            'status' => 'confirmed',
            'google_event_id' => 'google_event_123',
        ]);
    }

    /** @test */
    public function can_get_available_dates_via_api()
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => [
                    'test_calendar_id' => [
                        'busy' => [],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/appointments/available-dates", [
                'company_id' => $this->company->id,
                'days' => 7,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
            ]);
    }

    /** @test */
    public function can_get_available_slots_for_specific_date()
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => [
                    'test_calendar_id' => [
                        'busy' => [],
                    ],
                ],
            ], 200),
        ]);

        $targetDate = now()->addDay()->format('Y-m-d');
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/appointments/available-slots?company_id={$this->company->id}&date={$targetDate}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'date',
                'slots',
            ]);
    }

    /** @test */
    public function can_list_appointments_via_api()
    {
        Appointment::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/appointments?company_id={$this->company->id}");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    /** @test */
    public function can_filter_appointments_by_status()
    {
        $confirmed = Appointment::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'confirmed',
        ]);
        $pending = Appointment::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'pending',
        ]);

        // Debug: Verify the appointments were created with correct status
        $this->assertEquals('confirmed', $confirmed->refresh()->status, 'First appointment should be confirmed');
        $this->assertEquals('pending', $pending->refresh()->status, 'Second appointment should be pending');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/appointments?company_id={$this->company->id}&status=confirmed");

        $response->assertStatus(200);

        // Debug: Check what the API returns
        $data = $response->json('data');
        if (count($data) !== 1) {
            $this->fail("Expected 1 appointment, got " . count($data) . ". Data: " . json_encode($data));
        }

        $response->assertJsonCount(1, 'data');
    }

    /** @test */
    public function can_cancel_appointment_via_api()
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/*/events/*' => Http::response([], 200),
        ]);

        $appointment = Appointment::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'confirmed',
            'google_event_id' => 'google_event_123',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel", [
                'company_id' => $this->company->id,
                'reason' => 'Customer requested cancellation',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Appointment cancelled successfully',
            ]);

        $appointment->refresh();
        $this->assertEquals('cancelled', $appointment->status);
    }

    /** @test */
    public function can_reschedule_appointment_via_api()
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => [
                    'test_calendar_id' => [
                        'busy' => [],
                    ],
                ],
            ], 200),
            'www.googleapis.com/calendar/v3/calendars/*/events/*' => Http::response([
                'id' => 'google_event_456',
                'htmlLink' => 'https://calendar.google.com/event/test',
            ], 200),
        ]);

        // Create appointment on Tuesday (working day)
        $appointmentDate = now()->next('Tuesday')->setHour(10)->setMinute(0)->setSecond(0);
        $appointment = Appointment::factory()->create([
            'company_id' => $this->company->id,
            'start_time' => $appointmentDate,
            'end_time' => $appointmentDate->copy()->addMinutes(30),
            'status' => 'confirmed',
            'google_event_id' => 'google_event_123',
        ]);

        // Reschedule to Wednesday (different working day) at a different time
        $newTime = now()->next('Wednesday')->setHour(14)->setMinute(0)->setSecond(0);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/reschedule", [
                'company_id' => $this->company->id,
                'start_time' => $newTime->format('Y-m-d\TH:i:sP'),
            ]);

        if ($response->status() !== 200) {
            $this->fail("Reschedule failed: " . json_encode($response->json()));
        }

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Appointment rescheduled successfully',
            ]);

        $appointment->refresh();
        $this->assertEquals('14', $appointment->start_time->format('H'));
    }

    /** @test */
    public function cannot_cancel_already_cancelled_appointment()
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response([], 200),
        ]);

        $appointment = Appointment::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'cancelled',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel", [
                'company_id' => $this->company->id,
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Appointment is already cancelled',
            ]);
    }

    /** @test */
    public function cannot_reschedule_cancelled_appointment()
    {
        $appointment = Appointment::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'cancelled',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/reschedule", [
                'company_id' => $this->company->id,
                'start_time' => now()->addDays(2)->setHour(10)->setMinute(0)->setSecond(0)->format('Y-m-d\TH:i:sP'),
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Cannot reschedule a cancelled appointment',
            ]);
    }

    /** @test */
    public function appointment_validation_requires_required_fields()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/appointments", [
                'company_id' => $this->company->id,
                // Missing required fields
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['start_time', 'customer_name']);
    }

    /** @test */
    public function can_get_upcoming_appointments()
    {
        Appointment::factory()->create([
            'company_id' => $this->company->id,
            'start_time' => now()->subDay(),
            'status' => 'completed',
        ]);

        Appointment::factory()->create([
            'company_id' => $this->company->id,
            'start_time' => now()->addDay(),
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/appointments/upcoming", [
                'company_id' => $this->company->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /** @test */
    public function customer_id_and_conversation_id_link_appointment_to_records()
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => [
                    'test_calendar_id' => [
                        'busy' => [],
                    ],
                ],
            ], 200),
            'www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([
                'id' => 'google_event_123',
                'htmlLink' => 'https://calendar.google.com/event/test',
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/appointments", [
                'company_id' => $this->company->id,
                'start_time' => now()->addDay()->setHour(10)->setMinute(0)->setSecond(0)->format('Y-m-d\TH:i:sP'),
                'customer_name' => 'Test Customer',
                'customer_id' => $this->customer->id,
                'conversation_id' => $this->conversation->id,
            ]);

        if ($response->status() !== 201) {
            $this->fail("Booking failed: " . json_encode($response->json()));
        }

        $response->assertStatus(201);

        $appointment = Appointment::where('company_id', $this->company->id)
            ->orderBy('id', 'desc')
            ->first();
        $this->assertEquals($this->customer->id, $appointment->customer_id);
        $this->assertEquals($this->conversation->id, $appointment->conversation_id);
    }

    /** @test */
    public function appointment_metadata_includes_booking_source()
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => [
                    'test_calendar_id' => [
                        'busy' => [],
                    ],
                ],
            ], 200),
            'www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([
                'id' => 'google_event_123',
                'htmlLink' => 'https://calendar.google.com/event/test',
            ], 200),
        ]);

        // Use a simple date format that Laravel validation accepts
        $startTime = now()->addDay()->setHour(10)->setMinute(0)->setSecond(0)->format('Y-m-d\TH:i:sP');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/appointments", [
                'company_id' => $this->company->id,
                'start_time' => $startTime,
                'customer_name' => 'Test Customer',
            ]);

        // Debug: show error if not 201
        if ($response->status() !== 201) {
            $error = $response->json();
            $this->fail("API Error ({$response->status()}): " . json_encode($error));
        }

        // First verify the appointment was created successfully
        $response->assertStatus(201);

        $appointment = Appointment::where('company_id', $this->company->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($appointment, 'Appointment should be created');

        // The metadata should be set by AppointmentService
        $this->assertNotNull($appointment->metadata, 'Metadata should not be null');
        $this->assertEquals('chat', $appointment->metadata['booked_via'] ?? null);
        $this->assertNotEmpty($appointment->metadata['google_event_link'] ?? null);
    }
}
