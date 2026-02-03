<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BroadcastResource;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Customer;
use App\Models\PlatformConnection;
use App\Services\Broadcast\BroadcastService;
use App\Services\Media\MediaLibraryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Services\ActivityLogService;

class BroadcastController extends Controller
{
    protected BroadcastService $broadcastService;
    protected MediaLibraryService $mediaService;

    public function __construct(BroadcastService $broadcastService, MediaLibraryService $mediaService)
    {
        $this->broadcastService = $broadcastService;
        $this->mediaService = $mediaService;
    }

    /**
     * Get all broadcasts for the company
     */
    public function index(Request $request)
    {
        $companyId = $request->company_id;

        $query = Broadcast::where('company_id', $companyId)
            ->with(['user', 'platformConnection.messagingPlatform'])
            ->withCount([
                'recipients as pending_count' => fn ($q) => $q->where('status', 'pending'),
                'recipients as sending_count' => fn ($q) => $q->where('status', 'sending'),
            ]);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Search by name
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Order by latest
        $broadcasts = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        // Add statistics to each broadcast
        $broadcasts->getCollection()->transform(function ($broadcast) {
            $broadcast->statistics = $this->broadcastService->getStatistics($broadcast);
            return $broadcast;
        });

        return BroadcastResource::collection($broadcasts);
    }

    /**
     * Get a specific broadcast
     */
    public function show(Request $request, $id)
    {
        $companyId = $request->company_id;

        $broadcast = Broadcast::where('company_id', $companyId)
            ->with(['user', 'platformConnection.messagingPlatform'])
            ->findOrFail($id);

        $broadcast->statistics = $this->broadcastService->getStatistics($broadcast);

        return response()->json([
            'data' => new BroadcastResource($broadcast),
        ]);
    }

