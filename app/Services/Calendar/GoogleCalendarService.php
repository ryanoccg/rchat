<?php

namespace App\Services\Calendar;

use App\Models\Appointment;
use App\Models\CalendarConfiguration;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    private const CALENDAR_API_URL = 'https://www.googleapis.com/calendar/v3';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private CalendarConfiguration $config;
    private string $accessToken;

    public function __construct(CalendarConfiguration $config)
    {
        $this->config = $config;
        $this->accessToken = $this->getValidAccessToken();
    }

    /**
     * Get available time slots for a specific date
     */
    public function getAvailableSlots(string $date): array
    {
        $targetDate = Carbon::parse($date, $this->config->timezone);
        $dayOfWeek = strtolower($targetDate->format('l'));

        // Check if this day is enabled in working hours
        $workingHours = $this->config->working_hours ?? CalendarConfiguration::getDefaultWorkingHours();
        if (!isset($workingHours[$dayOfWeek]) || !$workingHours[$dayOfWeek]['enabled']) {
            return [];
        }

        // Check if date is blocked
        $blockedDates = $this->config->blocked_dates ?? [];
        if (in_array($targetDate->format('Y-m-d'), $blockedDates)) {
            return [];
        }

        // Check minimum notice
        $minNotice = now()->addHours($this->config->min_notice_hours);
        if ($targetDate->endOfDay()->lt($minNotice)) {
            return [];
        }

        // Get working hours for this day
        $dayHours = $workingHours[$dayOfWeek];
        $dayStart = $targetDate->copy()->setTimeFromTimeString($dayHours['start']);
        $dayEnd = $targetDate->copy()->setTimeFromTimeString($dayHours['end']);

        // Adjust for minimum notice if today
        if ($targetDate->isToday() && $minNotice->gt($dayStart)) {
            // Round up to next slot
            $slotDuration = $this->config->slot_duration;
            $minutes = $minNotice->minute;
            $roundedMinutes = ceil($minutes / $slotDuration) * $slotDuration;
            $dayStart = $minNotice->copy()->minute($roundedMinutes)->second(0);
        }

        // Fetch busy times from Google Calendar
        $busyTimes = $this->getBusyTimes($dayStart, $dayEnd);

        // Also get existing appointments from our database
        $existingAppointments = Appointment::where('company_id', $this->config->company_id)
            ->whereIn('status', [Appointment::STATUS_CONFIRMED, Appointment::STATUS_PENDING])
            ->whereBetween('start_time', [$dayStart, $dayEnd])
            ->get();

        foreach ($existingAppointments as $appointment) {
            $busyTimes[] = [
                'start' => $appointment->start_time,
                'end' => $appointment->end_time,
            ];
        }

        // Generate available slots
        $slots = [];
        $slotDuration = $this->config->slot_duration;
        $bufferTime = $this->config->buffer_time;
        $current = $dayStart->copy();

        while ($current->copy()->addMinutes($slotDuration)->lte($dayEnd)) {
            $slotStart = $current->copy();
            $slotEnd = $current->copy()->addMinutes($slotDuration);

            // Check if slot conflicts with busy times
            $isAvailable = true;
            foreach ($busyTimes as $busy) {
                $busyStart = Carbon::parse($busy['start']);
                $busyEnd = Carbon::parse($busy['end'])->addMinutes($bufferTime);

                // Check for overlap (including buffer before)
                $slotStartWithBuffer = $slotStart->copy()->subMinutes($bufferTime);
                if ($slotStartWithBuffer->lt($busyEnd) && $slotEnd->gt($busyStart)) {
                    $isAvailable = false;
                    break;
                }
            }

            if ($isAvailable) {
                $slots[] = [
                    'start' => $slotStart->format('H:i'),
                    'end' => $slotEnd->format('H:i'),
                    'start_datetime' => $slotStart->toIso8601String(),
                    'end_datetime' => $slotEnd->toIso8601String(),
                ];
            }

            $current->addMinutes($slotDuration);
        }

        return $slots;
    }

    /**
     * Get available dates for the next N days
     */
    public function getAvailableDates(?int $days = null): array
    {
        $days = $days ?? $this->config->advance_booking_days;
        $availableDates = [];
        $startDate = now($this->config->timezone);

        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i);
            $slots = $this->getAvailableSlots($date->format('Y-m-d'));

            if (!empty($slots)) {
                $availableDates[] = [
                    'date' => $date->format('Y-m-d'),
                    'day_name' => $date->format('l'),
                    'formatted' => $date->format('D, M j'),
                    'slot_count' => count($slots),
                ];
            }
        }

        return $availableDates;
    }

    /**
     * Create a calendar event
     */
    public function createEvent(array $eventData): array
    {
        $event = [
            'summary' => $eventData['title'],
            'description' => $eventData['description'] ?? '',
            'start' => [
                'dateTime' => Carbon::parse($eventData['start_time'])->toRfc3339String(),
                'timeZone' => $this->config->timezone,
            ],
            'end' => [
                'dateTime' => Carbon::parse($eventData['end_time'])->toRfc3339String(),
                'timeZone' => $this->config->timezone,
            ],
            'attendees' => [],
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'email', 'minutes' => 60],
                    ['method' => 'popup', 'minutes' => 30],
                ],
            ],
        ];

        // Add attendee if email provided
        if (!empty($eventData['customer_email'])) {
            $event['attendees'][] = [
                'email' => $eventData['customer_email'],
                'displayName' => $eventData['customer_name'] ?? null,
            ];
        }

        $response = Http::withToken($this->accessToken)
            ->post(self::CALENDAR_API_URL . '/calendars/' . urlencode($this->config->calendar_id) . '/events', $event);

        if (!$response->successful()) {
            Log::error('Failed to create Google Calendar event', [
                'error' => $response->body(),
                'status' => $response->status(),
            ]);
            throw new \Exception('Failed to create calendar event: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Update a calendar event
     */
    public function updateEvent(string $eventId, array $eventData): array
    {
        $event = [];

        if (isset($eventData['title'])) {
            $event['summary'] = $eventData['title'];
        }

        if (isset($eventData['description'])) {
            $event['description'] = $eventData['description'];
        }

        if (isset($eventData['start_time'])) {
            $event['start'] = [
                'dateTime' => Carbon::parse($eventData['start_time'])->toRfc3339String(),
                'timeZone' => $this->config->timezone,
            ];
        }

        if (isset($eventData['end_time'])) {
            $event['end'] = [
                'dateTime' => Carbon::parse($eventData['end_time'])->toRfc3339String(),
                'timeZone' => $this->config->timezone,
            ];
        }

        $response = Http::withToken($this->accessToken)
            ->patch(self::CALENDAR_API_URL . '/calendars/' . urlencode($this->config->calendar_id) . '/events/' . $eventId, $event);

        if (!$response->successful()) {
            throw new \Exception('Failed to update calendar event: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Delete/Cancel a calendar event
     */
    public function deleteEvent(string $eventId): bool
    {
        $response = Http::withToken($this->accessToken)
            ->delete(self::CALENDAR_API_URL . '/calendars/' . urlencode($this->config->calendar_id) . '/events/' . $eventId);

        return $response->successful();
    }

    /**
     * Get busy times from Google Calendar
     */
    private function getBusyTimes(Carbon $start, Carbon $end): array
    {
        $response = Http::withToken($this->accessToken)
            ->post(self::CALENDAR_API_URL . '/freeBusy', [
                'timeMin' => $start->toRfc3339String(),
                'timeMax' => $end->toRfc3339String(),
                'timeZone' => $this->config->timezone,
                'items' => [
                    ['id' => $this->config->calendar_id],
                ],
            ]);

        if (!$response->successful()) {
            Log::warning('Failed to fetch busy times from Google Calendar', [
                'error' => $response->body(),
            ]);
            return [];
        }

        $data = $response->json();
        $busyTimes = [];

        $calendarBusy = $data['calendars'][$this->config->calendar_id]['busy'] ?? [];
        foreach ($calendarBusy as $busy) {
            $busyTimes[] = [
                'start' => Carbon::parse($busy['start']),
                'end' => Carbon::parse($busy['end']),
            ];
        }

        return $busyTimes;
    }

    /**
     * Get a valid access token, refreshing if needed
     */
    private function getValidAccessToken(): string
    {
        $credentials = $this->config->credentials;

        if (!$credentials || !isset($credentials['access_token'])) {
            throw new \Exception('Google Calendar not connected');
        }

        // Check if token is expired
        if (isset($credentials['expires_at']) && now()->gte($credentials['expires_at'])) {
            if (!isset($credentials['refresh_token'])) {
                throw new \Exception('No refresh token available. Please reconnect Google Calendar.');
            }

            $newTokens = $this->refreshAccessToken($credentials['refresh_token']);
            $this->config->update(['credentials' => $newTokens]);
            return $newTokens['access_token'];
        }

        return $credentials['access_token'];
    }

    /**
     * Refresh access token
     */
    private function refreshAccessToken(string $refreshToken): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to refresh Google access token');
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $refreshToken,
            'expires_at' => now()->addSeconds($data['expires_in'])->toIso8601String(),
            'token_type' => $data['token_type'],
        ];
    }
}
