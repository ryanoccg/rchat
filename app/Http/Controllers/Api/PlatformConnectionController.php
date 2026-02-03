<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformConnectionResource;
use App\Models\MessagingPlatform;
use App\Models\PlatformConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlatformConnectionController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $request->company_id;

        $connections = PlatformConnection::where('company_id', $companyId)
            ->with('messagingPlatform')
            ->latest()
            ->get();

        return response()->json([
            'data' => PlatformConnectionResource::collection($connections),
        ]);
    }

    public function platforms()
    {
        $platforms = MessagingPlatform::where('is_active', true)->get();

        return response()->json([
            'data' => $platforms,
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $request->company_id;

        $platform = MessagingPlatform::findOrFail($request->messaging_platform_id);

        $rules = [
            'messaging_platform_id' => 'required|exists:messaging_platforms,id',
            'platform_account_name' => 'required|string|max:255',
        ];

        foreach ($platform->config_fields as $field => $config) {
            if ($config['required'] ?? false) {
                $rules["credentials.{$field}"] = 'required|string';
            }
        }

        $validated = $request->validate($rules);

        $webhookToken = Str::random(32);
        $webhookUrl = url("/api/webhooks/{$platform->slug}/{$webhookToken}");

        $connection = PlatformConnection::create([
            'company_id' => $companyId,
            'messaging_platform_id' => $validated['messaging_platform_id'],
            'platform_account_name' => $validated['platform_account_name'],
            'credentials' => array_merge($validated['credentials'] ?? [], [
                'webhook_token' => $webhookToken,
            ]),
            'webhook_config' => [
                'url' => $webhookUrl,
                'token' => $webhookToken,
            ],
            'is_active' => true,
            'connected_at' => now(),
        ]);

        $connection->load('messagingPlatform');

        return response()->json([
            'message' => 'Platform connection created successfully',
            'data' => new PlatformConnectionResource($connection),
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        $companyId = $request->company_id;

        $connection = PlatformConnection::where('company_id', $companyId)
            ->with('messagingPlatform')
            ->findOrFail($id);

        return response()->json([
            'data' => new PlatformConnectionResource($connection),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $companyId = $request->company_id;

        $connection = PlatformConnection::where('company_id', $companyId)
            ->findOrFail($id);

        $platform = $connection->messagingPlatform;

        $rules = [
            'platform_account_name' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
        ];

        foreach ($platform->config_fields as $field => $config) {
            $rules["credentials.{$field}"] = 'sometimes|string';
        }

        $validated = $request->validate($rules);

        if (isset($validated['platform_account_name'])) {
            $connection->platform_account_name = $validated['platform_account_name'];
        }

        if (isset($validated['is_active'])) {
            $connection->is_active = $validated['is_active'];
        }

        if (isset($validated['credentials'])) {
            $existingCredentials = $connection->credentials ?? [];
            $connection->credentials = array_merge($existingCredentials, $validated['credentials']);
        }

        $connection->save();
        $connection->load('messagingPlatform');

        return response()->json([
            'message' => 'Platform connection updated successfully',
            'data' => new PlatformConnectionResource($connection),
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $companyId = $request->company_id;

        $connection = PlatformConnection::where('company_id', $companyId)
            ->findOrFail($id);

        $connection->delete();

        return response()->json([
            'message' => 'Platform connection deleted successfully',
        ]);
    }

    public function toggleStatus(Request $request, string $id)
    {
        $companyId = $request->company_id;

        $connection = PlatformConnection::where('company_id', $companyId)
            ->findOrFail($id);

        $connection->is_active = !$connection->is_active;
        $connection->save();

        return response()->json([
            'message' => $connection->is_active ? 'Connection activated' : 'Connection deactivated',
            'data' => new PlatformConnectionResource($connection),
        ]);
    }

    public function testConnection(Request $request, string $id)
    {
        $companyId = $request->company_id;

        $connection = PlatformConnection::where('company_id', $companyId)
            ->with('messagingPlatform')
            ->findOrFail($id);

        // TODO: Implement actual connection testing per platform
        // For now, return a mock successful response
        return response()->json([
            'success' => true,
            'message' => 'Connection test successful',
            'platform' => $connection->messagingPlatform->display_name,
        ]);
    }
}
