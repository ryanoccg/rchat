<?php

namespace App\Services\Messaging;

use App\Models\PlatformConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppMessageHandler extends AbstractMessageHandler
{
    protected const API_URL = 'https://graph.facebook.com/v18.0';

    public function parseIncomingMessage(Request $request): array
    {
        $payload = $request->all();
        $messages = [];

        if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
            return ['messages' => []];
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];

                foreach ($value['messages'] ?? [] as $msg) {
                    $contact = collect($value['contacts'] ?? [])->firstWhere('wa_id', $msg['from']);

                    $messages[] = [
                        'sender_id' => $msg['from'],
                        'sender_name' => $contact['profile']['name'] ?? null,
                        'message_id' => $msg['id'] ?? null,
                        'text' => $this->extractText($msg),
                        'type' => $msg['type'] ?? 'text',
                        'media_urls' => $this->extractMediaInfo($msg),
                        'raw_message' => $msg,
                        'metadata' => [
                            'timestamp' => $msg['timestamp'] ?? null,
                            'context' => $msg['context'] ?? null,
                            'raw_message' => $msg,
                        ],
                    ];
                }
            }
        }

        return ['messages' => $messages];
    }

    public function sendMessage(PlatformConnection $connection, string $recipientId, string $message, array $options = []): array
    {
        $phoneNumberId = $connection->credentials['phone_number_id'] ?? null;
        $accessToken = $connection->credentials['access_token'] ?? null;

        if (!$phoneNumberId || !$accessToken) {
            throw new \Exception('Missing WhatsApp credentials');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipientId,
            'type' => 'text',
            'text' => ['body' => $message],
        ];

        $response = Http::withToken($accessToken)
            ->post(self::API_URL . "/{$phoneNumberId}/messages", $payload);

        if (!$response->successful()) {
            Log::error('WhatsApp send message failed', [
                'response' => $response->json(),
            ]);
            throw new \Exception('Failed to send WhatsApp message');
        }

        return $response->json();
    }

    /**
     * Send an image message to WhatsApp
     */
    public function sendImage(PlatformConnection $connection, string $recipientId, string $imageUrl, ?string $caption = null): array
    {
        $phoneNumberId = $connection->credentials['phone_number_id'] ?? null;
        $accessToken = $connection->credentials['access_token'] ?? null;
        
        if (!$phoneNumberId || !$accessToken) {
            throw new \Exception('Missing WhatsApp credentials');
        }

        // First, try to upload image to WhatsApp Media API to get media ID
        $mediaId = $this->uploadMedia($imageUrl, $accessToken, $phoneNumberId);

        if (!$mediaId) {
            // Fallback to direct link if upload fails
            Log::warning('WhatsApp media upload failed, falling back to link', [
                'image_url' => $imageUrl,
            ]);
            return $this->sendImageByLink($phoneNumberId, $accessToken, $recipientId, $imageUrl, $caption);
        }

        // Send message using media ID
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipientId,
            'type' => 'image',
            'image' => [
                'id' => $mediaId,
            ],
        ];

        if ($caption) {
            $payload['image']['caption'] = $caption;
        }

        $response = Http::withToken($accessToken)
            ->post(self::API_URL . "/{$phoneNumberId}/messages", $payload);

        if (!$response->successful()) {
            Log::error('WhatsApp send image failed', [
                'response' => $response->json(),
                'media_id' => $mediaId,
                'image_url' => $imageUrl,
            ]);
            throw new \Exception('Failed to send WhatsApp image');
        }

        Log::info('WhatsApp image sent successfully', [
            'media_id' => $mediaId,
            'recipient' => $recipientId,
        ]);

        return $response->json();
    }

    /**
     * Upload media to WhatsApp Media API
     * Returns media ID on success, null on failure
     */
    protected function uploadMedia(string $imageUrl, string $accessToken, string $phoneNumberId): ?string
    {
        try {
            // Download image content
            $imageResponse = Http::timeout(30)->get($imageUrl);
            
            if (!$imageResponse->successful()) {
                Log::warning('Failed to download image for WhatsApp upload', [
                    'image_url' => $imageUrl,
                    'status' => $imageResponse->status(),
                ]);
                return null;
            }

            $imageContent = $imageResponse->body();
            $mimeType = $imageResponse->header('Content-Type') ?? 'image/jpeg';

            // Upload to WhatsApp Media API
            $uploadUrl = self::API_URL . "/{$phoneNumberId}/media";
            
            $uploadResponse = Http::withToken($accessToken)
                ->attach('file', $imageContent, 'image.jpg', ['Content-Type' => $mimeType])
                ->timeout(60)
                ->post($uploadUrl);

            if (!$uploadResponse->successful()) {
                Log::warning('WhatsApp media upload failed', [
                    'image_url' => $imageUrl,
                    'response' => $uploadResponse->json(),
                ]);
                return null;
            }

            $mediaId = $uploadResponse->json('id');

            if ($mediaId) {
                Log::info('WhatsApp media uploaded successfully', [
                    'media_id' => $mediaId,
                    'image_url' => $imageUrl,
                ]);
            }

            return $mediaId;
        } catch (\Exception $e) {
            Log::error('WhatsApp media upload error', [
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Send image by direct link (fallback method)
     */
    protected function sendImageByLink(string $phoneNumberId, string $accessToken, string $recipientId, string $imageUrl, ?string $caption = null): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipientId,
            'type' => 'image',
            'image' => [
                'link' => $imageUrl,
            ],
        ];

        if ($caption) {
            $payload['image']['caption'] = $caption;
        }

        $response = Http::withToken($accessToken)
            ->post(self::API_URL . "/{$phoneNumberId}/messages", $payload);

        if (!$response->successful()) {
            Log::error('WhatsApp send image by link failed', [
                'response' => $response->json(),
                'image_url' => $imageUrl,
            ]);
            throw new \Exception('Failed to send WhatsApp image by link');
        }

        return $response->json();
    }

    protected function extractText(array $msg): string
    {
        return match ($msg['type'] ?? 'text') {
            'text' => $msg['text']['body'] ?? '',
            'image' => '[Image]' . ($msg['image']['caption'] ?? ''),
            'video' => '[Video]' . ($msg['video']['caption'] ?? ''),
            'audio' => '[Audio]',
            'document' => '[Document] ' . ($msg['document']['filename'] ?? ''),
            'location' => '[Location]',
            'contacts' => '[Contact]',
            'button' => $msg['button']['text'] ?? '',
            'interactive' => $msg['interactive']['button_reply']['title'] ?? $msg['interactive']['list_reply']['title'] ?? '',
            default => '',
        };
    }

    /**
     * Extract media information from WhatsApp message
     */
    protected function extractMediaInfo(array $msg): array
    {
        $media = [];
        $type = $msg['type'] ?? 'text';

        if ($type === 'image' && isset($msg['image'])) {
            $media[] = [
                'type' => 'image',
                'media_id' => $msg['image']['id'] ?? null,
                'mime_type' => $msg['image']['mime_type'] ?? 'image/jpeg',
                'sha256' => $msg['image']['sha256'] ?? null,
                'caption' => $msg['image']['caption'] ?? null,
            ];
        }

        if ($type === 'audio' && isset($msg['audio'])) {
            $media[] = [
                'type' => 'audio',
                'media_id' => $msg['audio']['id'] ?? null,
                'mime_type' => $msg['audio']['mime_type'] ?? 'audio/ogg',
            ];
        }

        if ($type === 'voice' && isset($msg['voice'])) {
            $media[] = [
                'type' => 'audio',
                'media_id' => $msg['voice']['id'] ?? null,
                'mime_type' => $msg['voice']['mime_type'] ?? 'audio/ogg; codecs=opus',
            ];
        }

        if ($type === 'video' && isset($msg['video'])) {
            $media[] = [
                'type' => 'video',
                'media_id' => $msg['video']['id'] ?? null,
                'mime_type' => $msg['video']['mime_type'] ?? 'video/mp4',
                'caption' => $msg['video']['caption'] ?? null,
            ];
        }

        if ($type === 'document' && isset($msg['document'])) {
            $media[] = [
                'type' => 'document',
                'media_id' => $msg['document']['id'] ?? null,
                'mime_type' => $msg['document']['mime_type'] ?? 'application/octet-stream',
                'filename' => $msg['document']['filename'] ?? null,
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
        return 'whatsapp';
    }
}
