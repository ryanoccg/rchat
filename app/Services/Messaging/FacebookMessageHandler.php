<?php

namespace App\Services\Messaging;

use App\Models\PlatformConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookMessageHandler extends AbstractMessageHandler
{
    protected const API_URL = 'https://graph.facebook.com/v18.0';

    public function parseIncomingMessage(Request $request): array
    {
        $payload = $request->all();
        $messages = [];

        if (($payload['object'] ?? '') !== 'page') {
            return ['messages' => []];
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $event) {
                if (isset($event['message'])) {
                    // Skip echo messages (messages sent by the page itself)
                    if (isset($event['message']['is_echo']) && $event['message']['is_echo']) {
                        Log::info('Skipping Facebook echo message', [
                            'sender_id' => $event['sender']['id'] ?? null,
                        ]);
                        continue;
                    }

                    $senderId = $event['sender']['id'];
                    
                    $messages[] = [
                        'sender_id' => $senderId,
                        'sender_name' => null, // Will be fetched when creating customer
                        'message_id' => $event['message']['mid'] ?? null,
                        'text' => $this->extractText($event['message']),
                        'type' => $this->determineMessageType($event['message']),
                        'media_urls' => $this->extractMediaInfo($event['message']),
                        'raw_message' => $event['message'],
                        'metadata' => [
                            'attachments' => $event['message']['attachments'] ?? [],
                            'timestamp' => $event['timestamp'] ?? null,
                            'raw_message' => $event['message'],
                        ],
                    ];
                }
            }
        }

        return ['messages' => $messages];
    }

    /**
     * Fetch user profile from Facebook Graph API
     */
    public function fetchUserProfile(PlatformConnection $connection, string $userId): ?array
    {
        $accessToken = $connection->credentials['page_access_token'] ?? null;

        if (!$accessToken) {
            Log::warning('Cannot fetch Facebook profile - missing access token', [
                'connection_id' => $connection->id,
            ]);
            return null;
        }

        try {
            $response = Http::get(self::API_URL . "/{$userId}", [
                'fields' => 'first_name,last_name,profile_pic',
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'first_name' => $data['first_name'] ?? null,
                    'last_name' => $data['last_name'] ?? null,
                    'name' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
                    'profile_pic' => $data['profile_pic'] ?? null,
                ];
            }

            Log::warning('Failed to fetch Facebook profile', [
                'user_id' => $userId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Error fetching Facebook profile', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function sendMessage(PlatformConnection $connection, string $recipientId, string $message, array $options = []): array
    {
        $accessToken = $connection->credentials['page_access_token'] ?? null;

        if (!$accessToken) {
            throw new \Exception('Missing page access token');
        }

        $payload = [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $message],
        ];

        if (isset($options['quick_replies'])) {
            $payload['message']['quick_replies'] = $options['quick_replies'];
        }

        $response = Http::post(self::API_URL . '/me/messages', array_merge($payload, [
            'access_token' => $accessToken,
        ]));

        if (!$response->successful()) {
            Log::error('Facebook send message failed', [
                'response' => $response->json(),
            ]);
            throw new \Exception('Failed to send Facebook message');
        }

        return $response->json();
    }

    /**
     * Send an image message to Facebook Messenger
     */
    public function sendImage(PlatformConnection $connection, string $recipientId, string $imageUrl, ?string $caption = null): array
    {
        $accessToken = $connection->credentials['page_access_token'] ?? null;

        if (!$accessToken) {
            throw new \Exception('Missing page access token');
        }

        $payload = [
            'recipient' => ['id' => $recipientId],
            'message' => [
                'attachment' => [
                    'type' => 'image',
                    'payload' => [
                        'url' => $imageUrl,
                        'is_reusable' => true,
                    ],
                ],
            ],
        ];

        $response = Http::post(self::API_URL . '/me/messages', array_merge($payload, [
            'access_token' => $accessToken,
        ]));

        if (!$response->successful()) {
            Log::error('Facebook send image failed', [
                'response' => $response->json(),
                'image_url' => $imageUrl,
            ]);
            throw new \Exception('Failed to send Facebook image');
        }

        return $response->json();
    }

    /**
     * Determine message type and extract text
     * Handles regular messages, quick replies, and attachments
     */
    /**
     * Determine message type and extract text
     * Handles regular messages, quick replies, and attachments
     */
    protected function determineMessageType(array $message): string
    {
        // Check if this is a quick reply message
        if (isset($message['quick_reply'])) {
            return 'quick_reply';
        }
        
        // If message has attachments, type is based on attachment type
        if (isset($message['attachments'])) {
            $attachment = $message['attachments'][0] ?? null;
            if ($attachment) {
                return match ($attachment['type'] ?? 'text') {
                    'image' => 'image',
                    'video' => 'video',
                    'audio' => 'audio',
                    'file' => 'file',
                    'location' => 'location',
                    'sticker' => 'sticker',
                    'fallthrough' => 'text',
                };
            }
        }
        
        // Default to text
        return 'text';
    }

    /**
     * Extract text from message
     * Handles regular text and quick reply messages
     */
    protected function extractText(array $message): string
    {
        // For quick replies, text is in quick_reply field
        if (isset($message['quick_reply'])) {
            return $message['quick_reply']['title'] ?? '';
        }
        
        // For regular messages, text is in text field
        return $message['text'] ?? '';
    }

    /**
     * Extract media information from Facebook message
     */
    protected function extractMediaInfo(array $message): array
    {
        $media = [];
        $seenUrls = []; // Track URLs to prevent duplicates
        $attachments = $message['attachments'] ?? [];

        foreach ($attachments as $attachment) {
            $type = $attachment['type'] ?? 'file';
            $payload = $attachment['payload'] ?? [];
            $url = $payload['url'] ?? null;

            // Skip if no URL or URL already processed (prevent duplicates)
            if (!$url || isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;

            switch ($type) {
                case 'image':
                    $media[] = [
                        'type' => 'image',
                        'url' => $url,
                        'mime_type' => 'image/jpeg',
                        'sticker_id' => $payload['sticker_id'] ?? null,
                    ];
                    break;

                case 'audio':
                    $media[] = [
                        'type' => 'audio',
                        'url' => $url,
                        'mime_type' => 'audio/mpeg',
                    ];
                    break;

                case 'video':
                    $media[] = [
                        'type' => 'video',
                        'url' => $url,
                        'mime_type' => 'video/mp4',
                    ];
                    break;

                case 'file':
                    $media[] = [
                        'type' => 'document',
                        'url' => $url,
                        'mime_type' => 'application/octet-stream',
                    ];
                    break;
            }
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
        return 'facebook';
    }
}
