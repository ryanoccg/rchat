<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\PlatformConnection;
use App\Services\Media\MediaStorageService;
use App\Services\Media\ProfilePhotoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

abstract class AbstractMessageHandler implements MessageHandlerInterface
{
    public function handleIncoming(Request $request, PlatformConnection $connection): array
    {
        $parsed = $this->parseIncomingMessage($request);

        if (empty($parsed['messages'])) {
            return ['status' => 'no_messages'];
        }

        $results = [];

        foreach ($parsed['messages'] as $messageData) {
            $result = $this->processMessage($connection, $messageData);
            $results[] = $result;
        }

        return ['status' => 'processed', 'results' => $results];
    }

    protected function processMessage(PlatformConnection $connection, array $messageData): array
    {
        Log::info('Processing incoming message', [
            'connection_id' => $connection->id,
            'platform' => $connection->messagingPlatform->slug,
            'sender_id' => $messageData['sender_id'] ?? null,
            'has_text' => !empty($messageData['text']),
            'platform_message_id' => $messageData['message_id'] ?? null,
        ]);

        $customer = $this->findOrCreateCustomer($connection, $messageData);
        $conversation = $this->findOrCreateConversation($connection, $customer);

        // Check if message already exists (prevent duplicates from webhook retries)
        $platformMessageId = $messageData['message_id'] ?? null;
        if ($platformMessageId) {
            $existingMessage = Message::where('conversation_id', $conversation->id)
                ->where('platform_message_id', $platformMessageId)
                ->first();
            
            if ($existingMessage) {
                Log::info('Duplicate message detected - skipping', [
                    'platform_message_id' => $platformMessageId,
                    'existing_message_id' => $existingMessage->id,
                    'conversation_id' => $conversation->id,
                ]);
                
                return [
                    'customer_id' => $customer->id,
                    'conversation_id' => $conversation->id,
                    'message_id' => $existingMessage->id,
                    'duplicate' => true,
                ];
            }
        }

        Log::info('Customer and conversation resolved', [
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'conversation_status' => $conversation->status,
            'is_ai_handling' => $conversation->is_ai_handling,
        ]);

        // Extract media information if present
        $mediaUrls = $this->extractMediaUrls($messageData);

        // Extract reply/quote context from raw message
        $replyContext = $this->extractReplyContext($messageData, $conversation);

        $metadata = array_merge($messageData['metadata'] ?? [], [
            'raw_message' => $messageData['raw_message'] ?? [],
        ]);
        if ($replyContext) {
            $metadata['reply_to'] = $replyContext;
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => $messageData['text'] ?? '',
            'message_type' => $messageData['type'] ?? 'text',
            'platform_message_id' => $messageData['message_id'] ?? null,
            'media_urls' => $mediaUrls,
            'metadata' => $metadata,
        ]);

        $conversation->update(['last_message_at' => now()]);

        Log::info("Message received", [
            'connection_id' => $connection->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'message_type' => $messageData['type'] ?? 'text',
            'has_media' => !empty($mediaUrls),
        ]);

        // Dispatch media storage job to download and store media locally
        // This runs before AI processing to ensure media is available
        if (!empty($mediaUrls)) {
            $this->dispatchMediaStorageJob($message, $connection);
        }

        // Dispatch media processing job if message has processable media (for AI analysis)
        if ($message->hasProcessableMedia() && !empty($mediaUrls)) {
            $this->dispatchMediaProcessingJob($message, $connection);
        }

