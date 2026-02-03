<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ActivityLogService
{
    /**
     * Log an activity
     */
    public static function log(
        string $action,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?User $user = null,
        ?int $companyId = null
    ): ?ActivityLog {
        $user = $user ?? Auth::user();

        if (!$user) {
            return null;
        }

        $companyId = $companyId ?? $user->current_company_id;

        if (!$companyId) {
            return null;
        }

        return ActivityLog::create([
            'user_id' => $user->id,
            'company_id' => $companyId,
            'action' => $action,
            'description' => $description,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->id,
            'properties' => !empty($properties) ? $properties : null,
        ]);
    }

    /**
     * Log a create action
     */
    public static function created(Model $model, string $modelName, array $properties = []): ?ActivityLog
    {
        return self::log(
            'created',
            "Created {$modelName}",
            $model,
            $properties
        );
    }

    /**
     * Log an update action
     */
    public static function updated(Model $model, string $modelName, array $changes = []): ?ActivityLog
    {
        return self::log(
            'updated',
            "Updated {$modelName}",
            $model,
            ['changes' => $changes]
        );
    }

    /**
     * Log a delete action
     */
    public static function deleted(Model $model, string $modelName, array $properties = []): ?ActivityLog
    {
        return self::log(
            'deleted',
            "Deleted {$modelName}",
            $model,
            $properties
        );
    }

    /**
     * Log a status change
     */
    public static function statusChanged(Model $model, string $modelName, string $oldStatus, string $newStatus): ?ActivityLog
    {
        return self::log(
            'status_changed',
            "Changed {$modelName} status from {$oldStatus} to {$newStatus}",
            $model,
            ['old_status' => $oldStatus, 'new_status' => $newStatus]
        );
    }

    /**
     * Log user login
     */
    public static function login(User $user): ?ActivityLog
    {
        return self::log(
            'login',
            'User logged in',
            $user,
            ['ip' => request()->ip(), 'user_agent' => request()->userAgent()],
            $user
        );
    }

    /**
     * Log user logout
     */
    public static function logout(User $user): ?ActivityLog
    {
        return self::log(
            'logout',
            'User logged out',
            $user,
            [],
            $user
        );
    }

    /**
     * Log AI configuration change
     */
    public static function aiConfigChanged(array $changes): ?ActivityLog
    {
        return self::log(
            'ai_config_changed',
            'Updated AI configuration',
            null,
            ['changes' => $changes]
        );
    }

    /**
     * Log platform connection
     */
    public static function platformConnected(Model $connection, string $platformName): ?ActivityLog
    {
        return self::log(
            'platform_connected',
            "Connected {$platformName} platform",
            $connection
        );
    }

    /**
     * Log platform disconnection
     */
    public static function platformDisconnected(Model $connection, string $platformName): ?ActivityLog
    {
        return self::log(
            'platform_disconnected',
            "Disconnected {$platformName} platform",
            $connection
        );
    }

    /**
     * Log team member invited
     */
    public static function teamMemberInvited(string $email, string $role): ?ActivityLog
    {
        return self::log(
            'team_member_invited',
            "Invited {$email} as {$role}",
            null,
            ['email' => $email, 'role' => $role]
        );
    }

    /**
     * Log team member removed
     */
    public static function teamMemberRemoved(User $member): ?ActivityLog
    {
        return self::log(
            'team_member_removed',
            "Removed team member {$member->name}",
            $member,
            ['email' => $member->email]
        );
    }

    /**
     * Log knowledge base entry added
     */
    public static function knowledgeBaseAdded(Model $entry, string $title): ?ActivityLog
    {
        return self::log(
            'knowledge_base_added',
            "Added knowledge base entry: {$title}",
            $entry
        );
    }

    /**
     * Log conversation assigned
     */
    public static function conversationAssigned(Model $conversation, ?User $assignee): ?ActivityLog
    {
        $assigneeName = $assignee ? $assignee->name : 'AI';
        return self::log(
            'conversation_assigned',
            "Assigned conversation to {$assigneeName}",
            $conversation,
            ['assignee_id' => $assignee?->id, 'assignee_name' => $assigneeName]
        );
    }

    /**
     * Log settings change
     */
    public static function settingsChanged(string $section, array $changes = []): ?ActivityLog
    {
        return self::log(
            'settings_changed',
            "Updated {$section} settings",
            null,
            ['section' => $section, 'changes' => $changes]
        );
    }

    /**
     * Log product added
     */
    public static function productAdded(Model $product, string $name): ?ActivityLog
    {
        return self::log(
            'product_added',
            "Added product: {$name}",
            $product
        );
    }

    /**
     * Log bulk action
     */
    public static function bulkAction(string $action, string $modelName, int $count): ?ActivityLog
    {
        return self::log(
            "bulk_{$action}",
            "{$action} {$count} {$modelName}(s)",
            null,
            ['count' => $count]
        );
    }

    /**
     * Log media upload
     */
    public static function mediaUploaded(Model $media, string $fileName): ?ActivityLog
    {
        return self::log(
            'media_uploaded',
            "Uploaded media: {$fileName}",
            $media,
            ['media_type' => $media->media_type, 'file_size' => $media->human_size]
        );
    }

    /**
     * Log media deleted
     */
    public static function mediaDeleted(Model $media, string $fileName): ?ActivityLog
    {
        return self::log(
            'media_deleted',
            "Deleted media: {$fileName}",
            $media
        );
    }
}
