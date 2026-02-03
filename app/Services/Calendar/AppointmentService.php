<?php

namespace App\Services\Calendar;

use App\Models\Appointment;
use App\Models\CalendarConfiguration;
use App\Models\Conversation;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AppointmentService
{
    private CalendarConfiguration $config;
    private GoogleCalendarService $calendarService;

    public function __construct(int $companyId)
    {
        $this->config = CalendarConfiguration::where('company_id', $companyId)->first();

        if (!$this->config || !$this->config->is_connected || !$this->config->is_enabled) {
            throw new \Exception('Calendar is not configured or enabled for this company');
        }

        $this->calendarService = new GoogleCalendarService($this->config);
    }

    /**
     * Check if appointment booking is available
     */
    public static function isAvailable(int $companyId): bool
    {
        $config = CalendarConfiguration::where('company_id', $companyId)->first();
        return $config && $config->is_connected && $config->is_enabled;
    }

    /**
     * Get booking configuration for AI context
     */
    public static function getBookingContext(int $companyId): ?array
    {
        $config = CalendarConfiguration::where('company_id', $companyId)->first();

        if (!$config || !$config->is_connected || !$config->is_enabled) {
            return null;
        }

        return [
            'enabled' => true,
            'slot_duration' => $config->slot_duration,
            'min_notice_hours' => $config->min_notice_hours,
            'advance_booking_days' => $config->advance_booking_days,
            'timezone' => $config->timezone,
            'booking_instructions' => $config->booking_instructions,
        ];
    }

    /**
     * Get available dates for booking
     */
    public function getAvailableDates(int $days = 14): array
    {
        return $this->calendarService->getAvailableDates($days);
    }

    /**
     * Get available time slots for a date
     */
    public function getAvailableSlots(string $date): array
    {
        return $this->calendarService->getAvailableSlots($date);
    }

    /**
     * Book an appointment
     */
    public function bookAppointment(array $data, ?Customer $customer = null, ?Conversation $conversation = null): Appointment
    {
        // Validate the slot is still available
        $date = Carbon::parse($data['start_time'])->format('Y-m-d');
        $requestedStart = Carbon::parse($data['start_time'])->format('H:i');

        $availableSlots = $this->calendarService->getAvailableSlots($date);
        $slotAvailable = false;

        foreach ($availableSlots as $slot) {
            if ($slot['start'] === $requestedStart) {
                $slotAvailable = true;
                break;
            }
        }

        if (!$slotAvailable) {
            throw new \Exception('The selected time slot is no longer available. Please choose another time.');
        }

        // Calculate end time
        $startTime = Carbon::parse($data['start_time']);
        $endTime = $startTime->copy()->addMinutes($this->config->slot_duration);

        // Create event in Google Calendar
        $eventData = [
            'title' => $data['title'] ?? 'Appointment with ' . ($data['customer_name'] ?? 'Customer'),
            'description' => $this->buildEventDescription($data, $customer),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'customer_name' => $data['customer_name'] ?? $customer?->name,
            'customer_email' => $data['customer_email'] ?? $customer?->email,
        ];

        try {
            $googleEvent = $this->calendarService->createEvent($eventData);
        } catch (\Exception $e) {
            Log::error('Failed to create Google Calendar event', [
                'error' => $e->getMessage(),
                'data' => $eventData,
            ]);
            throw new \Exception('Failed to create appointment in calendar. Please try again.');
        }

        // Create appointment record in database
        $appointment = Appointment::create([
            'company_id' => $this->config->company_id,
            'customer_id' => $customer?->id,
            'conversation_id' => $conversation?->id,
            'google_event_id' => $googleEvent['id'],
            'title' => $eventData['title'],
            'description' => $eventData['description'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'customer_name' => $data['customer_name'] ?? $customer?->name ?? 'Unknown',
            'customer_email' => $data['customer_email'] ?? $customer?->email,
            'customer_phone' => $data['customer_phone'] ?? $customer?->phone,
            'status' => Appointment::STATUS_CONFIRMED,
            'notes' => $data['notes'] ?? null,
            'metadata' => [
                'booked_via' => 'chat',
                'google_event_link' => $googleEvent['htmlLink'] ?? null,
            ],
        ]);

        Log::info('Appointment booked successfully', [
            'appointment_id' => $appointment->id,
            'google_event_id' => $googleEvent['id'],
            'start_time' => $startTime->toDateTimeString(),
        ]);

        return $appointment;
    }

    /**
     * Cancel an appointment
     */
    public function cancelAppointment(Appointment $appointment, ?string $reason = null): Appointment
    {
        // Cancel in Google Calendar
        if ($appointment->google_event_id) {
            try {
                $this->calendarService->deleteEvent($appointment->google_event_id);
            } catch (\Exception $e) {
                Log::warning('Failed to delete Google Calendar event', [
                    'error' => $e->getMessage(),
                    'event_id' => $appointment->google_event_id,
                ]);
            }
        }

        // Update appointment status
        $appointment->update([
            'status' => Appointment::STATUS_CANCELLED,
            'notes' => $reason ? ($appointment->notes . "\nCancellation reason: " . $reason) : $appointment->notes,
        ]);

        return $appointment;
    }

    /**
     * Reschedule an appointment
     */
    public function rescheduleAppointment(Appointment $appointment, string $newStartTime): Appointment
    {
        // Validate the new slot is available
        $date = Carbon::parse($newStartTime)->format('Y-m-d');
        $requestedStart = Carbon::parse($newStartTime)->format('H:i');

        $availableSlots = $this->calendarService->getAvailableSlots($date);
        $slotAvailable = false;

        foreach ($availableSlots as $slot) {
            if ($slot['start'] === $requestedStart) {
                $slotAvailable = true;
                break;
            }
        }

        if (!$slotAvailable) {
            throw new \Exception('The selected time slot is not available. Please choose another time.');
        }

        $newStart = Carbon::parse($newStartTime);
        $newEnd = $newStart->copy()->addMinutes($this->config->slot_duration);

        // Update in Google Calendar
        if ($appointment->google_event_id) {
            try {
                $this->calendarService->updateEvent($appointment->google_event_id, [
                    'start_time' => $newStart,
                    'end_time' => $newEnd,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to update Google Calendar event', [
                    'error' => $e->getMessage(),
                    'event_id' => $appointment->google_event_id,
                ]);
                throw new \Exception('Failed to reschedule appointment. Please try again.');
            }
        }

        // Update appointment record
        $appointment->update([
            'start_time' => $newStart,
            'end_time' => $newEnd,
        ]);

        return $appointment;
    }

    /**
     * Get upcoming appointments for a customer
     */
    public function getCustomerAppointments(Customer $customer): \Illuminate\Database\Eloquent\Collection
    {
        return Appointment::where('company_id', $this->config->company_id)
            ->where('customer_id', $customer->id)
            ->upcoming()
            ->get();
    }

    /**
     * Format available slots for AI response
     */
    public function formatSlotsForAI(string $date): string
    {
        $slots = $this->getAvailableSlots($date);

        if (empty($slots)) {
            return "No available slots on " . Carbon::parse($date)->format('l, F j');
        }

        $formattedDate = Carbon::parse($date)->format('l, F j');
        $slotList = array_map(fn($s) => $s['start'], $slots);

        // Group slots for readability
        $times = implode(', ', array_slice($slotList, 0, 8));
        if (count($slotList) > 8) {
            $times .= ' and more';
        }

        return "Available on {$formattedDate}: {$times}";
    }

    /**
     * Format available dates for AI response
     */
    public function formatDatesForAI(int $maxDays = 7): string
    {
        $dates = $this->getAvailableDates($maxDays);

        if (empty($dates)) {
            return "No available appointment slots in the next {$maxDays} days.";
        }

        $formatted = array_map(function ($d) {
            return $d['formatted'] . " ({$d['slot_count']} slots)";
        }, array_slice($dates, 0, 5));

        return "Available dates: " . implode(', ', $formatted);
    }

    /**
     * Build event description
     */
    private function buildEventDescription(array $data, ?Customer $customer): string
    {
        $lines = [];
        $lines[] = "Appointment booked via RChat";
        $lines[] = "";

        if (!empty($data['customer_name'])) {
            $lines[] = "Name: " . $data['customer_name'];
        } elseif ($customer?->name) {
            $lines[] = "Name: " . $customer->name;
        }

        if (!empty($data['customer_email'])) {
            $lines[] = "Email: " . $data['customer_email'];
        } elseif ($customer?->email) {
            $lines[] = "Email: " . $customer->email;
        }

        if (!empty($data['customer_phone'])) {
            $lines[] = "Phone: " . $data['customer_phone'];
        } elseif ($customer?->phone) {
            $lines[] = "Phone: " . $customer->phone;
        }

        if (!empty($data['notes'])) {
            $lines[] = "";
            $lines[] = "Notes: " . $data['notes'];
        }

        return implode("\n", $lines);
    }
}
