<?php

namespace App\Services\Broadcast;

use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Customer;
use App\Models\Conversation;
use App\Models\PlatformConnection;
use App\Jobs\SendBroadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BroadcastService
{
    /**
     * Create a new broadcast draft
     */
    public function createDraft(array $data, int $userId): Broadcast
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'message' => 'required|string',
            'message_type' => 'in:text,image',
            'media_urls' => 'array',
            'media_urls.*' => 'url',
            'platform_connection_id' => 'exists:platform_connections,id',
            'scheduled_at' => 'nullable|date|after:now',
            'filters' => 'array',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->first());
        }

        return DB::transaction(function () use ($data, $userId) {
            $broadcast = Broadcast::create([
                'company_id' => auth()->user()->current_company_id,
                'user_id' => $userId,
                'platform_connection_id' => $data['platform_connection_id'] ?? null,
                'name' => $data['name'],
                'message' => $data['message'],
                'message_type' => $data['message_type'] ?? 'text',
                'media_urls' => $data['media_urls'] ?? null,
                'status' => 'draft',
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'filters' => $data['filters'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            return $broadcast;
        });
    }

    /**
     * Update an existing broadcast
     */
    public function updateBroadcast(Broadcast $broadcast, array $data): Broadcast
    {
        if (!$broadcast->isEditable()) {
            throw new \InvalidArgumentException('Cannot update a broadcast that has been started.');
        }

        $validator = Validator::make($data, [
            'name' => 'sometimes|string|max:255',
            'message' => 'sometimes|string',
            'message_type' => 'in:text,image',
            'media_urls' => 'sometimes|array',
            'media_urls.*' => 'url',
            'platform_connection_id' => 'nullable|exists:platform_connections,id',
            'scheduled_at' => 'nullable|date|after:now',
            'filters' => 'sometimes|array',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->first());
        }

        $broadcast->update($data);

        return $broadcast->fresh();
    }

    /**
     * Get customers based on filters
     */
    public function getRecipientsByFilters(array $filters, int $companyId, ?int $platformConnectionId = null): \Illuminate\Support\Collection
    {
        $query = Customer::where('company_id', $companyId);

        // Filter by platform if specific connection selected
        if ($platformConnectionId) {
            $connection = PlatformConnection::find($platformConnectionId);
            if ($connection) {
                $query->where('messaging_platform_id', $connection->messaging_platform_id);
            }
        }

        // Apply additional filters
        if (!empty($filters['tags'])) {
            $query->whereJsonContains('metadata->tags', $filters['tags']);
        }

        if (!empty($filters['created_after'])) {
            $query->where('created_at', '>=', $filters['created_after']);
        }

        if (!empty($filters['created_before'])) {
            $query->where('created_at', '<=', $filters['created_before']);
        }

        if (!empty($filters['has_conversation'])) {
            $query->whereHas('conversations');
        }

        if (!empty($filters['language'])) {
            $query->where('language', $filters['language']);
        }

        // Exclude specific customers
        if (!empty($filters['exclude_customers'])) {
            $query->whereNotIn('id', $filters['exclude_customers']);
        }

        return $query->get();
    }

    /**
     * Prepare broadcast recipients (create records without sending)
     */
    public function prepareRecipients(Broadcast $broadcast, array $customerIds): void
    {
        if (!$broadcast->isEditable()) {
            throw new \InvalidArgumentException('Cannot modify recipients of a broadcast that has been started.');
        }

        DB::transaction(function () use ($broadcast, $customerIds) {
            // Clear existing recipients
            $broadcast->recipients()->delete();

            $recipients = [];
            $now = now();

            foreach ($customerIds as $customerId) {
                $customer = Customer::find($customerId);
                if (!$customer || $customer->company_id !== $broadcast->company_id) {
                    continue;
                }

                // Get the latest conversation for this customer
                $conversation = Conversation::where('customer_id', $customerId)
                    ->where('company_id', $broadcast->company_id)
                    ->latest()
                    ->first();

                if (!$conversation) {
                    // Create a conversation if none exists
                    $conversation = Conversation::create([
                        'company_id' => $broadcast->company_id,
                        'customer_id' => $customerId,
                        'platform_connection_id' => $broadcast->platform_connection_id,
                        'status' => 'open',
                    ]);
                }

                $recipients[] = [
                    'broadcast_id' => $broadcast->id,
                    'customer_id' => $customerId,
                    'conversation_id' => $conversation->id,
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (!empty($recipients)) {
                BroadcastRecipient::insert($recipients);
                $broadcast->update(['total_recipients' => count($recipients)]);
            }
        });
    }

    /**
     * Send broadcast immediately
     */
    public function sendBroadcast(Broadcast $broadcast): void
    {
        if (!$broadcast->canBeSent()) {
            throw new \InvalidArgumentException('Broadcast cannot be sent. Ensure it is in draft status and has a platform connection.');
        }

        $broadcast->update([
            'status' => 'sending',
            'started_at' => now(),
        ]);

        SendBroadcast::dispatch($broadcast->id);

        Log::info('Broadcast dispatched', [
            'broadcast_id' => $broadcast->id,
            'company_id' => $broadcast->company_id,
        ]);
    }

    /**
     * Schedule broadcast for later
     */
    public function scheduleBroadcast(Broadcast $broadcast, \DateTime $scheduledAt): void
    {
        if (!$broadcast->isEditable()) {
            throw new \InvalidArgumentException('Cannot schedule a broadcast that has been started.');
        }

        if ($scheduledAt <= now()) {
            throw new \InvalidArgumentException('Scheduled time must be in the future.');
        }

        $broadcast->update([
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt,
        ]);

        SendBroadcast::dispatch($broadcast->id)->delay($scheduledAt);

        Log::info('Broadcast scheduled', [
            'broadcast_id' => $broadcast->id,
            'scheduled_at' => $scheduledAt,
        ]);
    }

    /**
     * Cancel a scheduled or sending broadcast
     */
    public function cancelBroadcast(Broadcast $broadcast): void
    {
        if (!in_array($broadcast->status, ['draft', 'scheduled', 'sending'])) {
            throw new \InvalidArgumentException('Cannot cancel a completed or failed broadcast.');
        }

        $broadcast->update([
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);

        Log::info('Broadcast cancelled', [
            'broadcast_id' => $broadcast->id,
        ]);
    }

    /**
     * Get broadcast statistics
     */
    public function getStatistics(Broadcast $broadcast): array
    {
        return [
            'total' => $broadcast->total_recipients,
            'pending' => $broadcast->recipients()->where('status', 'pending')->count(),
            'sending' => $broadcast->recipients()->where('status', 'sending')->count(),
            'sent' => $broadcast->sent_count,
            'delivered' => $broadcast->delivered_count,
            'failed' => $broadcast->failed_count,
            'completion_percentage' => $broadcast->getCompletionPercentage(),
            'success_rate' => $broadcast->getSuccessRate(),
        ];
    }

    /**
     * Estimate recipient count for given filters
     */
    public function estimateRecipients(array $filters, int $companyId, ?int $platformConnectionId = null): int
    {
        return $this->getRecipientsByFilters($filters, $companyId, $platformConnectionId)->count();
    }
}
