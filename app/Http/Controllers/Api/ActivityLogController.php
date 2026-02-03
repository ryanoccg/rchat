<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Get activity logs for the company
     */
    public function index(Request $request)
    {
        $companyId = $request->get('company_id');
        $perPage = min($request->get('per_page', 20), 100);

        $query = ActivityLog::where('company_id', $companyId)
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc');

        // Filter by action type
        if ($request->has('action')) {
            $query->where('action', $request->action);
        }

        // Filter by user
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by date range
        if ($request->has('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        // Search in description
        if ($request->has('search')) {
            $query->where('description', 'like', '%' . $request->search . '%');
        }

        $logs = $query->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * Get available action types for filtering
     */
    public function actionTypes(Request $request)
    {
        $companyId = $request->get('company_id');

        $actions = ActivityLog::where('company_id', $companyId)
            ->distinct()
            ->pluck('action')
            ->sort()
            ->values();

        return response()->json([
            'actions' => $actions,
        ]);
    }

    /**
     * Get activity log stats
     */
    public function stats(Request $request)
    {
        $companyId = $request->get('company_id');

        $stats = [
            'total' => ActivityLog::where('company_id', $companyId)->count(),
            'today' => ActivityLog::where('company_id', $companyId)
                ->whereDate('created_at', today())
                ->count(),
            'this_week' => ActivityLog::where('company_id', $companyId)
                ->where('created_at', '>=', now()->startOfWeek())
                ->count(),
            'by_action' => ActivityLog::where('company_id', $companyId)
                ->selectRaw('action, count(*) as count')
                ->groupBy('action')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get(),
        ];

        return response()->json($stats);
    }
}
