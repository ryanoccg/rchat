<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Conversation;
use App\Services\CustomerInsightService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{
    /**
     * Get paginated list of customers with filters
     * SECURITY: Excludes sensitive metadata field from select, only exposing tags separately.
     */
    public function index(Request $request)
    {
        $companyId = $request->get('company_id');

        $query = Customer::where('company_id', $companyId)
            ->select([
                'id', 'company_id', 'platform_user_id', 'messaging_platform_id',
                'name', 'email', 'phone', 'profile_photo_url', 'language',
                // Only select tags from metadata, not the entire metadata object
                // Using raw JSON_EXTRACT for security
                \DB::raw("JSON_EXTRACT(metadata, '$.tags') as tags"),
                'created_at', 'updated_at'
            ])
            ->with(['messagingPlatform:id,name,display_name,slug'])
            ->withCount('conversations');

        // Search by name, email, or phone
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('platform_user_id', 'like', "%{$search}%");
            });
        }

        // Filter by platform (support both 'platform' and 'platform_id')
        if ($request->filled('platform')) {
            $query->where('messaging_platform_id', $request->platform);
        } elseif ($request->filled('platform_id')) {
            $query->where('messaging_platform_id', $request->platform_id);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Filter by tag
        if ($request->filled('tag')) {
            $tag = $request->tag;
            $query->whereNotNull('metadata')
                ->whereRaw("JSON_CONTAINS(metadata->'$.tags', ?)", [json_encode($tag)]);
        }

        // Sort (support both 'sort_field/sort_order' and 'sort_by/sort_order')
        $sortField = $request->get('sort_by', $request->get('sort_field', 'created_at'));
        $sortOrder = $request->get('sort_order', 'desc');
        $allowedSorts = ['name', 'email', 'created_at', 'updated_at'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        // Include stats
        $query->withCount('conversations')
            ->withCount(['conversations as open_conversations_count' => function ($q) {
                $q->where('status', 'open');
            }]);

        $perPage = min($request->get('per_page', 15), 100);
        $customers = $query->paginate($perPage);

        return response()->json([
            'data' => $customers->items(),
            'current_page' => $customers->currentPage(),
            'last_page' => $customers->lastPage(),
            'per_page' => $customers->perPage(),
            'total' => $customers->total(),
        ]);
    }

    /**
     * Get customer details
     * SECURITY: Returns transformed data to prevent exposing sensitive internal fields like metadata.
     */
    public function show(Request $request, string $id)
    {
        $companyId = $request->get('company_id');

        $customer = Customer::where('company_id', $companyId)
            ->with(['messagingPlatform:id,name,display_name,slug'])
            ->withCount('conversations')
            ->withCount(['conversations as open_conversations_count' => function ($q) {
                $q->where('status', 'open');
            }])
            ->withCount(['conversations as resolved_conversations_count' => function ($q) {
                $q->where('status', 'closed');
            }])
            ->findOrFail($id);

        // Get average satisfaction rating
        $avgRating = $customer->satisfactionRatings()->avg('rating');

        // Get recent conversations (limited data)
        $recentConversations = Conversation::where('customer_id', $customer->id)
            ->with(['assignedAgent:id,name', 'platformConnection.messagingPlatform:id,name,slug,icon'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn($conv) => [
                'id' => $conv->id,
                'status' => $conv->status,
                'priority' => $conv->priority,
                'last_message_at' => $conv->last_message_at?->toISOString(),
                'created_at' => $conv->created_at?->toISOString(),
                'assigned_agent' => $conv->assignedAgent ? [
                    'id' => $conv->assignedAgent->id,
                    'name' => $conv->assignedAgent->name,
                ] : null,
                'platform' => $conv->platformConnection?->messagingPlatform ? [
                    'id' => $conv->platformConnection->messagingPlatform->id,
                    'name' => $conv->platformConnection->messagingPlatform->name,
                    'slug' => $conv->platformConnection->messagingPlatform->slug,
                    'icon' => $conv->platformConnection->messagingPlatform->icon,
                ] : null,
            ]);

        // Return safe customer data using CustomerResource + additional stats
        return response()->json([
            'data' => array_merge((new CustomerResource($customer))->toArray($request), [
                'conversations_count' => $customer->conversations_count ?? 0,
                'open_conversations_count' => $customer->open_conversations_count ?? 0,
                'resolved_conversations_count' => $customer->resolved_conversations_count ?? 0,
                'avg_satisfaction_rating' => $avgRating ? round($avgRating, 1) : null,
                'tags' => $customer->metadata['tags'] ?? [],
                'recent_conversations' => $recentConversations,
            ]),
        ]);
    }

    /**
     * Create a new customer
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'platform_user_id' => 'required|string|max:255',
            'messaging_platform_id' => 'required|exists:messaging_platforms,id',
            'language' => 'nullable|string|max:10',
            'profile_data' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $companyId = $request->get('company_id');

        // Check for existing customer with same platform_user_id
        $existing = Customer::where('company_id', $companyId)
            ->where('messaging_platform_id', $request->messaging_platform_id)
            ->where('platform_user_id', $request->platform_user_id)
            ->first();

        if ($existing) {
            // SECURITY: Return limited data instead of full customer model
            return response()->json([
                'message' => 'Customer with this platform ID already exists',
                'data' => [
                    'id' => $existing->id,
                    'name' => $existing->name,
                    'platform_user_id' => $existing->platform_user_id,
                ],
            ], 409);
        }

        // Check if customer exists by email
        if ($request->email) {
            $existingByEmail = Customer::where('company_id', $companyId)
                ->where('email', $request->email)
                ->first();

            if ($existingByEmail) {
                // SECURITY: Return limited data instead of full customer model
                return response()->json([
                    'message' => 'Customer with this email already exists',
                    'data' => [
                        'id' => $existingByEmail->id,
                        'name' => $existingByEmail->name,
                        'email' => $existingByEmail->email,
                    ],
                ], 409);
            }
        }

        $customer = Customer::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'platform_user_id' => $request->platform_user_id,
            'messaging_platform_id' => $request->messaging_platform_id,
            'language' => $request->language ?? 'en',
            'profile_data' => $request->profile_data,
            'metadata' => $request->metadata,
        ]);

        $customer->load('messagingPlatform:id,name,display_name,slug');

        return response()->json([
            'message' => 'Customer created successfully',
            'customer' => $customer,
        ], 201);
    }

    /**
     * Update customer information
     */
    public function update(Request $request, string $id)
    {
        $companyId = $request->get('company_id');

        $customer = Customer::where('company_id', $companyId)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'language' => 'nullable|string|max:10',
            'profile_data' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $customer->update($request->only([
            'name', 'email', 'phone', 'language', 'profile_data', 'metadata'
        ]));

        $customer->load('messagingPlatform:id,name,display_name,slug');

        return response()->json([
            'message' => 'Customer updated successfully',
            'customer' => $customer,
        ]);
    }

    /**
     * Delete a customer
     */
    public function destroy(Request $request, string $id)
    {
        $companyId = $request->get('company_id');

        $customer = Customer::where('company_id', $companyId)->findOrFail($id);

        // Check if customer has conversations
        if ($customer->conversations()->exists()) {
            return response()->json([
                'message' => 'Cannot delete customer with existing conversations',
            ], 422);
        }

        $customer->delete();

        return response()->json([
            'message' => 'Customer deleted successfully',
        ]);
    }

    /**
     * Get customer's conversation history
     */
    public function conversations(Request $request, string $id)
    {
        $companyId = $request->get('company_id');

        $customer = Customer::where('company_id', $companyId)->findOrFail($id);

        $query = Conversation::where('customer_id', $customer->id)
            ->with([
                'assignedAgent:id,name',
                'platformConnection:id,platform_account_name',
                'platformConnection.messagingPlatform:id,name,display_name,slug',
            ])
            ->withCount('messages');

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $conversations = $query->latest()->paginate(20);

        return response()->json([
            'data' => $conversations->items(),
            'current_page' => $conversations->currentPage(),
            'last_page' => $conversations->lastPage(),
            'per_page' => $conversations->perPage(),
            'total' => $conversations->total(),
        ]);
    }

    /**
     * Update customer notes (stored in metadata)
     */
    public function updateNotes(Request $request, string $id)
    {
        $companyId = $request->get('company_id');

        $customer = Customer::where('company_id', $companyId)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $metadata = $customer->metadata ?? [];
        $metadata['notes'] = $request->notes;
        $customer->metadata = $metadata;
        $customer->save();

        return response()->json([
            'message' => 'Notes updated successfully',
            'customer' => $customer,
        ]);
    }

    /**
     * Update customer tags (stored in metadata)
     */
    public function updateTags(Request $request, string $id)
    {
        $companyId = $request->get('company_id');

        $customer = Customer::where('company_id', $companyId)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $metadata = $customer->metadata ?? [];
        $metadata['tags'] = $request->tags ?? [];
        $customer->metadata = $metadata;
        $customer->save();

        return response()->json([
            'message' => 'Tags updated successfully',
            'customer' => $customer,
        ]);
    }

    /**
     * Get all unique tags used across customers
     */
    public function tags(Request $request)
    {
        $companyId = $request->get('company_id');

        $customers = Customer::where('company_id', $companyId)
            ->whereNotNull('metadata')
            ->get(['metadata']);

        $allTags = collect();
        foreach ($customers as $customer) {
            if (isset($customer->metadata['tags']) && is_array($customer->metadata['tags'])) {
                $allTags = $allTags->merge($customer->metadata['tags']);
            }
        }

        return response()->json([
            'tags' => $allTags->unique()->values()->all(),
        ]);
    }

    /**
     * Get customer statistics summary
     */
    public function stats(Request $request)
    {
        $companyId = $request->get('company_id');

        $totalCustomers = Customer::where('company_id', $companyId)->count();

        $newCustomersThisMonth = Customer::where('company_id', $companyId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $customersByPlatform = Customer::where('company_id', $companyId)
            ->selectRaw('messaging_platform_id, COUNT(*) as count')
            ->groupBy('messaging_platform_id')
            ->with('messagingPlatform:id,name,display_name,slug')
            ->get();

        $activeCustomers = Customer::where('company_id', $companyId)
            ->whereHas('conversations', function ($q) {
                $q->where('updated_at', '>=', now()->subDays(30));
            })
            ->count();

        return response()->json([
            'total' => $totalCustomers,
            'new_this_month' => $newCustomersThisMonth,
            'active_last_30_days' => $activeCustomers,
            'by_platform' => $customersByPlatform,
        ]);
    }

    /**
     * Generate AI insights (tags and notes) for a customer
     */
    public function generateInsights(Request $request, string $id)
    {
        $companyId = $request->get('company_id');

        $customer = Customer::where('company_id', $companyId)->findOrFail($id);

        // Get the most recent conversation
        $conversation = Conversation::where('customer_id', $customer->id)
            ->orderBy('last_message_at', 'desc')
            ->first();

        if (!$conversation) {
            return response()->json([
                'message' => 'No conversations found for this customer',
            ], 404);
        }

        $insightService = new CustomerInsightService();
        $result = $insightService->analyzeAndTagCustomer($conversation);

        if ($result['success']) {
            // Reload customer to get updated data
            $customer->refresh();

            return response()->json([
                'message' => 'Customer insights generated successfully',
                'insights' => $result['insights'],
                'customer' => $customer,
            ]);
        }

        return response()->json([
            'message' => 'Failed to generate insights',
            'error' => $result['error'] ?? 'Unknown error',
        ], 400);
    }
}
