<?php

namespace Tests\Feature;

use App\Jobs\ProcessDelayedAiResponse;
use App\Models\AiConfiguration;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessagingPlatform;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * Tests for appointment tag extraction and removal in ProcessDelayedAiResponse
 * These tests verify that:
 * 1. [BOOK_APPOINTMENT: ...] tags are properly parsed
 * 2. Tags are ALWAYS removed from displayed content (even if booking fails)
 * 3. Various tag formats are handled correctly
 */
class AppointmentTagProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected ProcessDelayedAiResponse $job;
    protected Company $company;
    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MessagingPlatformSeeder::class);
        $this->seed(\Database\Seeders\AiProviderSeeder::class);

        $this->company = Company::factory()->create();
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['role' => 'Company Owner']);

        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'platform_user_id' => 'test_customer',
            'name' => 'Test Customer',
        ]);

        $platform = MessagingPlatform::where('slug', 'facebook')->first();
        $connection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $platform->id,
            'is_active' => true,
        ]);

        $this->conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $connection->id,
        ]);

        // Create a job instance to test protected methods
        $this->job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            1 // triggeredByMessageId
        );
    }

    /**
     * Helper to call protected methods on the job
     */
    protected function callProtectedMethod(string $method, array $args = [])
    {
        $reflection = new ReflectionClass($this->job);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        return $method->invokeArgs($this->job, $args);
    }

    /** @test */
    public function extract_appointment_booking_parses_valid_tag()
    {
        $content = 'Hello! I have booked your appointment. [BOOK_APPOINTMENT: date=2026-02-10, time=14:00, name=John Doe, phone=1234567890, email=john@example.com] Thank you!';

        $result = $this->callProtectedMethod('extractAppointmentBooking', [$content]);

        $this->assertNotNull($result);
        $this->assertEquals('2026-02-10', $result['date']);
        $this->assertEquals('14:00', $result['time']);
        $this->assertEquals('John Doe', $result['name']);
        $this->assertEquals('1234567890', $result['phone']);
        $this->assertEquals('john@example.com', $result['email']);
    }

    /** @test */
    public function extract_appointment_booking_handles_names_with_spaces()
    {
        $content = '[BOOK_APPOINTMENT: date=2026-02-10, time=10:30, name=Ryano Chu 3, phone=0174272807, email=ryano2@gmail.com]';

        $result = $this->callProtectedMethod('extractAppointmentBooking', [$content]);

        $this->assertNotNull($result);
        $this->assertEquals('Ryano Chu 3', $result['name']);
        $this->assertEquals('2026-02-10', $result['date']);
        $this->assertEquals('10:30', $result['time']);
    }

    /** @test */
    public function extract_appointment_booking_returns_null_without_date()
    {
        $content = '[BOOK_APPOINTMENT: time=14:00, name=John Doe]';

        $result = $this->callProtectedMethod('extractAppointmentBooking', [$content]);

        $this->assertNull($result);
    }

    /** @test */
    public function extract_appointment_booking_returns_null_without_time()
    {
        $content = '[BOOK_APPOINTMENT: date=2026-02-10, name=John Doe]';

        $result = $this->callProtectedMethod('extractAppointmentBooking', [$content]);

        $this->assertNull($result);
    }

    /** @test */
    public function extract_appointment_booking_returns_null_for_no_tag()
    {
        $content = 'Hello! How can I help you today?';

        $result = $this->callProtectedMethod('extractAppointmentBooking', [$content]);

        $this->assertNull($result);
    }

    /** @test */
    public function extract_appointment_booking_is_case_insensitive()
    {
        $content = '[book_appointment: date=2026-02-10, time=14:00, name=Test]';

        $result = $this->callProtectedMethod('extractAppointmentBooking', [$content]);

        $this->assertNotNull($result);
        $this->assertEquals('2026-02-10', $result['date']);
    }

    /** @test */
    public function remove_appointment_booking_tags_removes_tag_from_content()
    {
        $content = 'Your appointment is confirmed! [BOOK_APPOINTMENT: date=2026-02-10, time=14:00, name=John Doe, phone=123, email=test@test.com] Have a great day!';

        $result = $this->callProtectedMethod('removeAppointmentBookingTags', [$content]);

        $this->assertStringNotContainsString('[BOOK_APPOINTMENT:', $result);
        $this->assertStringNotContainsString('date=2026-02-10', $result);
        $this->assertStringContainsString('Your appointment is confirmed!', $result);
        $this->assertStringContainsString('Have a great day!', $result);
    }

    /** @test */
    public function remove_appointment_booking_tags_handles_multiple_tags()
    {
        $content = '[BOOK_APPOINTMENT: date=2026-02-10, time=14:00, name=Test] Some text [BOOK_APPOINTMENT: date=2026-02-11, time=15:00, name=Test2]';

        $result = $this->callProtectedMethod('removeAppointmentBookingTags', [$content]);

        $this->assertStringNotContainsString('[BOOK_APPOINTMENT:', $result);
        $this->assertStringContainsString('Some text', $result);
    }

    /** @test */
    public function remove_appointment_booking_tags_is_case_insensitive()
    {
        $content = 'Text before [book_APPOINTMENT: date=2026-02-10, time=14:00, name=Test] text after';

        $result = $this->callProtectedMethod('removeAppointmentBookingTags', [$content]);

        $this->assertStringNotContainsString('[book_APPOINTMENT:', $result);
        $this->assertStringContainsString('Text before', $result);
        $this->assertStringContainsString('text after', $result);
    }

    /** @test */
    public function remove_appointment_booking_tags_cleans_up_extra_newlines()
    {
        $content = "Before\n\n\n[BOOK_APPOINTMENT: date=2026-02-10, time=14:00, name=Test]\n\n\nAfter";

        $result = $this->callProtectedMethod('removeAppointmentBookingTags', [$content]);

        // Should have at most 2 consecutive newlines
        $this->assertDoesNotMatchRegularExpression('/\n{3,}/', $result);
    }

    /** @test */
    public function remove_appointment_booking_tags_returns_trimmed_content()
    {
        $content = '   [BOOK_APPOINTMENT: date=2026-02-10, time=14:00, name=Test]   ';

        $result = $this->callProtectedMethod('removeAppointmentBookingTags', [$content]);

        $this->assertEquals('', $result);
    }

    /** @test */
    public function tag_is_always_removed_even_with_invalid_data()
    {
        // This tests the critical fix: tag should be removed even if extraction fails
        $content = 'Hello! [BOOK_APPOINTMENT: invalid_format_here] Goodbye!';

        // Extraction should fail (no date/time)
        $extractResult = $this->callProtectedMethod('extractAppointmentBooking', [$content]);
        $this->assertNull($extractResult);

        // But removal should still work
        $cleanResult = $this->callProtectedMethod('removeAppointmentBookingTags', [$content]);
        $this->assertStringNotContainsString('[BOOK_APPOINTMENT:', $cleanResult);
        $this->assertStringContainsString('Hello!', $cleanResult);
        $this->assertStringContainsString('Goodbye!', $cleanResult);
    }

    /** @test */
    public function tag_removal_handles_partial_data()
    {
        // Tag exists but missing required fields (date only, no time)
        $content = 'Your request: [BOOK_APPOINTMENT: date=2026-02-10, name=Test] Thank you!';

        // Extraction should fail (no time)
        $extractResult = $this->callProtectedMethod('extractAppointmentBooking', [$content]);
        $this->assertNull($extractResult);

        // But tag should still be removed
        $cleanResult = $this->callProtectedMethod('removeAppointmentBookingTags', [$content]);
        $this->assertStringNotContainsString('[BOOK_APPOINTMENT:', $cleanResult);
        $this->assertEquals('Your request: Thank you!', $cleanResult);
    }

    /** @test */
    public function extract_handles_extra_whitespace_in_tag()
    {
        $content = '[BOOK_APPOINTMENT:   date=2026-02-10,   time=14:00,   name=Test User   ]';

        $result = $this->callProtectedMethod('extractAppointmentBooking', [$content]);

        $this->assertNotNull($result);
        $this->assertEquals('2026-02-10', $result['date']);
        $this->assertEquals('14:00', $result['time']);
        $this->assertEquals('Test User', $result['name']);
    }

    /** @test */
    public function extract_handles_mixed_order_of_fields()
    {
        $content = '[BOOK_APPOINTMENT: name=Test User, email=test@test.com, time=14:00, phone=123456, date=2026-02-10]';

        $result = $this->callProtectedMethod('extractAppointmentBooking', [$content]);

        $this->assertNotNull($result);
        $this->assertEquals('2026-02-10', $result['date']);
        $this->assertEquals('14:00', $result['time']);
        $this->assertEquals('Test User', $result['name']);
        $this->assertEquals('test@test.com', $result['email']);
        $this->assertEquals('123456', $result['phone']);
    }

    /** @test */
    public function real_world_example_from_user_issue()
    {
        // This is the actual format that caused the issue
        $content = 'Thank you for booking! [BOOK_APPOINTMENT: date=2024-02-04, time=14:00, name=Ryano Chu 3, phone=0174272807, email=ryano2@gmail.com] We look forward to seeing you!';

        // Extraction should work
        $extractResult = $this->callProtectedMethod('extractAppointmentBooking', [$content]);
        $this->assertNotNull($extractResult);
        $this->assertEquals('2024-02-04', $extractResult['date']);
        $this->assertEquals('14:00', $extractResult['time']);
        $this->assertEquals('Ryano Chu 3', $extractResult['name']);
        $this->assertEquals('0174272807', $extractResult['phone']);
        $this->assertEquals('ryano2@gmail.com', $extractResult['email']);

        // Tag should be removed
        $cleanResult = $this->callProtectedMethod('removeAppointmentBookingTags', [$content]);
        $this->assertStringNotContainsString('[BOOK_APPOINTMENT:', $cleanResult);
        $this->assertStringContainsString('Thank you for booking!', $cleanResult);
        $this->assertStringContainsString('We look forward to seeing you!', $cleanResult);
    }
}
