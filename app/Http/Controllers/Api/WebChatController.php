<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessagingPlatform;
use App\Models\PlatformConnection;
use App\Services\AI\AiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WebChatController extends Controller
{
    /**
     * Initialize chat widget - returns config and creates/retrieves session
     */
    public function init(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'widget_id' => 'required|string',
            'visitor_id' => 'nullable|string',
            'visitor_info' => 'nullable|array',
            'visitor_info.name' => 'nullable|string|max:255',
            'visitor_info.email' => 'nullable|email|max:255',
            'visitor_info.phone' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Find the platform connection by widget ID (using the connection's unique identifier)
        $connection = PlatformConnection::where('id', $request->widget_id)
            ->whereHas('messagingPlatform', function ($q) {
                $q->where('slug', 'webchat');
            })
            ->where('is_active', true)
            ->first();

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'Widget not found or inactive',
            ], 404);
        }

        // Validate domain if configured
        $allowedDomains = $connection->credentials['allowed_domains'] ?? '*';
        if ($allowedDomains !== '*') {
            $origin = $request->header('Origin') ?? $request->header('Referer');
            if ($origin) {
                $originHost = parse_url($origin, PHP_URL_HOST);
                $allowed = array_filter(array_map('trim', explode("\n", $allowedDomains)));
                
                if (!empty($allowed) && !in_array($originHost, $allowed) && !in_array('*', $allowed)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Domain not allowed',
                    ], 403);
                }
            }
        }

        // Get or create visitor ID
        $visitorId = $request->visitor_id ?? Str::uuid()->toString();

        // Get widget config
        $config = [
            'widget_id' => $connection->id,
            'visitor_id' => $visitorId,
            'title' => $connection->credentials['widget_title'] ?? 'Chat with us',
            'welcome_message' => $connection->credentials['welcome_message'] ?? 'Hello! How can we help you today?',
            'primary_color' => $connection->credentials['primary_color'] ?? '#6366f1',
            'position' => $connection->credentials['position'] ?? 'bottom-right',
            'company_name' => $connection->company->name ?? 'Support',
            'company_logo' => $connection->company->logo ?? null,
        ];

        // Get existing conversation and messages if visitor exists
        $customer = Customer::where('company_id', $connection->company_id)
            ->where('platform_user_id', $visitorId)
            ->where('messaging_platform_id', $connection->messaging_platform_id)
            ->first();

        // Update customer with visitor info if provided
        $visitorInfo = $request->visitor_info;
        if ($visitorInfo && $customer) {
            $updateData = [];
            if (!empty($visitorInfo['name']) && empty($customer->name)) {
                $updateData['name'] = $visitorInfo['name'];
            }
            if (!empty($visitorInfo['email']) && empty($customer->email)) {
                $updateData['email'] = $visitorInfo['email'];
            }
            if (!empty($visitorInfo['phone']) && empty($customer->phone)) {
                $updateData['phone'] = $visitorInfo['phone'];
            }
            if (!empty($updateData)) {
                $customer->update($updateData);
            }
        } elseif ($visitorInfo && !$customer) {
            // Check if customer exists by email first
            if (!empty($visitorInfo['email'])) {
                $customer = Customer::where('company_id', $connection->company_id)
                    ->where('email', $visitorInfo['email'])
                    ->first();
            }
            
            // Create customer with visitor info if not found
            if (!$customer) {
                $customer = Customer::create([
                    'company_id' => $connection->company_id,
                    'messaging_platform_id' => $connection->messaging_platform_id,
                    'platform_user_id' => $visitorId,
                    'name' => $visitorInfo['name'] ?? 'Web Visitor',
                    'email' => $visitorInfo['email'] ?? null,
                    'phone' => $visitorInfo['phone'] ?? null,
                ]);
            }
        }

        $messages = [];
        $conversationId = null;

        if ($customer) {
            $conversation = Conversation::where('customer_id', $customer->id)
                ->where('platform_connection_id', $connection->id)
                ->where('status', '!=', 'closed')
                ->orderByDesc('created_at')
                ->first();

            if ($conversation) {
                $conversationId = $conversation->id;
                $messages = $conversation->messages()
                    ->orderBy('created_at', 'asc')
                    ->get()
                    ->map(function ($msg) {
                        return [
                            'id' => $msg->id,
                            'content' => $msg->content,
                            'is_from_customer' => $msg->is_from_customer,
                            'sender_type' => $msg->sender_type,
                            'created_at' => $msg->created_at->toIso8601String(),
                        ];
                    });
            }
        }

        return response()->json([
            'success' => true,
            'config' => $config,
            'conversation_id' => $conversationId,
            'messages' => $messages,
        ]);
    }

    /**
     * Send a message from the widget visitor
     */
    public function sendMessage(Request $request)
    {
        \Log::info('WebChat: sendMessage called', [
            'widget_id' => $request->widget_id,
            'visitor_id' => $request->visitor_id,
            'message' => $request->message,
        ]);
        
        $validator = Validator::make($request->all(), [
            'widget_id' => 'required|string',
            'visitor_id' => 'required|string',
            'message' => 'required|string|max:5000',
            'visitor_name' => 'nullable|string|max:255',
            'visitor_email' => 'nullable|email|max:255',
        ]);

        if ($validator->fails()) {
            \Log::warning('WebChat: Validation failed', ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid request',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Find the platform connection
        $connection = PlatformConnection::where('id', $request->widget_id)
            ->whereHas('messagingPlatform', function ($q) {
                $q->where('slug', 'webchat');
            })
            ->where('is_active', true)
            ->first();

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'Widget not found or inactive',
            ], 404);
        }

        // Get the webchat platform
        $platform = MessagingPlatform::where('slug', 'webchat')->first();

        // Get or create customer - first check by email if provided
        $customer = null;
        if ($request->visitor_email) {
            $customer = Customer::where('company_id', $connection->company_id)
                ->where('email', $request->visitor_email)
                ->first();
        }
        
        // If not found by email, try by platform_user_id
        if (!$customer) {
            $customer = Customer::firstOrCreate(
                [
                    'company_id' => $connection->company_id,
                    'platform_user_id' => $request->visitor_id,
                    'messaging_platform_id' => $platform->id,
                ],
                [
                    'name' => $request->visitor_name ?? 'Website Visitor',
                    'email' => $request->visitor_email,
                    'profile_data' => [
                        'source' => 'webchat',
                        'user_agent' => $request->userAgent(),
                        'ip' => $request->ip(),
                    ],
                ]
            );
        }

        // Update customer info if provided
        if ($request->visitor_name && $customer->name === 'Website Visitor') {
            $customer->update(['name' => $request->visitor_name]);
        }
        if ($request->visitor_email && !$customer->email) {
            $customer->update(['email' => $request->visitor_email]);
        }

        // Get or create conversation
        $conversation = Conversation::where('customer_id', $customer->id)
            ->where('platform_connection_id', $connection->id)
            ->where('status', '!=', 'closed')
            ->orderByDesc('created_at')
            ->first();

        if (!$conversation) {
            $conversation = Conversation::create([
                'company_id' => $connection->company_id,
                'customer_id' => $customer->id,
                'platform_connection_id' => $connection->id,
                'status' => 'open',
                'is_ai_handling' => true,
                'priority' => 'normal',
            ]);
        } else {
            // Ensure AI handling is enabled for webchat if not assigned to human agent
            if (!$conversation->is_ai_handling && !$conversation->assigned_to) {
                $conversation->update(['is_ai_handling' => true]);
            }
        }

        // Create the visitor's message
        // Note: sender_id is null for customer messages (it references users table)
        $visitorMessage = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $request->message,
            'message_type' => 'text',
            'sender_type' => 'customer',
            'is_from_customer' => true,
            'sender_id' => null, // Customer messages don't have user sender
            'platform_message_id' => Str::uuid()->toString(),
            'metadata' => [
                'source' => 'webchat_widget',
                'customer_id' => $customer->id,
            ],
        ]);

        // Update conversation
        $conversation->update([
            'last_message_at' => now(),
        ]);

        $response = [
            'success' => true,
            'message' => [
                'id' => $visitorMessage->id,
                'content' => $visitorMessage->content,
                'is_from_customer' => true,
                'sender_type' => 'customer',
                'created_at' => $visitorMessage->created_at->toIso8601String(),
            ],
            'conversation_id' => $conversation->id,
        ];

        // Generate AI response if enabled
        \Log::info('WebChat: Checking AI handling', [
            'conversation_id' => $conversation->id,
            'is_ai_handling' => $conversation->is_ai_handling,
            'message' => $request->message,
        ]);
        
        if ($conversation->is_ai_handling) {
            try {
                // Always use AiService for consistent RAG handling across all platforms
                \Log::info('WebChat: Using AiService for message handling', [
                    'conversation_id' => $conversation->id,
                    'message' => $request->message,
                ]);
                
                $aiService = new AiService($connection->company);
                $aiResponse = $aiService->respondToMessage($conversation, $request->message);

                if ($aiResponse->isSuccessful()) {
                    $aiMessage = Message::create([
                        'conversation_id' => $conversation->id,
                        'content' => $aiResponse->getContent(),
                        'message_type' => 'text',
                        'sender_type' => 'ai',
                        'is_from_customer' => false,
                        'platform_message_id' => Str::uuid()->toString(),
                        'ai_response_data' => [
                            'model' => $aiResponse->getModel(),
                            'usage' => $aiResponse->getUsage(),
                        ],
                    ]);

                    $response['ai_response'] = [
                        'id' => $aiMessage->id,
                        'content' => $aiMessage->content,
                        'is_from_customer' => false,
                        'sender_type' => 'ai',
                        'created_at' => $aiMessage->created_at->toIso8601String(),
                    ];

                    $conversation->update([
                        'last_message_at' => now(),
                        'last_message_preview' => Str::limit($aiResponse->getContent(), 100),
                    ]);
                }
            } catch (\Exception $e) {
                // Log error but don't fail the request
                \Log::error('WebChat AI response failed: ' . $e->getMessage(), [
                    'company_id' => $connection->company_id,
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return response()->json($response);
    }

    /**
     * Get messages for a conversation (polling endpoint)
     */
    public function getMessages(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'widget_id' => 'required|string',
            'visitor_id' => 'required|string',
            'last_message_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Find the platform connection
        $connection = PlatformConnection::where('id', $request->widget_id)
            ->whereHas('messagingPlatform', function ($q) {
                $q->where('slug', 'webchat');
            })
            ->where('is_active', true)
            ->first();

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'Widget not found',
            ], 404);
        }

        // Find customer
        $platform = MessagingPlatform::where('slug', 'webchat')->first();
        $customer = Customer::where('company_id', $connection->company_id)
            ->where('platform_user_id', $request->visitor_id)
            ->where('messaging_platform_id', $platform->id)
            ->first();

        if (!$customer) {
            return response()->json([
                'success' => true,
                'messages' => [],
            ]);
        }

        // Find conversation
        $conversation = Conversation::where('customer_id', $customer->id)
            ->where('platform_connection_id', $connection->id)
            ->where('status', '!=', 'closed')
            ->orderByDesc('created_at')
            ->first();

        if (!$conversation) {
            return response()->json([
                'success' => true,
                'messages' => [],
            ]);
        }

        // Get new messages
        $query = $conversation->messages()->orderBy('created_at', 'asc');

        if ($request->last_message_id) {
            $query->where('id', '>', $request->last_message_id);
        }

        $messages = $query->get()->map(function ($msg) {
            return [
                'id' => $msg->id,
                'content' => $msg->content,
                'is_from_customer' => $msg->is_from_customer,
                'sender_type' => $msg->sender_type,
                'created_at' => $msg->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'messages' => $messages,
            'conversation_status' => $conversation->status,
        ]);
    }

    /**
     * Get the embeddable widget script
     */
    public function widgetScript($widgetId)
    {
        $connection = PlatformConnection::where('id', $widgetId)
            ->whereHas('messagingPlatform', function ($q) {
                $q->where('slug', 'webchat');
            })
            ->where('is_active', true)
            ->first();

        if (!$connection) {
            return response('// Widget not found', 404)
                ->header('Content-Type', 'application/javascript');
        }

        $config = [
            'widgetId' => (string) $connection->id,
            'apiUrl' => config('app.url') . '/api/webchat',
            'title' => $connection->credentials['widget_title'] ?? 'Chat with us',
            'welcomeMessage' => $connection->credentials['welcome_message'] ?? 'Hello! How can we help you today?',
            'primaryColor' => $connection->credentials['primary_color'] ?? '#6366f1',
            'position' => $connection->credentials['position'] ?? 'bottom-right',
            'companyName' => $connection->company->name ?? 'Support',
        ];

        $script = view('webchat.widget-script', ['config' => $config])->render();

        return response($script)
            ->header('Content-Type', 'application/javascript')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Test AI connection endpoint
     */
    public function testAi(Request $request)
    {
        try {
            $provider = new \App\Services\AI\Providers\OpenAiProvider(null);
            
            $response = $provider->sendMessage('Hello, this is a test message.', [], [
                'model' => 'gpt-4o-mini',
                'max_tokens' => 50,
                'temperature' => 0.5,
            ]);

            return response()->json([
                'success' => $response->isSuccessful(),
                'content' => $response->isSuccessful() ? $response->getContent() : null,
                'error' => $response->isSuccessful() ? null : $response->getError(),
                'model' => $response->getModel(),
                'usage' => $response->getUsage(),
                'api_key_configured' => !empty(env('OPENAI_API_KEY')),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }
}