    /**
     * Create a new broadcast
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
            'message_type' => 'in:text,image',
            'media_urls' => 'array',
            'media_urls.*' => 'url',
            'platform_connection_id' => 'required|exists:platform_connections,id',
            'scheduled_at' => 'nullable|date|after:now',
            'filters' => 'array',
            'notes' => 'nullable|string|max:1000',
        ]);

        $companyId = $request->company_id;

        // Verify platform connection belongs to company
        $connection = PlatformConnection::where('id', $request->platform_connection_id)
            ->where('company_id', $companyId)
            ->first();

        if (!$connection) {
            return response()->json([
                'message' => 'Platform connection not found',
            ], 404);
        }

        if (!$connection->is_active) {
            return response()->json([
                'message' => 'Platform connection is not active',
            ], 400);
        }

        try {
            $broadcast = $this->broadcastService->createDraft($validated, $request->user()->id);

            // Get recipients based on filters
            $customerIds = $this->broadcastService
                ->getRecipientsByFilters($validated['filters'] ?? [], $companyId, $connection->id)
                ->pluck('id')
                ->toArray();

            // Prepare recipients
            if (!empty($customerIds)) {
                $this->broadcastService->prepareRecipients($broadcast, $customerIds);
            }

            // Load relationships and statistics
            $broadcast->load(['user', 'platformConnection.messagingPlatform']);
            $broadcast->statistics = $this->broadcastService->getStatistics($broadcast);

            ActivityLogService::log('broadcast_created', "Created broadcast: {$broadcast->name}", $broadcast, [
                'broadcast_name' => $broadcast->name,
                'broadcast_id' => $broadcast->id,
            ]);

            return response()->json([
                'message' => 'Broadcast created successfully',
                'data' => new BroadcastResource($broadcast),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create broadcast', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Update a broadcast
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'message' => 'sometimes|string|max:5000',
            'message_type' => 'in:text,image',
            'media_urls' => 'sometimes|array',
            'media_urls.*' => 'url',
            'platform_connection_id' => 'nullable|exists:platform_connections,id',
            'scheduled_at' => 'nullable|date|after:now',
            'filters' => 'sometimes|array',
            'notes' => 'nullable|string|max:1000',
        ]);

        $companyId = $request->company_id;

        $broadcast = Broadcast::where('company_id', $companyId)
            ->findOrFail($id);

        // Verify platform connection belongs to company if provided
        if (!empty($validated['platform_connection_id'])) {
            $connection = PlatformConnection::where('id', $validated['platform_connection_id'])
                ->where('company_id', $companyId)
                ->first();

            if (!$connection) {
                return response()->json([
                    'message' => 'Platform connection not found',
                ], 404);
            }
        }

        try {
            $broadcast = $this->broadcastService->updateBroadcast($broadcast, $validated);

            // If filters changed, update recipients
            if (isset($validated['filters']) || isset($validated['platform_connection_id'])) {
                $platformConnectionId = $broadcast->platform_connection_id;
                $filters = $validated['filters'] ?? $broadcast->filters ?? [];

                $customerIds = $this->broadcastService
                    ->getRecipientsByFilters($filters, $companyId, $platformConnectionId)
                    ->pluck('id')
                    ->toArray();

                $this->broadcastService->prepareRecipients($broadcast, $customerIds);
            }

            $broadcast->load(['user', 'platformConnection.messagingPlatform']);
            $broadcast->statistics = $this->broadcastService->getStatistics($broadcast);

            return response()->json([
                'message' => 'Broadcast updated successfully',
                'data' => new BroadcastResource($broadcast),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update broadcast', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Send a broadcast immediately
     */
    public function send(Request $request, $id)
    {
        $companyId = $request->company_id;

        $broadcast = Broadcast::where('company_id', $companyId)
            ->findOrFail($id);

        if ($broadcast->total_recipients === 0) {
            return response()->json([
                'message' => 'Cannot send broadcast with no recipients',
            ], 400);
        }

        try {
            $this->broadcastService->sendBroadcast($broadcast);

            ActivityLogService::log('broadcast_sent', "Sent broadcast: {$broadcast->name}", $broadcast, [
                'broadcast_name' => $broadcast->name,
                'broadcast_id' => $broadcast->id,
            ]);

            return response()->json([
                'message' => 'Broadcast is being sent',
                'data' => new BroadcastResource($broadcast->fresh(['platformConnection'])),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send broadcast', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Schedule a broadcast for later
     */
    public function schedule(Request $request, $id)
    {
        $validated = $request->validate([
            'scheduled_at' => 'required|date|after:now',
        ]);

        $companyId = $request->company_id;

        $broadcast = Broadcast::where('company_id', $companyId)
            ->findOrFail($id);

        if ($broadcast->total_recipients === 0) {
            return response()->json([
                'message' => 'Cannot schedule broadcast with no recipients',
            ], 400);
        }

        try {
            $scheduledAt = Carbon::parse($validated['scheduled_at']);
            $this->broadcastService->scheduleBroadcast($broadcast, $scheduledAt);

            ActivityLogService::log('broadcast_scheduled', "Scheduled broadcast: {$broadcast->name}", $broadcast, [
                'broadcast_name' => $broadcast->name,
                'broadcast_id' => $broadcast->id,
                'scheduled_at' => $scheduledAt->toISOString(),
            ]);

            return response()->json([
                'message' => 'Broadcast scheduled successfully',
                'data' => new BroadcastResource($broadcast->fresh()),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to schedule broadcast', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Cancel a broadcast
     */
    public function cancel(Request $request, $id)
    {
        $companyId = $request->company_id;

        $broadcast = Broadcast::where('company_id', $companyId)
            ->findOrFail($id);

        try {
            $this->broadcastService->cancelBroadcast($broadcast);

            ActivityLogService::log('broadcast_cancelled', "Cancelled broadcast: {$broadcast->name}", $broadcast, [
                'broadcast_name' => $broadcast->name,
                'broadcast_id' => $broadcast->id,
            ]);

            return response()->json([
                'message' => 'Broadcast cancelled successfully',
                'data' => new BroadcastResource($broadcast->fresh()),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cancel broadcast', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Delete a broadcast (soft delete)
     */
    public function destroy(Request $request, $id)
    {
        $companyId = $request->company_id;

        $broadcast = Broadcast::where('company_id', $companyId)
            ->findOrFail($id);

        if (!$broadcast->isEditable()) {
            return response()->json([
                'message' => 'Cannot delete a broadcast that has been started',
            ], 400);
        }

        $broadcastName = $broadcast->name;
        $broadcastId = $broadcast->id;
        $broadcast->delete();

        ActivityLogService::log('broadcast_deleted', "Deleted broadcast: {$broadcastName}", null, [
            'broadcast_name' => $broadcastName,
            'broadcast_id' => $broadcastId,
        ]);

        return response()->json([
            'message' => 'Broadcast deleted successfully',
        ]);
    }

    /**
     * Get broadcast recipients
     */
    public function recipients(Request $request, $id)
    {
        $companyId = $request->company_id;

        $broadcast = Broadcast::where('company_id', $companyId)
            ->findOrFail($id);

        $query = BroadcastRecipient::where('broadcast_id', $id)
            ->with(['customer', 'conversation']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $recipients = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 50));

        return response()->json($recipients);
    }

    /**
     * Estimate recipients count based on filters
     */
    public function estimate(Request $request)
    {
        $validated = $request->validate([
            'filters' => 'array',
            'platform_connection_id' => 'required|exists:platform_connections,id',
        ]);

        $companyId = $request->company_id;

        // Verify platform connection belongs to company
        $connection = PlatformConnection::where('id', $validated['platform_connection_id'])
            ->where('company_id', $companyId)
            ->first();

        if (!$connection) {
            return response()->json([
                'message' => 'Platform connection not found',
            ], 404);
        }

        try {
            $count = $this->broadcastService->estimateRecipients(
                $validated['filters'] ?? [],
                $companyId,
                $connection->id
            );

            return response()->json([
                'estimated_recipients' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Upload an image for broadcast
     * Uses MediaLibraryService for centralized file management
     */
    public function uploadImage(Request $request)
    {
        $validated = $request->validate([
            'image' => 'required|image|max:10240', // 10MB max
        ]);

        $companyId = $request->company_id;
        $userId = $request->user()->id;

        try {
            // Store image using MediaLibraryService
            $media = $this->mediaService->upload($validated['image'], $companyId, $userId, [
                'collection' => 'broadcasts',
                'folder' => 'images',
                'title' => 'Broadcast Image',
                'source' => 'broadcast_upload',
            ]);

            Log::info('Broadcast image uploaded', [
                'media_id' => $media->id,
                'path' => $media->path,
                'url' => $media->url,
                'size' => $media->file_size,
            ]);

            return response()->json([
                'url' => $media->url,
                'media_id' => $media->id,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to upload broadcast image', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to upload image',
            ], 500);
        }
    }

    /**
     * Get broadcast statistics
     */
    public function statistics(Request $request, $id)
    {
        $companyId = $request->company_id;

        $broadcast = Broadcast::where('company_id', $companyId)
            ->findOrFail($id);

        $statistics = $this->broadcastService->getStatistics($broadcast);

        return response()->json($statistics);
    }
}
