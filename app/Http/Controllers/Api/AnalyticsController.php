<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\SatisfactionRating;
use App\Models\SentimentAnalysis;
use App\Models\UsageTracking;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Get overview statistics
     */
    public function overview(Request $request)
    {
        $companyId = $request->company_id;
        $period = $request->get('period', '30'); // days
        $startDate = Carbon::now()->subDays((int) $period)->startOfDay();

        // Total conversations
        $totalConversations = Conversation::where('company_id', $companyId)
            ->where('created_at', '>=', $startDate)
            ->count();

        $previousConversations = Conversation::where('company_id', $companyId)
            ->where('created_at', '>=', $startDate->copy()->subDays($period))
            ->where('created_at', '<', $startDate)
            ->count();

        // Total messages
        $totalMessages = Message::whereHas('conversation', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })
            ->where('created_at', '>=', $startDate)
            ->count();

        // Total customers
        $totalCustomers = Customer::where('company_id', $companyId)
            ->where('created_at', '>=', $startDate)
            ->count();

        // AI handled conversations
        $aiHandled = Conversation::where('company_id', $companyId)
            ->where('created_at', '>=', $startDate)
            ->where('is_ai_handling', true)
            ->count();

        $aiHandleRate = $totalConversations > 0 
            ? round(($aiHandled / $totalConversations) * 100, 1) 
            : 0;

        // Average response time (in minutes)
        $avgResponseTime = $this->calculateAverageResponseTime($companyId, $startDate);

        // Resolution rate
        $resolvedConversations = Conversation::where('company_id', $companyId)
            ->where('created_at', '>=', $startDate)
            ->where('status', 'closed')
            ->count();

        $resolutionRate = $totalConversations > 0 
            ? round(($resolvedConversations / $totalConversations) * 100, 1) 
            : 0;

        return response()->json([
            'data' => [
                'total_conversations' => $totalConversations,
                'conversation_change' => $this->calculateChange($totalConversations, $previousConversations),
                'total_messages' => $totalMessages,
                'total_customers' => $totalCustomers,
                'ai_handle_rate' => $aiHandleRate,
                'avg_response_time' => $avgResponseTime,
                'resolution_rate' => $resolutionRate,
                'period' => $period,
            ],
        ]);
    }

    /**
     * Get conversation trends over time
     */
    public function conversationTrends(Request $request)
    {
        $companyId = $request->company_id;
        $period = $request->get('period', '30');
        $groupBy = $request->get('group_by', 'day'); // day, week, month
        $startDate = Carbon::now()->subDays((int) $period)->startOfDay();

        $dateFormat = match ($groupBy) {
            'week' => '%Y-%u',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $trends = Conversation::where('company_id', $companyId)
            ->where('created_at', '>=', $startDate)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '{$dateFormat}') as date"),
                DB::raw('count(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as resolved"),
                DB::raw("SUM(CASE WHEN is_ai_handling = 1 THEN 1 ELSE 0 END) as ai_handled")
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'data' => $trends,
            'period' => $period,
            'group_by' => $groupBy,
        ]);
    }

    /**
     * Get sentiment analysis trends
     */
    public function sentimentTrends(Request $request)
    {
        $companyId = $request->company_id;
        $period = $request->get('period', '30');
        $startDate = Carbon::now()->subDays((int) $period)->startOfDay();

        // Get sentiment distribution
        $sentimentDistribution = SentimentAnalysis::whereHas('conversation', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })
            ->where('created_at', '>=', $startDate)
            ->select('sentiment', DB::raw('count(*) as count'), DB::raw('avg(score) as avg_score'))
            ->groupBy('sentiment')
            ->get();

        // Get sentiment over time
        $sentimentOverTime = SentimentAnalysis::whereHas('conversation', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })
            ->where('created_at', '>=', $startDate)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d') as date"),
                DB::raw("SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) as positive"),
                DB::raw("SUM(CASE WHEN sentiment = 'neutral' THEN 1 ELSE 0 END) as neutral"),
                DB::raw("SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) as negative"),
                DB::raw('avg(score) as avg_score')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Calculate overall sentiment score
        $overallScore = SentimentAnalysis::whereHas('conversation', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })
            ->where('created_at', '>=', $startDate)
            ->avg('score');

        return response()->json([
            'data' => [
                'distribution' => $sentimentDistribution,
                'over_time' => $sentimentOverTime,
                'overall_score' => round($overallScore ?? 0, 2),
            ],
            'period' => $period,
        ]);
    }

    /**
     * Get customer satisfaction metrics
     */
    public function satisfaction(Request $request)
    {
        $companyId = $request->company_id;
        $period = $request->get('period', '30');
        $startDate = Carbon::now()->subDays((int) $period)->startOfDay();

        // Get satisfaction ratings
        $ratings = SatisfactionRating::whereHas('conversation', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })
            ->where('created_at', '>=', $startDate);

        $avgRating = $ratings->clone()->avg('rating');
        $totalRatings = $ratings->clone()->count();

        // Rating distribution
        $distribution = SatisfactionRating::whereHas('conversation', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })
            ->where('created_at', '>=', $startDate)
            ->select('rating', DB::raw('count(*) as count'))
            ->groupBy('rating')
            ->orderBy('rating')
            ->get();

        // Ratings over time
        $ratingsOverTime = SatisfactionRating::whereHas('conversation', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })
            ->where('created_at', '>=', $startDate)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d') as date"),
                DB::raw('avg(rating) as avg_rating'),
                DB::raw('count(*) as count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // NPS calculation (assuming 1-5 scale: 4-5 promoters, 3 passive, 1-2 detractors)
        $promoters = $ratings->clone()->where('rating', '>=', 4)->count();
        $detractors = $ratings->clone()->where('rating', '<=', 2)->count();
        $nps = $totalRatings > 0 
            ? round((($promoters - $detractors) / $totalRatings) * 100) 
            : 0;

        return response()->json([
            'data' => [
                'avg_rating' => round($avgRating ?? 0, 2),
                'total_ratings' => $totalRatings,
                'distribution' => $distribution,
                'over_time' => $ratingsOverTime,
                'nps' => $nps,
            ],
            'period' => $period,
        ]);
    }

    /**
     * Get platform performance metrics
     */
    public function platformPerformance(Request $request)
    {
        $companyId = $request->company_id;
        $period = $request->get('period', '30');
        $startDate = Carbon::now()->subDays((int) $period)->startOfDay();

        $platformStats = Conversation::where('conversations.company_id', $companyId)
            ->where('conversations.created_at', '>=', $startDate)
            ->join('platform_connections', 'conversations.platform_connection_id', '=', 'platform_connections.id')
            ->join('messaging_platforms', 'platform_connections.messaging_platform_id', '=', 'messaging_platforms.id')
            ->select(
                'messaging_platforms.name as platform',
                'messaging_platforms.slug as platform_slug',
                DB::raw('count(conversations.id) as conversations'),
                DB::raw("SUM(CASE WHEN conversations.status = 'closed' THEN 1 ELSE 0 END) as resolved"),
                DB::raw("SUM(CASE WHEN conversations.is_ai_handling = 1 THEN 1 ELSE 0 END) as ai_handled")
            )
            ->groupBy('messaging_platforms.id', 'messaging_platforms.name', 'messaging_platforms.slug')
            ->get();

        return response()->json([
            'data' => $platformStats,
            'period' => $period,
        ]);
    }

    /**
     * Get agent performance metrics
     */
    public function agentPerformance(Request $request)
    {
        $companyId = $request->company_id;
        $period = $request->get('period', '30');
        $startDate = Carbon::now()->subDays((int) $period)->startOfDay();

        $agentStats = Conversation::where('company_id', $companyId)
            ->where('conversations.created_at', '>=', $startDate)
            ->whereNotNull('assigned_to')
            ->join('users', 'conversations.assigned_to', '=', 'users.id')
            ->select(
                'users.id as agent_id',
                'users.name as agent_name',
                DB::raw('count(conversations.id) as conversations'),
                DB::raw("SUM(CASE WHEN conversations.status = 'closed' THEN 1 ELSE 0 END) as resolved"),
                DB::raw('avg(conversations.ai_confidence_score) as avg_confidence')
            )
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('conversations')
            ->get();

        return response()->json([
            'data' => $agentStats,
            'period' => $period,
        ]);
    }

    /**
     * Get usage statistics
     */
    public function usage(Request $request)
    {
        $companyId = $request->company_id;
        $period = $request->get('period', '30');
        $startDate = Carbon::now()->subDays((int) $period)->startOfDay();

        $usageData = UsageTracking::where('company_id', $companyId)
            ->where('period_date', '>=', $startDate)
            ->orderBy('period_date')
            ->get();

        // Calculate totals
        $totalMessages = $usageData->sum('messages_sent');
        $avgStorage = $usageData->avg('storage_used_mb');
        $avgTeamMembers = $usageData->avg('active_team_members');

        return response()->json([
            'data' => [
                'daily' => $usageData,
                'totals' => [
                    'messages_sent' => $totalMessages,
                    'avg_storage_mb' => round($avgStorage ?? 0, 2),
                    'avg_team_members' => round($avgTeamMembers ?? 0, 1),
                ],
            ],
            'period' => $period,
        ]);
    }

    /**
     * Get hourly distribution of conversations
     */
    public function hourlyDistribution(Request $request)
    {
        $companyId = $request->company_id;
        $period = $request->get('period', '30');
        $startDate = Carbon::now()->subDays((int) $period)->startOfDay();

        $hourlyData = Conversation::where('company_id', $companyId)
            ->where('created_at', '>=', $startDate)
            ->select(
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('count(*) as count')
            )
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        // Fill in missing hours with 0
        $distribution = [];
        for ($h = 0; $h < 24; $h++) {
            $distribution[] = [
                'hour' => $h,
                'label' => sprintf('%02d:00', $h),
                'count' => $hourlyData->get($h)?->count ?? 0,
            ];
        }

        return response()->json([
            'data' => $distribution,
            'period' => $period,
        ]);
    }

    /**
     * Export analytics data
     */
    public function export(Request $request)
    {
        $companyId = $request->company_id;
        $period = $request->get('period', '30');
        $format = $request->get('format', 'json');
        $startDate = Carbon::now()->subDays((int) $period)->startOfDay();

        $data = [
            'generated_at' => now()->toISOString(),
            'period' => "{$period} days",
            'conversations' => Conversation::where('company_id', $companyId)
                ->where('created_at', '>=', $startDate)
                ->select('id', 'status', 'is_ai_handling', 'ai_confidence_score', 'created_at', 'closed_at')
                ->get(),
            'satisfaction' => SatisfactionRating::whereHas('conversation', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            })
                ->where('created_at', '>=', $startDate)
                ->get(),
        ];

        if ($format === 'csv') {
            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, ['Conversation ID', 'Status', 'AI Handling', 'Confidence', 'Created', 'Closed']);
            foreach ($data['conversations'] as $conv) {
                fputcsv($handle, [
                    $conv->id,
                    $conv->status,
                    $conv->is_ai_handling ? 'Yes' : 'No',
                    $conv->ai_confidence_score,
                    $conv->created_at,
                    $conv->closed_at,
                ]);
            }
            rewind($handle);
            $csv = stream_get_contents($handle);
            fclose($handle);

            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="analytics-export.csv"',
            ]);
        }

        return response()->json($data);
    }

    /**
     * Calculate average response time in minutes
     */
    protected function calculateAverageResponseTime(int $companyId, Carbon $startDate): float
    {
        // This would calculate time between customer message and agent/AI response
        // Simplified implementation - in production would query message timestamps
        $avgMinutes = Message::whereHas('conversation', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })
            ->where('created_at', '>=', $startDate)
            ->where('sender_type', '!=', 'customer')
            ->avg(DB::raw("TIMESTAMPDIFF(SECOND, (SELECT created_at FROM messages m2 WHERE m2.conversation_id = messages.conversation_id AND m2.sender_type = 'customer' AND m2.created_at < messages.created_at ORDER BY m2.created_at DESC LIMIT 1), created_at) / 60"));

        return round($avgMinutes ?? 0, 1);
    }

    /**
     * Calculate percentage change
     */
    protected function calculateChange(int $current, int $previous): float
    {
        if ($previous === 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
