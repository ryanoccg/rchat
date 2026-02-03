<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Services\ConversationSummaryService;
use App\Services\Messaging\MessageHandlerFactory;
use App\Services\Media\MediaLibraryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class ConversationController extends Controller
{
    protected MediaLibraryService $mediaService;

    public function __construct(MediaLibraryService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    /**
     * Display a listing of conversations.
     * Optimized for performance with selective eager loading and efficient search.
     */
    public function index(Request $request)
    {
        $companyId = $request->user()->current_company_id;

        // Select only needed columns for listing performance
        $query = Conversation::where('company_id', $companyId)
            ->select([
                'id', 'company_id', 'customer_id', 'platform_connection_id',
                'assigned_to', 'status', 'priority', 'is_ai_handling',
                'ai_confidence_score', 'last_message_at', 'created_at', 'updated_at'
            ])
            ->with([
                'customer:id,name,email,phone,language,profile_photo_url',
                'assignedAgent:id,name',
                'platformConnection:id,messaging_platform_id',
                'platformConnection.messagingPlatform:id,name,icon',
                'latestMessage' => function ($query) {
                    $query->select('messages.id', 'messages.conversation_id', 'messages.content', 'messages.created_at');
                }
            ]);

        // Filter by status
        if ($request->filled('status')) {
            if ($request->status === 'open') {
                // Open means open, in_progress, and escalated (anything not closed)
                $query->whereIn('status', ['open', 'in_progress', 'escalated']);
            } else {
                $query->where('status', $request->status);
            }
        }

        // Filter by assigned agent
        if ($request->has('assigned_to')) {
            if ($request->assigned_to === 'me') {
                $query->where('assigned_to', $request->user()->id);
            } elseif ($request->assigned_to === 'unassigned') {
                $query->whereNull('assigned_to');
            } else {
                $query->where('assigned_to', $request->assigned_to);
            }
        }

        // Filter by AI handled
        if ($request->has('ai_handled')) {
            $query->where('is_ai_handling', $request->boolean('ai_handled'));
        }

        // Optimized search by customer name/email using subquery with IDs
        if ($request->filled('search')) {
            $search = trim($request->search);
            // Use a subquery to get matching customer IDs first
            $customerIds = Customer::where('company_id', $companyId)
                ->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "{$search}%")
                        ->orWhere('name', 'LIKE', "% {$search}%")
                        ->orWhere('email', 'LIKE', "{$search}%");
                })
                ->limit(100)
                ->pluck('id');

            $query->whereIn('customer_id', $customerIds);
        }

        $conversations = $query->latest('last_message_at')
            ->simplePaginate($request->get('per_page', 20));

        return ConversationResource::collection($conversations);
    }

    /**
     * Display the specified conversation with messages.
     * SECURITY: Returns transformed data instead of raw model to prevent exposing sensitive internal fields.
     */
    public function show(Request $request, string $id)
    {
        $companyId = $request->user()->current_company_id;

        $conversation = Conversation::where('company_id', $companyId)
            ->with([
                'customer',
                'assignedAgent',
                'platformConnection.messagingPlatform',
                'tags',
                'summary',
                'satisfactionRating',
            ])
            ->findOrFail($id);

        // Return transformed data instead of raw model
        return response()->json([
            'id' => $conversation->id,
            'status' => $conversation->status,
            'priority' => $conversation->priority,
            'is_ai_handling' => $conversation->is_ai_handling,
            'ai_confidence_score' => $conversation->ai_confidence_score,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'closed_at' => $conversation->closed_at?->toISOString(),
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),

            'customer' => $conversation->customer ? new CustomerResource($conversation->customer) : null,

            'assigned_agent' => $conversation->assignedAgent ? [
                'id' => $conversation->assignedAgent->id,
                'name' => $conversation->assignedAgent->name,
            ] : null,

            'platform_connection' => [
                'id' => $conversation->platformConnection->id,
                'platform_account_name' => $conversation->platformConnection->platform_account_name,
                'is_active' => $conversation->platformConnection->is_active,
                'messaging_platform' => $conversation->platformConnection->messagingPlatform ? [
                    'id' => $conversation->platformConnection->messagingPlatform->id,
                    'name' => $conversation->platformConnection->messagingPlatform->name,
                    'slug' => $conversation->platformConnection->messagingPlatform->slug,
                    'icon' => $conversation->platformConnection->messagingPlatform->icon,
                ] : null,
            ],

            'tags' => $conversation->tags->map(fn($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
            ]),

            'summary' => $conversation->summary ? [
                'id' => $conversation->summary->id,
                'summary' => $conversation->summary->summary,
                'key_points' => $conversation->summary->key_points,
                'generated_at' => $conversation->summary->created_at?->toISOString(),
            ] : null,

            'satisfaction_rating' => $conversation->satisfactionRating ? [
                'rating' => $conversation->satisfactionRating->rating,
                'feedback' => $conversation->satisfactionRating->feedback,
            ] : null,
        ]);
    }

    /**
     * Get messages for a conversation.
     * Supports pagination with before_id for loading older messages.
     * SECURITY: Returns transformed messages to prevent exposing sensitive internal data.
     */
    public function messages(Request $request, string $id)
    {
        $companyId = $request->user()->current_company_id;

        $conversation = Conversation::where('company_id', $companyId)->findOrFail($id);

        $limit = min($request->get('limit', 30), 100); // Default 30, max 100

        $query = $conversation->messages()
            ->with(['sender', 'quotedMessage', 'mediaProcessingResults'])
            ->orderBy('created_at', 'desc'); // Order by newest first for pagination

        // If before_id is provided, load messages older than that ID
        if ($request->has('before_id')) {
            $beforeMessage = Message::find($request->before_id);
            if ($beforeMessage) {
                $query->where('created_at', '<', $beforeMessage->created_at);
            }
        }

        $messages = $query->take($limit)->get();

        // Check if there are more older messages
        $hasMore = false;
        if ($messages->isNotEmpty()) {
            $oldestMessage = $messages->last();
            $hasMore = $conversation->messages()
                ->where('created_at', '<', $oldestMessage->created_at)
                ->exists();
        }

        // Reverse to return in chronological order (oldest first)
        $messages = $messages->reverse()->values();

        return response()->json([
            'data' => MessageResource::collection($messages),
            'has_more' => $hasMore,
            'oldest_id' => $messages->isNotEmpty() ? $messages->first()->id : null,
        ]);
    }

    /**
     * Send a message in a conversation.
     */
    public function sendMessage(Request $request, string $id)
    {
        $request->validate([
            'content' => 'required_without:media_urls,attachment|string',
            'message_type' => 'in:text,image,audio,video,file',
            'media_urls' => 'array',
            'attachment' => 'nullable|file|max:10240', // Max 10MB
            'quoted_message_id' => 'nullable|exists:messages,id',
        ]);

        $companyId = $request->user()->current_company_id;
        $conversation = Conversation::where('company_id', $companyId)->findOrFail($id);

        $connection = $conversation->platformConnection;
        if (!$connection || !$connection->is_active) {
            return response()->json(['error' => 'Platform connection not active'], 400);
        }

        $platform = $connection->messagingPlatform->slug ?? null;
        if (!$platform) {
            return response()->json(['error' => 'Messaging platform not found'], 400);
        }

        // Get the customer's platform user ID
        $customer = $conversation->customer;
        if (!$customer || !$customer->platform_user_id) {
            return response()->json(['error' => 'Customer platform user ID not found'], 400);
        }

        $recipientId = $customer->platform_user_id;
        $messageType = $request->get('message_type', 'text');
        $content = $request->content;
        $mediaUrls = $request->media_urls;
        $attachment = $request->file('attachment');

        // Handle file upload if attachment is provided
        if ($attachment) {
            try {
                // Store attachment using MediaLibraryService
                $media = $this->mediaService->upload($attachment, $companyId, $request->user()->id, [
                    'collection' => 'attachments',
                    'folder' => 'attachments',
                    'title' => 'Conversation Attachment',
                    'source' => 'conversation_attachment',
                    'mediable_type' => Conversation::class,
                    'mediable_id' => $conversation->id,
                ]);

                // Build media_urls array structure
                $mediaUrls = [[
                    'url' => $media->url,
                    'local_url' => $media->url,
                    'type' => $messageType,
                    'mime_type' => $media->mime_type,
                    'size' => $media->file_size,
                    'media_id' => $media->id,
                ]];

                Log::info('Attachment uploaded', [
                    'media_id' => $media->id,
                    'path' => $media->path,
                    'url' => $media->url,
                    'mime_type' => $media->mime_type,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to upload attachment', [
                    'error' => $e->getMessage(),
                ]);
                return response()->json(['error' => 'Failed to upload attachment'], 500);
            }
        }

        try {
            $handler = MessageHandlerFactory::create($platform);

            // Handle image/attachment messages differently
            if ($messageType === 'image' && !empty($mediaUrls) && is_array($mediaUrls)) {
                // Send images first
                foreach ($mediaUrls as $media) {
                    $imageUrl = is_array($media) ? ($media['url'] ?? $media['local_url'] ?? null) : $media;
                    if ($imageUrl) {
                        // Ensure absolute URL for external platforms
                        if ($imageUrl && !str_starts_with($imageUrl, 'http')) {
                            $imageUrl = url($imageUrl);
                        }
                        $handler->sendImage($connection, $recipientId, $imageUrl, $content);
                    }
                }
            } else {
                // Send text message
                $handler->sendMessage($connection, $recipientId, $content ?? '');
            }

            // Create message record after successful send
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $request->user()->id,
                'sender_type' => 'agent',
                'message_type' => $messageType,
                'content' => $content,
                'media_urls' => $mediaUrls,
                'is_from_customer' => false,
                'quoted_message_id' => $request->quoted_message_id,
            ]);

            // Update conversation
            $conversation->update([
                'last_message_at' => now(),
                'is_ai_handling' => false,
            ]);

            Log::info('Agent message sent to platform', [
                'conversation_id' => $conversation->id,
                'platform' => $platform,
                'recipient_id' => $recipientId,
            ]);

            // Return transformed message to prevent exposing sensitive internal data
            $message->load(['sender', 'quotedMessage']);
            return response()->json(new MessageResource($message), 201);
        } catch (\Exception $e) {
            Log::error('Failed to send agent message to platform', [
                'conversation_id' => $conversation->id,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Failed to send message: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Assign conversation to an agent.
     */
    public function assign(Request $request, string $id)
    {
        $request->validate([
            'agent_id' => 'nullable|exists:users,id',
        ]);

        $companyId = $request->user()->current_company_id;
        $conversation = Conversation::where('company_id', $companyId)->findOrFail($id);

        $conversation->update([
            'assigned_to' => $request->agent_id,
            'is_ai_handling' => false,
        ]);

        // Send notification to assigned agent
        if ($request->agent_id) {
            $customerName = $conversation->customer?->name ?? 'Unknown Customer';
            \App\Services\NotificationService::conversationAssigned(
                $request->agent_id,
                $companyId,
                $conversation->id,
                $customerName,
                $request->user()->name
            );
        }

        // Return limited data instead of full conversation model
        return response()->json([
            'id' => $conversation->id,
            'assigned_to' => $conversation->assigned_to,
            'is_ai_handling' => $conversation->is_ai_handling,
            'assigned_agent' => $conversation->assignedAgent ? [
                'id' => $conversation->assignedAgent->id,
                'name' => $conversation->assignedAgent->name,
            ] : null,
        ]);
    }

    /**
     * Update conversation status.
     */
    public function updateStatus(Request $request, string $id)
    {
        $request->validate([
            'status' => 'required|in:open,in_progress,escalated,closed',
        ]);

        $companyId = $request->user()->current_company_id;
        $conversation = Conversation::where('company_id', $companyId)->findOrFail($id);

        $updateData = ['status' => $request->status];

        if ($request->status === 'closed') {
            $updateData['closed_at'] = now();
        }

        $conversation->update($updateData);

        // Auto-generate summary when conversation is closed
        if ($request->status === 'closed' && !$conversation->summary) {
            try {
                $summaryService = new ConversationSummaryService();
                $summaryService->generateSummary($conversation, $request->user()->id);
                Log::info('Auto-generated summary on conversation close', [
                    'conversation_id' => $conversation->id,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to auto-generate summary', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Return limited data instead of full conversation model
        return response()->json([
            'id' => $conversation->id,
            'status' => $conversation->status,
            'closed_at' => $conversation->closed_at?->toISOString(),
        ]);
    }

    /**
     * Transfer to AI handling.
     */
    public function transferToAi(Request $request, string $id)
    {
        $companyId = $request->user()->current_company_id;
        $conversation = Conversation::where('company_id', $companyId)->findOrFail($id);

        $conversation->update([
            'is_ai_handling' => true,
            'assigned_to' => null,
        ]);

        // Return limited data instead of full conversation model
        return response()->json([
            'id' => $conversation->id,
            'is_ai_handling' => $conversation->is_ai_handling,
            'assigned_to' => $conversation->assigned_to,
        ]);
    }

    /**
     * Update AI handling status.
     */
    public function updateAiHandling(Request $request, string $id)
    {
        $request->validate([
            'is_ai_handling' => 'required|boolean',
        ]);

        $companyId = $request->user()->current_company_id;
        $conversation = Conversation::where('company_id', $companyId)->findOrFail($id);

        $updateData = ['is_ai_handling' => $request->is_ai_handling];

        // If switching to human, clear AI handling
        if (!$request->is_ai_handling && !$conversation->assigned_to) {
            // Auto-assign to current user if switching to human mode
            $updateData['assigned_to'] = $request->user()->id;
        }

        // If switching to AI, optionally clear assignment
        if ($request->is_ai_handling) {
            $updateData['assigned_to'] = null;
        }

        $conversation->update($updateData);

        // Return limited data instead of full conversation model
        return response()->json([
            'id' => $conversation->id,
            'is_ai_handling' => $conversation->is_ai_handling,
            'assigned_to' => $conversation->assigned_to,
        ]);
    }

    /**
     * Get conversation summary.
     */
    public function getSummary(Request $request, string $id)
    {
        $companyId = $request->user()->current_company_id;
        $conversation = Conversation::where('company_id', $companyId)
            ->with('summary')
            ->findOrFail($id);

        if (!$conversation->summary) {
            // Auto-generate if requested
            if ($request->boolean('auto_generate')) {
                try {
                    $summaryService = new ConversationSummaryService();
                    $summary = $summaryService->generateSummary($conversation, $request->user()->id);
                    return response()->json([
                        'exists' => true,
                        'summary' => $summary,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to auto-generate summary', [
                        'conversation_id' => $conversation->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message' => 'No summary available for this conversation.',
                'exists' => false,
            ], 404);
        }

        return response()->json([
            'exists' => true,
            'summary' => $conversation->summary,
        ]);
    }

    /**
     * Generate AI-powered conversation summary.
     */
    public function generateSummary(Request $request, string $id)
    {
        $companyId = $request->user()->current_company_id;
        $conversation = Conversation::where('company_id', $companyId)->findOrFail($id);

        $summaryService = new ConversationSummaryService();
        
        try {
            $summary = $summaryService->generateSummary($conversation, $request->user()->id);
            
            return response()->json([
                'success' => true,
                'message' => 'Summary generated successfully',
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate summary: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete conversation summary.
     */
    public function deleteSummary(Request $request, string $id)
    {
        $companyId = $request->user()->current_company_id;
        $conversation = Conversation::where('company_id', $companyId)->findOrFail($id);

        if ($conversation->summary) {
            $conversation->summary->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Summary deleted successfully',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No summary to delete',
        ], 404);
    }

    /**
     * Generate summaries for multiple conversations (batch).
     */
    public function generateBatchSummaries(Request $request)
    {
        $request->validate([
            'conversation_ids' => 'required|array|min:1',
            'conversation_ids.*' => 'required|integer|exists:conversations,id',
        ]);

        $companyId = $request->user()->current_company_id;
        
        // Verify all conversations belong to company
        $validConversations = Conversation::where('company_id', $companyId)
            ->whereIn('id', $request->conversation_ids)
            ->pluck('id')
            ->toArray();

        if (count($validConversations) !== count($request->conversation_ids)) {
            return response()->json([
                'success' => false,
                'message' => 'Some conversations do not belong to your company',
            ], 403);
        }

        $summaryService = new ConversationSummaryService();
        $results = $summaryService->generateBatchSummaries($validConversations, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Batch summary generation completed',
            'results' => $results,
            'total' => count($request->conversation_ids),
            'successful' => count($results['success']),
            'failed' => count($results['failed']),
        ]);
    }
}