        return [
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
        ];
    }

    /**
     * Extract reply/quote context from raw message for all platforms
     */
    protected function extractReplyContext(array $messageData, $conversation): ?array
    {
        $rawMessage = $messageData['metadata']['raw_message'] ?? $messageData['raw_message'] ?? [];

        // WhatsApp: context.id references the quoted message
        if (!empty($rawMessage['context']['id'])) {
            $quotedMessageId = $rawMessage['context']['id'];
            $quotedMessage = Message::where('conversation_id', $conversation->id)
                ->where('platform_message_id', $quotedMessageId)
                ->first();

            return [
                'platform_message_id' => $quotedMessageId,
                'text' => $quotedMessage?->content ?? '[quoted message]',
                'is_from_customer' => $quotedMessage?->is_from_customer,
            ];
        }

        // Telegram: reply_to_message contains the full quoted message
        if (!empty($rawMessage['reply_to_message'])) {
            $replyTo = $rawMessage['reply_to_message'];
            return [
                'platform_message_id' => $replyTo['message_id'] ?? null,
                'text' => $replyTo['text'] ?? '[quoted message]',
                'is_from_customer' => !($replyTo['from']['is_bot'] ?? false),
            ];
        }

        // Facebook: reply_to.mid references the quoted message
        if (!empty($rawMessage['reply_to']['mid'])) {
            $quotedMid = $rawMessage['reply_to']['mid'];
            $quotedMessage = Message::where('conversation_id', $conversation->id)
                ->where('platform_message_id', $quotedMid)
                ->first();

            return [
                'platform_message_id' => $quotedMid,
                'text' => $quotedMessage?->content ?? '[quoted message]',
                'is_from_customer' => $quotedMessage?->is_from_customer,
            ];
        }

        return null;
    }

    /**
     * Extract media URLs from message data
     * Override in platform-specific handlers for custom extraction
     */
    protected function extractMediaUrls(array $messageData): array
    {
        return $messageData['media_urls'] ?? [];
    }

    /**
     * Dispatch media storage job to download and store media locally
     */
    protected function dispatchMediaStorageJob(Message $message, PlatformConnection $connection): void
    {
        $platform = $this->getPlatformIdentifier();

        if ($platform) {
            // Dispatch with no delay to store media as quickly as possible
            StoreMessageMedia::dispatch($message->id, $connection->id, $platform);

            Log::info("Media storage job dispatched", [
                'message_id' => $message->id,
                'platform' => $platform,
            ]);
        }
    }

    /**
     * Dispatch media processing job
     */
    protected function dispatchMediaProcessingJob(Message $message, PlatformConnection $connection): void
    {
        $platform = $this->getPlatformIdentifier();

        if ($platform && config('media.processing_enabled', true)) {
            // Add a small delay to allow media storage to complete first
            ProcessMediaMessage::dispatch($message, $connection, $platform)
                ->delay(now()->addSeconds(5));

            Log::info("Media processing job dispatched", [
                'message_id' => $message->id,
                'platform' => $platform,
                'message_type' => $message->message_type,
            ]);
        }
    }

    /**
     * Get the platform identifier for this handler
     * Override in platform-specific handlers
     */
    protected function getPlatformIdentifier(): ?string
    {
        return null;
    }

    /**
     * Send an image message - default implementation that throws
     * Override in platform-specific handlers
     */
    public function sendImage(PlatformConnection $connection, string $recipientId, string $imageUrl, ?string $caption = null): array
    {
        throw new \Exception('Image sending not supported for this platform');
    }

    protected function findOrCreateCustomer(PlatformConnection $connection, array $messageData): Customer
    {
        $platformUserId = $messageData['sender_id'];

        $customer = Customer::where('company_id', $connection->company_id)
            ->where('platform_user_id', $platformUserId)
            ->where('messaging_platform_id', $connection->messaging_platform_id)
            ->first();

        // If customer exists but has no name or default name, or missing profile photo, update it
        if ($customer && method_exists($this, 'fetchUserProfile')) {
            $needsProfileUpdate = empty($customer->name) ||
                                  empty($customer->profile_photo_url) ||
                                  in_array($customer->name, ['Facebook User', 'WhatsApp User', 'Telegram User', 'LINE User', 'Website Visitor']);

            if ($needsProfileUpdate) {
                $senderProfile = $this->fetchUserProfile($connection, $platformUserId);

                if ($senderProfile) {
                    $updateData = ['profile_data' => $senderProfile];

                    if (!empty($senderProfile['name'])) {
                        $updateData['name'] = $senderProfile['name'];
                    }

                    // Download and store profile photo if available
                    if (!empty($senderProfile['profile_pic'])) {
                        $profilePhotoService = new ProfilePhotoService();
                        $localPhotoUrl = $profilePhotoService->downloadAndStore(
                            $senderProfile['profile_pic'],
                            $customer->company_id,
                            $customer->id
                        );

                        if ($localPhotoUrl) {
                            $updateData['profile_photo_url'] = $localPhotoUrl;
                        }
                    }

                    $customer->update($updateData);

                    Log::info('Updated existing customer with profile', [
                        'customer_id' => $customer->id,
                        'name' => $senderProfile['name'] ?? $customer->name,
                        'has_profile_photo' => !empty($senderProfile['profile_pic']),
                    ]);
                }
            }
        }

        if (!$customer) {
            // Fetch profile from platform if handler supports it
            $senderProfile = null;
            if (method_exists($this, 'fetchUserProfile')) {
                $senderProfile = $this->fetchUserProfile($connection, $platformUserId);
                Log::info('Fetched user profile from platform', [
                    'platform' => $connection->messagingPlatform->slug,
                    'user_id' => $platformUserId,
                    'profile' => $senderProfile,
                ]);
            }

            // Check if customer exists by email from profile data
            $email = $senderProfile['email'] ?? $messageData['sender_email'] ?? null;
            
            if ($email) {
                $customer = Customer::where('company_id', $connection->company_id)
                    ->where('email', $email)
                    ->first();
            }
            
            // Create new customer if not found
            if (!$customer) {
                $customerName = $senderProfile['name'] ??
                               $messageData['sender_name'] ??
                               'Facebook User';

                $customer = Customer::create([
                    'company_id' => $connection->company_id,
                    'messaging_platform_id' => $connection->messaging_platform_id,
                    'platform_user_id' => $platformUserId,
                    'name' => $customerName,
                    'email' => $email,
                    'profile_photo_url' => $senderProfile['profile_pic'] ?? null,
                    'profile_data' => $senderProfile ?? [],
                ]);

                // Download and store profile photo after customer creation
                if (!empty($senderProfile['profile_pic'])) {
                    $profilePhotoService = new ProfilePhotoService();
                    $localPhotoUrl = $profilePhotoService->downloadAndStore(
                        $senderProfile['profile_pic'],
                        $connection->company_id,
                        $customer->id
                    );

                    if ($localPhotoUrl) {
                        $customer->profile_photo_url = $localPhotoUrl;
                        $customer->save();
                    }
                }

                Log::info('New customer created with profile', [
                    'customer_id' => $customer->id,
                    'name' => $customerName,
                    'has_profile' => !empty($senderProfile),
                    'has_profile_photo' => !empty($senderProfile['profile_pic']),
                ]);
            }
        }

        return $customer;
    }

    protected function findOrCreateConversation(PlatformConnection $connection, Customer $customer): Conversation
    {
        $conversation = Conversation::where('company_id', $connection->company_id)
            ->where('customer_id', $customer->id)
            ->where('platform_connection_id', $connection->id)
            ->whereIn('status', ['open', 'in_progress'])
            ->first();

        if (!$conversation) {
            $conversation = Conversation::create([
                'company_id' => $connection->company_id,
                'customer_id' => $customer->id,
                'platform_connection_id' => $connection->id,
                'status' => 'open',
                'priority' => 'normal',
                'is_ai_handling' => true,
                'last_message_at' => now(),
            ]);
        }

        return $conversation;
    }
}
