<?php

namespace App\Services\Messaging;

use App\Models\PlatformConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LineMessageHandler extends AbstractMessageHandler
{
    protected const API_URL = 'https://api.line.me/v2/bot';

    public function parseIncomingMessage(Request $request): array
    {
        $payload = $request->all();
        $messages = [];

        foreach ($payload['events'] ?? [] as $event) {
            if (($event['type'] ?? '') !== 'message') {
                continue;
            }

            $message = $event['message'] ?? [];
            $source = $event['source'] ?? [];

            $messages[] = [
                'sender_id' => $source['userId'] ?? '',
                'sender_name' => null,
                'message_id' => $message['id'] ?? null,
                'text' => $this->extractText($message),
                'type' => $message['type'] ?? 'text',
                'metadata' => [
                    'reply_token' => $event['replyToken'] ?? null,
                    'timestamp' => $event['timestamp'] ?? null,
                    'source_type' => $source['type'] ?? null,
                    'group_id' => $source['groupId'] ?? null,
                    'room_id' => $source['roomId'] ?? null,
                ],
            ];
        }

        return ['messages' => $messages];
    }

    public function sendMessage(PlatformConnection $connection, string $recipientId, string $message, array $options = []): array
    {
        $channelAccessToken = $connection->credentials['channel_access_token'] ?? null;

        if (!$channelAccessToken) {
            throw new \Exception('Missing LINE channel access token');
        }

        // Use reply if reply_token is provided, otherwise push
        if (isset($options['reply_token'])) {
            return $this->replyMessage($channelAccessToken, $options['reply_token'], $message);
        }

        return $this->pushMessage($channelAccessToken, $recipientId, $message);
    }

    /**
     * Send an image message to LINE
     */
    public function sendImage(PlatformConnection $connection, string $recipientId, string $imageUrl, ?string $caption = null): array
    {
        $channelAccessToken = $connection->credentials['channel_access_token'] ?? null;

        if (!$channelAccessToken) {
            throw new \Exception('Missing LINE channel access token');
        }

        $payload = [
            'to' => $recipientId,
            'messages' => [
                [
                    'type' => 'image',
                    'originalContentUrl' => $imageUrl,
                    'previewImageUrl' => $imageUrl, // Use same URL for preview
                ],
            ],
        ];

        $response = Http::withToken($channelAccessToken)
            ->post(self::API_URL . '/message/push', $payload);

        if (!$response->successful()) {
            Log::error('LINE send image failed', [
                'response' => $response->json(),
                'image_url' => $imageUrl,
            ]);
            throw new \Exception('Failed to send LINE image');
        }

        return $response->json() ?: ['status' => 'ok'];
    }

    protected function replyMessage(string $token, string $replyToken, string $message): array
    {
        $payload = [
            'replyToken' => $replyToken,
            'messages' => [
                ['type' => 'text', 'text' => $message],
            ],
        ];

        $response = Http::withToken($token)
            ->post(self::API_URL . '/message/reply', $payload);

        if (!$response->successful()) {
            Log::error('LINE reply message failed', [
                'response' => $response->json(),
            ]);
            throw new \Exception('Failed to reply LINE message');
        }

        return $response->json() ?: ['status' => 'ok'];
    }

    protected function pushMessage(string $token, string $userId, string $message): array
    {
        $payload = [
            'to' => $userId,
            'messages' => [
                ['type' => 'text', 'text' => $message],
            ],
        ];

        $response = Http::withToken($token)
            ->post(self::API_URL . '/message/push', $payload);

        if (!$response->successful()) {
            Log::error('LINE push message failed', [
                'response' => $response->json(),
            ]);
            throw new \Exception('Failed to push LINE message');
        }

        return $response->json() ?: ['status' => 'ok'];
    }

    protected function extractText(array $message): string
    {
        return match ($message['type'] ?? 'text') {
            'text' => $message['text'] ?? '',
            'image' => '[Image]',
            'video' => '[Video]',
            'audio' => '[Audio]',
            'file' => '[File] ' . ($message['fileName'] ?? ''),
            'location' => '[Location] ' . ($message['title'] ?? $message['address'] ?? ''),
            'sticker' => '[Sticker]',
            default => '',
        };
    }

    /**
     * Fetch user profile from LINE API
     */
    public function fetchUserProfile(PlatformConnection $connection, string $userId): ?array
    {
        $channelAccessToken = $connection->credentials['channel_access_token'] ?? null;

        if (!$channelAccessToken) {
            Log::warning('Cannot fetch LINE profile - missing channel access token', [
                'connection_id' => $connection->id,
            ]);
            return null;
        }

        try {
            $response = Http::withToken($channelAccessToken)
                ->get(self::API_URL . "/profile/{$userId}");

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'name' => $data['displayName'] ?? null,
                    'profile_pic' => $data['pictureUrl'] ?? null,
                    'status_message' => $data['statusMessage'] ?? null,
                    'language' => $data['language'] ?? null,
                ];
            }

            Log::warning('LINE profile fetch failed', [
                'user_id' => $userId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('LINE profile fetch error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the platform identifier for this handler
     */
    protected function getPlatformIdentifier(): ?string
    {
        return 'line';
    }
}
