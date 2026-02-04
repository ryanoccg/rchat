<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\SatisfactionRating;
use Illuminate\Http\Request;
use App\Http\Resources\ConversationResource;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $companyId = $request->user()->current_company_id;

        // Get conversation stats
        $totalConversations = Conversation::where('company_id', $companyId)->count();
        $activeConversations = Conversation::where('company_id', $companyId)
            ->where('status', 'active')
            ->count();
        $pendingConversations = Conversation::where('company_id', $companyId)
            ->where('status', 'pending')
            ->count();

        // Get message stats for today
        $todayMessages = Message::whereHas('conversation', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })->whereDate('created_at', today())->count();

        // Get customer stats
        $totalCustomers = Customer::where('company_id', $companyId)->count();
        $newCustomersToday = Customer::where('company_id', $companyId)
            ->whereDate('created_at', today())
            ->count();

        // Get satisfaction rating average
        $avgSatisfaction = SatisfactionRating::whereHas('conversation', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })->avg('rating') ?? 0;

        // AI vs Human handled
        $aiHandled = Conversation::where('company_id', $companyId)
            ->where('is_ai_handling', true)
            ->count();

        return response()->json([
            'conversations' => [
                'total' => $totalConversations,
                'active' => $activeConversations,
                'pending' => $pendingConversations,
            ],
            'messages' => [
                'today' => $todayMessages,
            ],
            'customers' => [
                'total' => $totalCustomers,
                'new_today' => $newCustomersToday,
            ],
            'satisfaction' => [
                'average' => round($avgSatisfaction, 1),
            ],
            'ai_stats' => [
                'ai_handled' => $aiHandled,
                'human_handled' => $totalConversations - $aiHandled,
                'ai_percentage' => $totalConversations > 0 
                    ? round(($aiHandled / $totalConversations) * 100, 1) 
                    : 0,
            ],
        ]);
    }

    public function recentConversations(Request $request)
    {
        $companyId = $request->user()->current_company_id;

        $conversations = Conversation::where('company_id', $companyId)
            ->with(['customer', 'assignedAgent', 'latestMessage', 'platformConnection.messagingPlatform'])
            ->latest('last_message_at')
            ->limit(10)
            ->get();

        // Return minimal data optimized for dashboard display
        return response()->json($conversations->map(function ($conversation) {
            return [
                'id' => $conversation->id,
                'status' => $conversation->status,
                'priority' => $conversation->priority,
                'is_ai_handling' => $conversation->is_ai_handling,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'customer' => [
                    'id' => $conversation->customer->id,
                    'name' => $conversation->customer->name,
                    'display_name' => $conversation->customer->display_name,
                    'profile_photo_url' => $conversation->customer->profile_photo_url,
                ],
                'platform' => $conversation->platformConnection && $conversation->platformConnection->relationLoaded('messagingPlatform') && $conversation->platformConnection->messagingPlatform
                    ? [
                        'id' => $conversation->platformConnection->messagingPlatform->id,
                        'name' => $conversation->platformConnection->messagingPlatform->name,
                        'slug' => $conversation->platformConnection->messagingPlatform->slug,
                        'icon' => $conversation->platformConnection->messagingPlatform->icon,
                    ]
                    : null,
                'assigned_agent' => $conversation->assignedAgent ? [
                    'id' => $conversation->assignedAgent->id,
                    'name' => $conversation->assignedAgent->name,
                ] : null,
                'last_message' => $conversation->latestMessage?->content,
            ];
        }));
    }

    /**
     * Get activity feed
     * SECURITY: Transforms results to exclude sensitive internal data like IP addresses.
     */
    public function activityFeed(Request $request)
    {
        $companyId = $request->user()->current_company_id;

        $activities = DB::table('activity_logs')
            ->where('company_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        // Transform to exclude sensitive internal data
        return response()->json($activities->map(fn($activity) => [
            'id' => $activity->id,
            'action' => $activity->action,
            'description' => $activity->description,
            'subject_type' => $activity->subject_type,
            'subject_id' => $activity->subject_id,
            'causer_type' => $activity->causer_type,
            'causer_id' => $activity->causer_id,
            'created_at' => $activity->created_at,
            // Exclude: ip_address, user_agent, and other sensitive fields
        ]));
    }
}
