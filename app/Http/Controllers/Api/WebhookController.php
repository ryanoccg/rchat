<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\PlatformConnection;
use App\Services\Messaging\MessageHandlerFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function verify(Request $request, string $platform, string $token)
    {
        $connection = $this->findConnectionByToken($platform, $token);

        if (!$connection) {
            return response('Invalid webhook token', 403);
        }

        // Platform-specific verification
        return match ($platform) {
            'facebook' => $this->verifyFacebook($request, $connection),
            'whatsapp' => $this->verifyWhatsApp($request, $connection),
            'telegram' => $this->verifyTelegram($request, $connection),
            'line' => $this->verifyLine($request, $connection),
            default => response('Unsupported platform', 400),
        };
    }

    public function handle(Request $request, string $platform, string $token)
    {
        $connection = $this->findConnectionByToken($platform, $token);

        if (!$connection) {
            Log::warning("Webhook received with invalid token", [
                'platform' => $platform,
                'token' => substr($token, 0, 8) . '...',
            ]);
            return response('Invalid webhook token', 403);
        }

        // Verify request signature for Facebook
        if ($platform === 'facebook' && !$this->verifyFacebookSignature($request, $connection)) {
            Log::warning("Facebook webhook signature verification failed", [
                'connection_id' => $connection->id,
            ]);
            return response('Invalid signature', 403);
        }

        if (!$connection->is_active) {
            Log::info("Webhook received for inactive connection", [
                'connection_id' => $connection->id,
            ]);
            return response('Connection inactive', 200);
        }

        try {
            $handler = MessageHandlerFactory::create($platform);
            $result = $handler->handleIncoming($request, $connection);

            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            Log::error("Webhook handling failed", [
                'platform' => $platform,
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    protected function findConnectionByToken(string $platform, string $token): ?PlatformConnection
    {
        return PlatformConnection::whereHas('messagingPlatform', function ($query) use ($platform) {
            $query->where('slug', $platform);
        })
            ->whereJsonContains('credentials', ['webhook_token' => $token])
            ->first();
    }

    protected function verifyFacebook(Request $request, PlatformConnection $connection)
    {
        $mode = $request->query('hub_mode');
        $verifyToken = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expectedToken = $connection->credentials['verify_token'] ?? null;

        Log::info('Facebook webhook verification attempt', [
            'mode' => $mode,
            'verify_token_received' => $verifyToken,
            'verify_token_expected' => $expectedToken,
            'challenge' => $challenge,
            'connection_id' => $connection->id,
            'tokens_match' => $verifyToken === $expectedToken,
        ]);

        if ($mode === 'subscribe' && $verifyToken === $expectedToken) {
            Log::info('Facebook webhook verification successful', [
                'challenge' => $challenge,
                'response_type' => 'text/plain'
            ]);
            return response($challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        Log::warning('Facebook webhook verification failed', [
            'mode' => $mode,
            'tokens_match' => $verifyToken === $expectedToken,
        ]);

        return response('Verification failed', 403);
    }

    protected function verifyWhatsApp(Request $request, PlatformConnection $connection)
    {
        // PHP converts dots to underscores in query parameter names,
        // so hub.mode becomes hub_mode in $_GET
        $mode = $request->query('hub_mode');
        $verifyToken = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expectedToken = $connection->credentials['webhook_verify_token']
            ?? $connection->credentials['verify_token']
            ?? null;

        Log::info('WhatsApp webhook verification attempt', [
            'mode' => $mode,
            'verify_token_received' => $verifyToken,
            'verify_token_expected' => $expectedToken,
            'challenge' => $challenge,
            'connection_id' => $connection->id,
            'tokens_match' => $verifyToken === $expectedToken,
            'all_query_params' => array_keys($request->query()),
        ]);

        if ($mode === 'subscribe' && $verifyToken === $expectedToken) {
            Log::info('WhatsApp webhook verification successful', [
                'challenge' => $challenge,
            ]);
            return response($challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verification failed', [
            'mode' => $mode,
            'tokens_match' => $verifyToken === $expectedToken,
        ]);

        return response('Verification failed', 403);
    }

    protected function verifyTelegram(Request $request, PlatformConnection $connection)
    {
        // Telegram doesn't require verification, just acknowledge
        // But verify the connection has proper webhook setup
        $expectedToken = $connection->credentials['webhook_token'] ?? null;
        
        if ($expectedToken) {
            Log::info('Telegram webhook verified', [
                'connection_id' => $connection->id,
                'token_configured' => true,
            ]);
        } else {
            Log::warning('Telegram connection missing webhook_token in credentials', [
                'connection_id' => $connection->id,
            ]);
        }
        
        return response('OK', 200);
    }

    protected function verifyLine(Request $request, PlatformConnection $connection)
    {
        // LINE doesn't require verification, just acknowledge
        return response('OK', 200);
    }

    /**
     * Verify Facebook request signature using app_secret
     */
    protected function verifyFacebookSignature(Request $request, PlatformConnection $connection): bool
    {
        $appSecret = $connection->credentials['app_secret'] ?? null;
        
        if (!$appSecret) {
            Log::warning('Facebook app_secret not configured', [
                'connection_id' => $connection->id,
            ]);
            return false;
        }

        $signature = $request->header('X-Hub-Signature-256');
        
        if (!$signature) {
            // Try the older SHA1 signature
            $signature = $request->header('X-Hub-Signature');
            if (!$signature) {
                Log::warning('No signature header found in Facebook webhook request');
                return false;
            }
            
            // SHA1 signature format: sha1=<signature>
            [$algorithm, $hash] = explode('=', $signature, 2);
            $expectedHash = hash_hmac('sha1', $request->getContent(), $appSecret);
        } else {
            // SHA256 signature format: sha256=<signature>
            [$algorithm, $hash] = explode('=', $signature, 2);
            $expectedHash = hash_hmac('sha256', $request->getContent(), $appSecret);
        }

        $isValid = hash_equals($expectedHash, $hash);
        
        if (!$isValid) {
            Log::warning('Facebook signature verification failed', [
                'connection_id' => $connection->id,
                'received_signature' => substr($hash, 0, 10) . '...',
                'expected_signature' => substr($expectedHash, 0, 10) . '...',
            ]);
        }

        return $isValid;
    }
}
