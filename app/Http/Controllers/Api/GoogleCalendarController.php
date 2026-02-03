<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarConfiguration;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleCalendarController extends Controller
{
    private const OAUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const CALENDAR_API_URL = 'https://www.googleapis.com/calendar/v3';

    private const SCOPES = [
        'https://www.googleapis.com/auth/calendar',
        'https://www.googleapis.com/auth/calendar.events',
    ];

    /**
     * Get the Google OAuth URL for calendar authorization
     */
    public function getAuthUrl(Request $request)
    {
        $clientId = config('services.google.client_id');

        if (!$clientId) {
            return response()->json([
                'message' => 'Google Calendar integration is not configured. Please contact support.',
            ], 400);
        }

        $user = $request->user();
        $companyId = $request->company_id;

        // Generate state token with embedded data (company_id + random token)
        $stateData = [
            'company_id' => $companyId,
            'user_id' => $user->id,
            'token' => Str::random(32),
            'expires' => now()->addMinutes(30)->timestamp,
        ];
        $state = base64_encode(json_encode($stateData));

        // Store in cache for verification (avoids session issues with OAuth redirects)
        cache()->put('google_oauth_' . $stateData['token'], $stateData, now()->addMinutes(30));

        $redirectUri = url('/api/auth/google/callback');

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent', // Force to get refresh token
            'state' => $state,
        ]);

        return response()->json([
            'url' => self::OAUTH_URL . '?' . $params,
        ]);
    }

    /**
     * Handle the OAuth callback from Google
     */
    public function handleCallback(Request $request)
    {
        $state = $request->input('state');
        $code = $request->input('code');
        $error = $request->input('error');

        // Check for errors
        if ($error) {
            Log::error('Google OAuth error', ['error' => $error]);
            return redirect('/calendar?error=' . urlencode('Google authorization was denied'));
        }

        // Decode and verify state token
        $stateData = json_decode(base64_decode($state), true);
        if (!$stateData || !isset($stateData['token'])) {
            return redirect('/calendar?error=' . urlencode('Invalid state token. Please try again.'));
        }

        // Verify from cache
        $cachedState = cache()->get('google_oauth_' . $stateData['token']);
        if (!$cachedState || $cachedState['token'] !== $stateData['token']) {
            return redirect('/calendar?error=' . urlencode('State token expired or invalid. Please try again.'));
        }

        // Check expiration
        if (now()->timestamp > $stateData['expires']) {
            cache()->forget('google_oauth_' . $stateData['token']);
            return redirect('/calendar?error=' . urlencode('Authorization expired. Please try again.'));
        }

        if (!$code) {
            return redirect('/calendar?error=' . urlencode('No authorization code received'));
        }

        try {
            // Exchange code for tokens
            $tokens = $this->exchangeCodeForTokens($code);

            // Store tokens in cache with the state token as identifier
            $cacheKey = 'google_token_' . $stateData['token'];
            cache()->put($cacheKey, [
                'tokens' => $tokens,
                'company_id' => $stateData['company_id'],
                'user_id' => $stateData['user_id'],
            ], now()->addMinutes(30));

            // Clean up state cache
            cache()->forget('google_oauth_' . $stateData['token']);

            return redirect('/calendar?google_connected=pending&google_token=' . urlencode($stateData['token']));
        } catch (\Exception $e) {
            Log::error('Google OAuth token exchange failed', ['error' => $e->getMessage()]);
            return redirect('/calendar?error=' . urlencode('Failed to connect to Google Calendar'));
        }
    }

    /**
     * Get the list of calendars for the authenticated user
     */
    public function getCalendars(Request $request)
    {
        $googleToken = $request->get('google_token');
        $companyId = $request->company_id;
        $tokens = null;

        // Check for tokens from cache using google_token identifier
        if ($googleToken) {
            $cacheKey = 'google_token_' . $googleToken;
            $cachedData = cache()->get($cacheKey);
            if ($cachedData && $cachedData['company_id'] === $companyId) {
                $tokens = $cachedData['tokens'];
            }
        }

        if (!$tokens) {
            // Try to get from existing configuration
            $config = CalendarConfiguration::where('company_id', $companyId)->first();
            if ($config && $config->credentials) {
                $tokens = $config->credentials;

                // Refresh if needed
                if ($this->isTokenExpired($tokens)) {
                    $tokens = $this->refreshAccessToken($tokens['refresh_token']);
                    $config->update(['credentials' => $tokens]);
                }
            }
        }

        if (!$tokens || !isset($tokens['access_token'])) {
            return response()->json([
                'message' => 'Not authenticated with Google. Please connect your account first.',
            ], 401);
        }

        try {
            $calendars = $this->fetchCalendars($tokens['access_token']);

            return response()->json([
                'calendars' => $calendars,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch Google calendars', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to fetch calendars: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Connect/Save the selected calendar
     */
    public function connectCalendar(Request $request)
    {
        $validated = $request->validate([
            'calendar_id' => 'required|string',
            'calendar_name' => 'required|string',
            'slot_duration' => 'nullable|integer|min:15|max:180',
            'buffer_time' => 'nullable|integer|min:0|max:60',
            'advance_booking_days' => 'nullable|integer|min:1|max:90',
            'min_notice_hours' => 'nullable|integer|min:1|max:168',
            'timezone' => 'nullable|string',
            'working_hours' => 'nullable|array',
            'booking_instructions' => 'nullable|string|max:1000',
            'google_token' => 'nullable|string',
        ]);

        $companyId = $request->company_id;
        $tokens = null;

        // Check for tokens from cache using google_token identifier
        if (!empty($validated['google_token'])) {
            $cachedData = cache()->get('google_token_' . $validated['google_token']);
            if ($cachedData && $cachedData['company_id'] === $companyId) {
                $tokens = $cachedData['tokens'];
            }
        }

        // If no cached tokens, check for existing config
        if (!$tokens) {
            $existingConfig = CalendarConfiguration::where('company_id', $companyId)->first();
            if ($existingConfig && $existingConfig->credentials) {
                $tokens = $existingConfig->credentials;
            }
        }

        if (!$tokens || !isset($tokens['access_token'])) {
            return response()->json([
                'message' => 'Not authenticated with Google. Please reconnect your account.',
            ], 401);
        }

        // Create or update configuration
        $config = CalendarConfiguration::updateOrCreate(
            ['company_id' => $companyId],
            [
                'provider' => 'google',
                'credentials' => $tokens,
                'calendar_id' => $validated['calendar_id'],
                'calendar_name' => $validated['calendar_name'],
                'is_connected' => true,
                'is_enabled' => true,
                'slot_duration' => $validated['slot_duration'] ?? 30,
                'buffer_time' => $validated['buffer_time'] ?? 15,
                'advance_booking_days' => $validated['advance_booking_days'] ?? 30,
                'min_notice_hours' => $validated['min_notice_hours'] ?? 24,
                'timezone' => $validated['timezone'] ?? 'Asia/Kuala_Lumpur',
                'working_hours' => $validated['working_hours'] ?? CalendarConfiguration::getDefaultWorkingHours(),
                'booking_instructions' => $validated['booking_instructions'] ?? null,
                'last_synced_at' => now(),
            ]
        );

        // Clear cached tokens
        if (!empty($validated['google_token'])) {
            cache()->forget('google_token_' . $validated['google_token']);
        }

        return response()->json([
            'message' => 'Google Calendar connected successfully',
            'data' => $config,
        ]);
    }

    /**
     * Get the current calendar configuration
     */
    public function getConfiguration(Request $request)
    {
        $companyId = $request->company_id;
        $config = CalendarConfiguration::where('company_id', $companyId)->first();

        if (!$config) {
            return response()->json([
                'data' => null,
            ]);
        }

        // Don't expose credentials
        $data = $config->toArray();
        unset($data['credentials']);

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Update calendar configuration (settings only)
     */
    public function updateConfiguration(Request $request)
    {
        $validated = $request->validate([
            'is_enabled' => 'sometimes|boolean',
            'slot_duration' => 'nullable|integer|min:15|max:180',
            'buffer_time' => 'nullable|integer|min:0|max:60',
            'advance_booking_days' => 'nullable|integer|min:1|max:90',
            'min_notice_hours' => 'nullable|integer|min:1|max:168',
            'timezone' => 'nullable|string',
            'working_hours' => 'nullable|array',
            'blocked_dates' => 'nullable|array',
            'booking_instructions' => 'nullable|string|max:1000',
        ]);

        $companyId = $request->company_id;
        $config = CalendarConfiguration::where('company_id', $companyId)->first();

        if (!$config) {
            return response()->json([
                'message' => 'Calendar not configured. Please connect first.',
            ], 404);
        }

        $config->update($validated);

        // Don't expose credentials
        $data = $config->toArray();
        unset($data['credentials']);

        return response()->json([
            'message' => 'Calendar settings updated successfully',
            'data' => $data,
        ]);
    }

    /**
     * Disconnect Google Calendar
     */
    public function disconnect(Request $request)
    {
        $companyId = $request->company_id;
        $config = CalendarConfiguration::where('company_id', $companyId)->first();

        if ($config) {
            // Optionally revoke token (best practice)
            if ($config->credentials && isset($config->credentials['access_token'])) {
                try {
                    $this->revokeToken($config->credentials['access_token']);
                } catch (\Exception $e) {
                    Log::warning('Failed to revoke Google token', ['error' => $e->getMessage()]);
                }
            }

            $config->update([
                'credentials' => null,
                'is_connected' => false,
                'calendar_id' => null,
                'calendar_name' => null,
            ]);
        }

        return response()->json([
            'message' => 'Google Calendar disconnected successfully',
        ]);
    }

    /**
     * Get available time slots
     */
    public function getAvailableSlots(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date|after_or_equal:today',
        ]);

        $companyId = $request->company_id;
        $config = CalendarConfiguration::where('company_id', $companyId)->first();

        if (!$config || !$config->is_connected || !$config->is_enabled) {
            return response()->json([
                'message' => 'Calendar is not configured or enabled',
            ], 400);
        }

        try {
            $service = new GoogleCalendarService($config);
            $slots = $service->getAvailableSlots($validated['date']);

            return response()->json([
                'date' => $validated['date'],
                'slots' => $slots,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get available slots', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to get available slots: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Exchange authorization code for tokens
     */
    private function exchangeCodeForTokens(string $code): array
    {
        $redirectUri = url('/api/auth/google/callback');

        $response = \Http::asForm()
            ->timeout(30)
            ->retry(2, 1000)
            ->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to exchange code for tokens: ' . $response->body());
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds($data['expires_in'])->toIso8601String(),
            'token_type' => $data['token_type'],
        ];
    }

    /**
     * Refresh access token using refresh token
     */
    private function refreshAccessToken(string $refreshToken): array
    {
        $response = \Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to refresh access token: ' . $response->body());
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $refreshToken, // Keep the same refresh token
            'expires_at' => now()->addSeconds($data['expires_in'])->toIso8601String(),
            'token_type' => $data['token_type'],
        ];
    }

    /**
     * Check if token is expired
     */
    private function isTokenExpired(array $tokens): bool
    {
        if (!isset($tokens['expires_at'])) {
            return true;
        }

        return now()->gte($tokens['expires_at']);
    }

    /**
     * Revoke access token
     */
    private function revokeToken(string $token): void
    {
        \Http::post('https://oauth2.googleapis.com/revoke', [
            'token' => $token,
        ]);
    }

    /**
     * Fetch calendars from Google
     */
    private function fetchCalendars(string $accessToken): array
    {
        $response = \Http::withToken($accessToken)
            ->get(self::CALENDAR_API_URL . '/users/me/calendarList');

        if (!$response->successful()) {
            throw new \Exception('Failed to fetch calendars: ' . $response->body());
        }

        $data = $response->json();
        $calendars = [];

        foreach ($data['items'] ?? [] as $calendar) {
            // Only include calendars the user can write to
            if (in_array($calendar['accessRole'], ['owner', 'writer'])) {
                $calendars[] = [
                    'id' => $calendar['id'],
                    'name' => $calendar['summary'],
                    'description' => $calendar['description'] ?? null,
                    'primary' => $calendar['primary'] ?? false,
                    'backgroundColor' => $calendar['backgroundColor'] ?? null,
                ];
            }
        }

        return $calendars;
    }
}
