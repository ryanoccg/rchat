<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->where('company_id', $request->user()->current_company_id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return NotificationResource::collection($notifications);
    }

    public function unreadCount(Request $request)
    {
        $count = Notification::where('user_id', $request->user()->id)
            ->where('company_id', $request->user()->current_company_id)
            ->where('is_read', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markAsRead(Request $request, string $id)
    {
        $notification = Notification::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return new NotificationResource($notification);
    }

    public function markAllAsRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->where('company_id', $request->user()->current_company_id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json(['message' => 'All notifications marked as read']);
    }

    public function destroy(Request $request, string $id)
    {
        $notification = Notification::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $notification->delete();

        return response()->json(['message' => 'Notification deleted']);
    }
}
