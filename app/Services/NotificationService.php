<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Mail\ConversationAssignedMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    /**
     * Create a notification and optionally send email
     */
    public static function notify(
        int $userId,
        int $companyId,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl = null,
        array $data = []
    ): Notification {
        $notification = Notification::create([
            'user_id' => $userId,
            'company_id' => $companyId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
            'data' => $data,
        ]);

        // Send email if user has email notifications enabled
        self::maybeSendEmail($userId, $notification);

        return $notification;
    }

    /**
     * Notify when a conversation is assigned to an agent
     */
    public static function conversationAssigned(
        int $agentId,
        int $companyId,
        int $conversationId,
        string $customerName,
        ?string $assignedByName = null
    ): Notification {
        $title = 'Conversation Assigned';
        $message = $assignedByName
            ? "{$assignedByName} assigned you a conversation with {$customerName}."
            : "You have been assigned a conversation with {$customerName}.";

        return self::notify(
            $agentId,
            $companyId,
            'assignment',
            $title,
            $message,
            "/conversations?id={$conversationId}",
            [
                'conversation_id' => $conversationId,
                'customer_name' => $customerName,
                'assigned_by' => $assignedByName,
            ]
        );
    }

    /**
     * Send email notification if user has it enabled
     */
    protected static function maybeSendEmail(int $userId, Notification $notification): void
    {
        try {
            $user = User::find($userId);
            if (!$user) return;

            $preferences = $user->notification_preferences ?? [];
            $emailEnabled = $preferences['email_notifications'] ?? true;

            if ($emailEnabled && $user->email) {
                Mail::to($user->email)->queue(new ConversationAssignedMail($notification, $user));
            }
        } catch (\Exception $e) {
            Log::error('Failed to send notification email', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
