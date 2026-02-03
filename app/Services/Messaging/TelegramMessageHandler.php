<?php

namespace App\Services\Messaging;

use App\Models\PlatformConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramMessageHandler extends AbstractMessageHandler
{
    protected const API_URL = 'https://api.telegram.org/bot';

    public function parseIncomingMessage(Request $request): array
    {
        $payload = $request->all();
        $messages = [];

        // Handle regular messages
        if (isset($payload['message'])) {
            $msg = $payload['message'];
            $messages[] = $this->parseMessage($msg);
        }

        // Handle edited messages
        if (isset($payload['edited_message'])) {
            $msg = $payload['edited_message'];
            $parsed = $this->parseMessage($msg);
            $parsed['metadata']['edited'] = true;
            $messages[] = $parsed;
        }

        // Handle channel posts
        if (isset($payload['channel_post'])) {
            $msg = $payload['channel_post'];
            $messages[] = $this->parseMessage($msg);
        }

        return ['messages' => $messages];
    }

    public function sendMessage(PlatformConnection $connection, string $recipientId, string $message, array $options = []): array
    {
        $botToken = $connection->credentials['bot_token'] ?? null;

        if (!$botToken) {
            throw new \Exception('Missing Telegram bot token');
        }

        $payload = [
            'chat_id' => $recipientId,
            'text' => $message,
            'parse_mode' => $options['parse_mode'] ?? 'HTML',
        ];

        if (isset($options['reply_markup'])) {
            $payload['reply_markup'] = json_encode($options['reply_markup']);
        }

        $response = Http::post(self::API_URL . $botToken . '/sendMessage', $payload);

        if (!$response->successful()) {
            Log::error('Telegram send message failed', [
                'response' => $response->json(),
            ]);
            throw new \Exception('Failed to send Telegram message');
        }

        return $response->json();
    }

    /**
     * Send an image message to Telegram
     */
    public function sendImage(PlatformConnection $connection, string $recipientId, string $imageUrl, ?string $caption = null): array
    {
        $botToken = $connection->credentials['bot_token'] ?? null;

        if (!$botToken) {
            throw new \Exception('Missing Telegram bot token');
        }

        $payload = [
            'chat_id' => $recipientId,
            'photo' => $imageUrl,
        ];

        if ($caption) {
            $payload['caption'] = $caption;
            $payload['parse_mode'] = 'HTML';
        }

        $response = Http::post(self::API_URL . $botToken . '/sendPhoto', $payload);

        if (!$response->successful()) {
            Log::error('Telegram send image failed', [
                'response' => $response->json(),
                'image_url' => $imageUrl,
            ]);
            throw new \Exception('Failed to send Telegram image');
        }

        return $response->json();
    }

    protected function parseMessage(array $msg): array
    {
        $from = $msg['from'] ?? [];
        $chat = $msg['chat'] ?? [];

        return [
            'sender_id' => (string)($from['id'] ?? $chat['id'] ?? ''),
            'sender_name' => trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')),
            'message_id' => (string)($msg['message_id'] ?? null),
            'text' => $this->extractText($msg),
            'type' => $this->determineMessageType($msg),
            'media_urls' => $this->extractMediaInfo($msg),
            'raw_message' => $msg,
            'metadata' => [
                'chat_id' => $chat['id'] ?? null,
                'chat_type' => $chat['type'] ?? null,
                'username' => $from['username'] ?? null,
                'date' => $msg['date'] ?? null,
                'raw_message' => $msg,
            ],
            'sender_profile' => [
                'username' => $from['username'] ?? null,
                'language_code' => $from['language_code'] ?? null,
            ],
        ];
    }

    protected function extractText(array $msg): string
    {
        if (isset($msg['text'])) {
            return $msg['text'];
        }

        if (isset($msg['caption'])) {
            return $msg['caption'];
        }

        if (isset($msg['sticker'])) {
            return '[Sticker] ' . ($msg['sticker']['emoji'] ?? '');
        }

        if (isset($msg['contact'])) {
            return '[Contact] ' . ($msg['contact']['first_name'] ?? '');
        }

        if (isset($msg['location'])) {
            return '[Location]';
        }

        return '';
    }

    protected function determineMessageType(array $msg): string
    {
        if (isset($msg['photo'])) return 'image';
        if (isset($msg['video'])) return 'video';
        if (isset($msg['audio'])) return 'audio';
        if (isset($msg['voice'])) return 'audio';
        if (isset($msg['document'])) return 'file';
        if (isset($msg['sticker'])) return 'sticker';
        if (isset($msg['location'])) return 'location';
        if (isset($msg['contact'])) return 'contact';

        return 'text';
    }

    /**
     * Extract media information from Telegram message
     */
    protected function extractMediaInfo(array $msg): array
    {
        $media = [];

        // Photo (Telegram sends array of sizes, use largest)
        if (isset($msg['photo']) && is_array($msg['photo'])) {
            $photo = end($msg['photo']);
            $media[] = [
                'type' => 'image',
                'file_id' => $photo['file_id'] ?? null,
                'file_unique_id' => $photo['file_unique_id'] ?? null,
                'mime_type' => 'image/jpeg',
                'file_size' => $photo['file_size'] ?? null,
            ];
        }

        // Voice message
        if (isset($msg['voice'])) {
            $media[] = [
                'type' => 'audio',
                'file_id' => $msg['voice']['file_id'] ?? null,
                'file_unique_id' => $msg['voice']['file_unique_id'] ?? null,
                'mime_type' => $msg['voice']['mime_type'] ?? 'audio/ogg',
                'duration' => $msg['voice']['duration'] ?? null,
            ];
        }

        // Audio message
        if (isset($msg['audio'])) {
            $media[] = [
                'type' => 'audio',
                'file_id' => $msg['audio']['file_id'] ?? null,
                'file_unique_id' => $msg['audio']['file_unique_id'] ?? null,
                'mime_type' => $msg['audio']['mime_type'] ?? 'audio/mpeg',
                'duration' => $msg['audio']['duration'] ?? null,
                'title' => $msg['audio']['title'] ?? null,
            ];
        }

        // Video message
        if (isset($msg['video'])) {
            $media[] = [
                'type' => 'video',
                'file_id' => $msg['video']['file_id'] ?? null,
                'file_unique_id' => $msg['video']['file_unique_id'] ?? null,
                'mime_type' => $msg['video']['mime_type'] ?? 'video/mp4',
                'duration' => $msg['video']['duration'] ?? null,
            ];
        }

        // Video note (circular video)
        if (isset($msg['video_note'])) {
            $media[] = [
                'type' => 'video',
                'file_id' => $msg['video_note']['file_id'] ?? null,
                'file_unique_id' => $msg['video_note']['file_unique_id'] ?? null,
                'mime_type' => 'video/mp4',
                'duration' => $msg['video_note']['duration'] ?? null,
            ];
        }

        // Document
        if (isset($msg['document'])) {
            $media[] = [
                'type' => 'document',
                'file_id' => $msg['document']['file_id'] ?? null,
                'file_unique_id' => $msg['document']['file_unique_id'] ?? null,
                'mime_type' => $msg['document']['mime_type'] ?? 'application/octet-stream',
                'file_name' => $msg['document']['file_name'] ?? null,
            ];
        }

        return $media;
    }

    /**
     * Extract media URLs from message data
     */
    protected function extractMediaUrls(array $messageData): array
    {
        return $messageData['media_urls'] ?? [];
    }

    /**
     * Get platform identifier
     */
    protected function getPlatformIdentifier(): ?string
    {
        return 'telegram';
    }
}
