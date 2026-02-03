<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessagingPlatform;
use App\Models\PlatformConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FacebookAuthController extends Controller
{
    /**
     * Facebook Graph API base URL
     */
    private const GRAPH_API_URL = 'https://graph.facebook.com/v18.0';

    /**
     * Facebook OAuth dialog URL
     */
    private const OAUTH_URL = 'https://www.facebook.com/v18.0/dialog/oauth';

    /**
     * Required permissions for Messenger integration
     */
    private const PERMISSIONS = [
        'pages_show_list',
        'pages_messaging',
        'pages_read_engagement',
        'pages_manage_metadata',
    ];

    /**
     * Get the OAuth authorization URL for Facebook
     */
    public function getAuthUrl(Request $request)
    {
        $appId = config('services.facebook.app_id');

        if (empty($appId)) {
            return response()->json([
                'error' => 'Facebook App not configured. Please contact administrator.',
            ], 500);
        }

        $user = $request->user();

        // Generate state token with embedded data (company_id + random token)
        // Format: base64(json({company_id, user_id, token, expires}))
        $stateData = [
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'token' => Str::random(32),
            'expires' => now()->addMinutes(30)->timestamp,
        ];
        $state = base64_encode(json_encode($stateData));

        // Store in cache for verification (avoids session issues with OAuth redirects)
        cache()->put('fb_oauth_' . $stateData['token'], $stateData, now()->addMinutes(30));

        $redirectUri = url('/api/auth/facebook/callback');

        $params = http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(',', self::PERMISSIONS),
            'state' => $state,
            'response_type' => 'code',
        ]);

        return response()->json([
            'url' => self::OAUTH_URL . '?' . $params,
            'state' => $state,
        ]);
    }

    /**
     * Handle OAuth callback from Facebook
     */
    public function handleCallback(Request $request)
    {
        $code = $request->get('code');
        $state = $request->get('state');
        $error = $request->get('error');

        // Handle user denied authorization
        if ($error) {
            return redirect('/platforms?error=' . urlencode($request->get('error_description', 'Authorization denied')));
        }

        // Decode and verify state token
        $stateData = json_decode(base64_decode($state), true);
        if (!$stateData || !isset($stateData['token'])) {
            return redirect('/platforms?error=' . urlencode('Invalid state token. Please try again.'));
        }

        // Verify from cache
        $cachedState = cache()->get('fb_oauth_' . $stateData['token']);
        if (!$cachedState || $cachedState['token'] !== $stateData['token']) {
            return redirect('/platforms?error=' . urlencode('State token expired or invalid. Please try again.'));
        }

        // Check expiration
        if (now()->timestamp > $stateData['expires']) {
            cache()->forget('fb_oauth_' . $stateData['token']);
            return redirect('/platforms?error=' . urlencode('Authorization expired. Please try again.'));
        }

        // Exchange code for access token
        $tokenResponse = $this->exchangeCodeForToken($code);

        if (isset($tokenResponse['error'])) {
            Log::error('Facebook OAuth token exchange failed', $tokenResponse);
            return redirect('/platforms?error=' . urlencode('Failed to get access token: ' . ($tokenResponse['error']['message'] ?? 'Unknown error')));
        }

        $userAccessToken = $tokenResponse['access_token'];

        // Get long-lived token
        $longLivedToken = $this->getLongLivedToken($userAccessToken);
        if (isset($longLivedToken['error'])) {
            Log::error('Facebook long-lived token exchange failed', $longLivedToken);
            // Continue with short-lived token if long-lived fails
            $longLivedToken = ['access_token' => $userAccessToken];
        }

        // Store token in cache with the state token as identifier
        $cacheKey = 'fb_token_' . $stateData['token'];
        cache()->put($cacheKey, [
            'access_token' => $longLivedToken['access_token'],
            'company_id' => $stateData['company_id'],
            'user_id' => $stateData['user_id'],
        ], now()->addMinutes(30));

        // Clean up state cache
        cache()->forget('fb_oauth_' . $stateData['token']);

        // Check if this was opened in a popup window (we'll handle this client-side)
        // Return an HTML page that notifies the parent window and closes itself
        $token = $stateData['token'];
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Facebook Authorization Complete</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; background: #f5f5f5; }
        .container { text-align: center; padding: 40px; background: white; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .icon { font-size: 48px; color: #22c55e; margin-bottom: 16px; }
        h1 { margin: 0 0 8px; color: #1a1a1a; font-size: 24px; }
        p { margin: 0; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">✓</div>
        <h1>Authorization Complete</h1>
        <p>Redirecting back to ChatHero...</p>
    </div>
    <script>
        // Redirect the parent window (or this window if not a popup)
        var targetUrl = '/platforms?facebook_auth=success&fb_token=' + encodeURIComponent('$token');
        if (window.opener) {
            window.opener.location.href = targetUrl;
            window.close();
        } else {
            window.location.href = targetUrl;
        }
    </script>
</body>
</html>
HTML;

        return response($html)->header('Content-Type', 'text/html');
    }

    /**
     * Get list of pages the user manages
     */
    public function getPages(Request $request)
    {
        $fbToken = $request->get('fb_token');

        // Get token from cache using the fb_token identifier
        $cachedData = null;
        if ($fbToken) {
            $cachedData = cache()->get('fb_token_' . $fbToken);
        }

        if (!$cachedData || !isset($cachedData['access_token'])) {
            return response()->json([
                'error' => 'No Facebook authorization found. Please connect again.',
            ], 401);
        }

        // Verify company ownership
        if ($cachedData['company_id'] !== $request->user()->company_id) {
            return response()->json([
                'error' => 'Unauthorized access.',
            ], 403);
        }

        $response = Http::get(self::GRAPH_API_URL . '/me/accounts', [
            'access_token' => $cachedData['access_token'],
            'fields' => 'id,name,access_token,picture{url},category,fan_count',
        ]);

        if (!$response->successful()) {
            Log::error('Facebook get pages failed', ['response' => $response->json()]);
            return response()->json([
                'error' => 'Failed to fetch pages. Please try connecting again.',
            ], 400);
        }

        $data = $response->json();
        $pages = collect($data['data'] ?? [])->map(function ($page) {
            return [
                'id' => $page['id'],
                'name' => $page['name'],
                'access_token' => $page['access_token'],
                'picture_url' => $page['picture']['data']['url'] ?? null,
                'category' => $page['category'] ?? null,
                'fan_count' => $page['fan_count'] ?? 0,
            ];
        });

        return response()->json([
            'pages' => $pages,
        ]);
    }

    /**
     * Connect a selected Facebook page
     */
    public function connectPage(Request $request)
    {
        $request->validate([
            'page_id' => 'required|string',
            'page_name' => 'required|string',
            'page_access_token' => 'required|string',
            'account_name' => 'nullable|string|max:255',
            'fb_token' => 'nullable|string',
        ]);

        $user = $request->user();
        $companyId = $user->company_id;

        // Get Facebook platform
        $platform = MessagingPlatform::where('slug', 'facebook')->first();

        if (!$platform) {
            return response()->json([
                'error' => 'Facebook platform not configured.',
            ], 500);
        }

        // Check if this page is already connected
        $existingConnection = PlatformConnection::where('company_id', $companyId)
            ->where('messaging_platform_id', $platform->id)
            ->whereJsonContains('credentials->page_id', $request->page_id)
            ->first();

        if ($existingConnection) {
            return response()->json([
                'error' => 'This page is already connected.',
            ], 400);
        }

        // Generate verify token and webhook token
        $verifyToken = Str::random(32);
        $webhookToken = Str::random(32);

        // Create platform connection
        $connection = PlatformConnection::create([
            'company_id' => $companyId,
            'messaging_platform_id' => $platform->id,
            'platform_account_name' => $request->account_name ?: $request->page_name,
            'credentials' => [
                'page_id' => $request->page_id,
                'page_access_token' => $request->page_access_token,
                'app_secret' => config('services.facebook.app_secret'),
                'verify_token' => $verifyToken,
            ],
            'webhook_config' => [
                'url' => url("/api/webhooks/facebook/{$webhookToken}"),
                'token' => $webhookToken,
                'verify_token' => $verifyToken,
            ],
            'is_active' => true,
            'connected_at' => now(),
        ]);

        // Clear cached token data
        if ($request->fb_token) {
            cache()->forget('fb_token_' . $request->fb_token);
        }

        // Subscribe the app to the page's webhook
        $this->subscribePageWebhook($request->page_id, $request->page_access_token);

        return response()->json([
            'message' => 'Facebook page connected successfully!',
            'connection' => $connection->load('messagingPlatform'),
            'webhook_url' => $connection->webhook_config['url'],
            'verify_token' => $verifyToken,
        ]);
    }

    /**
     * Exchange authorization code for access token
     */
    private function exchangeCodeForToken(string $code): array
    {
        $response = Http::get(self::GRAPH_API_URL . '/oauth/access_token', [
            'client_id' => config('services.facebook.app_id'),
            'client_secret' => config('services.facebook.app_secret'),
            'redirect_uri' => url('/api/auth/facebook/callback'),
            'code' => $code,
        ]);

        return $response->json();
    }

    /**
     * Exchange short-lived token for long-lived token
     */
    private function getLongLivedToken(string $shortLivedToken): array
    {
        $response = Http::get(self::GRAPH_API_URL . '/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.app_id'),
            'client_secret' => config('services.facebook.app_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        return $response->json();
    }

    /**
     * Subscribe app to page webhook events
     */
    private function subscribePageWebhook(string $pageId, string $pageAccessToken): bool
    {
        $response = Http::post(self::GRAPH_API_URL . "/{$pageId}/subscribed_apps", [
            'access_token' => $pageAccessToken,
            'subscribed_fields' => 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads',
        ]);

        if (!$response->successful()) {
            Log::warning('Failed to subscribe page to webhooks', [
                'page_id' => $pageId,
                'response' => $response->json(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Disconnect a Facebook page (revoke access)
     */
    public function disconnectPage(Request $request, PlatformConnection $connection)
    {
        // Verify ownership
        if ($connection->company_id !== $request->user()->company_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Optionally unsubscribe from webhooks
        $pageId = $connection->credentials['page_id'] ?? null;
        $pageToken = $connection->credentials['page_access_token'] ?? null;

        if ($pageId && $pageToken) {
            Http::delete(self::GRAPH_API_URL . "/{$pageId}/subscribed_apps", [
                'access_token' => $pageToken,
            ]);
        }

        $connection->delete();

        return response()->json([
            'message' => 'Facebook page disconnected successfully.',
        ]);
    }
}
