<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiConfigurationResource;
use App\Models\AiConfiguration;
use App\Models\AiProvider;
use App\Services\AI\AiServiceFactory;
use App\Services\AI\AiRateLimiter;
use Illuminate\Http\Request;

class AiConfigurationController extends Controller
{
    /**
     * Get available AI providers
     */
    public function providers()
    {
        $providers = AiProvider::where('is_active', true)->get();

        return response()->json([
            'data' => $providers,
        ]);
    }

    /**
     * Get the company's AI configuration
     */
    public function show(Request $request)
    {
        $companyId = $request->company_id;

        $config = AiConfiguration::where('company_id', $companyId)
            ->with(['primaryProvider', 'fallbackProvider'])
            ->first();

        return response()->json([
            'data' => $config ? new AiConfigurationResource($config) : null,
        ]);
    }

    /**
     * Create or update the company's AI configuration
     */
    public function store(Request $request)
    {
        $companyId = $request->company_id;

        $validated = $request->validate([
            'primary_provider_id' => 'required|exists:ai_providers,id',
            'fallback_provider_id' => 'nullable|exists:ai_providers,id',
            'primary_model' => 'required|string',
            'system_prompt' => 'nullable|string|max:5000',
            'personality_tone' => 'nullable|string|max:255',
            'prohibited_topics' => 'nullable|array',
            'prohibited_topics.*' => 'string',
            'custom_instructions' => 'nullable|array',
            'custom_instructions.*' => 'string',
            'confidence_threshold' => 'nullable|numeric|min:0|max:1',
            'auto_respond' => 'boolean',
            'response_delay_seconds' => 'nullable|integer|min:0|max:300',
            'max_tokens' => 'nullable|integer|min:100|max:4096',
            'temperature' => 'nullable|numeric|min:0|max:2',
        ]);

        // Convert confidence_threshold from 0-1 to 0-100 for database storage
        if (isset($validated['confidence_threshold'])) {
            $validated['confidence_threshold'] = (int) round($validated['confidence_threshold'] * 100);
        }

        $config = AiConfiguration::updateOrCreate(
            ['company_id' => $companyId],
            $validated
        );

        $config->load(['primaryProvider', 'fallbackProvider']);

        return response()->json([
            'message' => 'AI configuration saved successfully',
            'data' => new AiConfigurationResource($config),
        ]);
    }

    /**
     * Update specific fields of the AI configuration
     */
    public function update(Request $request)
    {
        $companyId = $request->company_id;

        $config = AiConfiguration::where('company_id', $companyId)->first();

        if (!$config) {
            return response()->json([
                'message' => 'AI configuration not found. Please create one first.',
            ], 404);
        }

        $validated = $request->validate([
            'primary_provider_id' => 'sometimes|exists:ai_providers,id',
            'fallback_provider_id' => 'nullable|exists:ai_providers,id',
            'primary_model' => 'sometimes|string',
            'system_prompt' => 'nullable|string|max:5000',
            'personality_tone' => 'nullable|string|max:255',
            'prohibited_topics' => 'nullable|array',
            'custom_instructions' => 'nullable|array',
            'confidence_threshold' => 'nullable|numeric|min:0|max:1',
            'auto_respond' => 'sometimes|boolean',
            'response_delay_seconds' => 'nullable|integer|min:0|max:300',
            'max_tokens' => 'nullable|integer|min:100|max:4096',
            'temperature' => 'nullable|numeric|min:0|max:2',
        ]);

        // Convert confidence_threshold from 0-1 to 0-100 for database storage
        if (isset($validated['confidence_threshold'])) {
            $validated['confidence_threshold'] = (int) round($validated['confidence_threshold'] * 100);
        }

        $config->update($validated);
        $config->load(['primaryProvider', 'fallbackProvider']);

        return response()->json([
            'message' => 'AI configuration updated successfully',
            'data' => new AiConfigurationResource($config),
        ]);
    }

    /**
     * Test the AI configuration
     */
    public function test(Request $request)
    {
        $companyId = $request->company_id;

        $config = AiConfiguration::where('company_id', $companyId)
            ->with('primaryProvider')
            ->first();

        if (!$config) {
            return response()->json([
                'success' => false,
                'message' => 'AI is not configured',
            ], 400);
        }

        $testMessage = $request->input('message', 'Hello, this is a test message. Please respond briefly.');

        try {
            $provider = AiServiceFactory::fromConfiguration($config);
            
            $response = $provider->generateResponse(
                $config->system_prompt ?? 'You are a helpful assistant.',
                $testMessage,
                [
                    'model' => $config->primary_model,
                    'max_tokens' => 150,
                    'temperature' => $config->temperature ?? 0.7,
                ]
            );

            if (!$response->isSuccessful()) {
                return response()->json([
                    'success' => false,
                    'message' => $response->getError(),
                    'provider' => $config->primaryProvider->display_name,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'AI test successful',
                'provider' => $config->primaryProvider->display_name,
                'model' => $response->getModel(),
                'response' => $response->getContent(),
                'usage' => $response->getUsage(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'provider' => $config->primaryProvider->display_name ?? 'Unknown',
            ], 500);
        }
    }

    /**
     * Toggle auto-respond setting
     */
    public function toggleAutoRespond(Request $request)
    {
        $companyId = $request->company_id;

        $config = AiConfiguration::where('company_id', $companyId)->first();

        if (!$config) {
            return response()->json([
                'message' => 'AI configuration not found',
            ], 404);
        }

        $config->auto_respond = !$config->auto_respond;
        $config->save();

        return response()->json([
            'message' => $config->auto_respond ? 'Auto-respond enabled' : 'Auto-respond disabled',
            'auto_respond' => $config->auto_respond,
        ]);
    }

    /**
     * Get available models for a specific provider
     */
    public function getModels(string $providerId)
    {
        $provider = AiProvider::findOrFail($providerId);

        return response()->json([
            'data' => $provider->available_models ?? [],
        ]);
    }

    /**
     * Get rate limit usage for the current provider/model
     */
    public function rateLimitUsage(Request $request)
    {
        $companyId = $request->company_id;

        $config = AiConfiguration::where('company_id', $companyId)
            ->with('primaryProvider')
            ->first();

        if (!$config || !$config->primaryProvider) {
            return response()->json([
                'data' => null,
                'message' => 'AI not configured',
            ]);
        }

        $providerSlug = $config->primaryProvider->slug;
        $model = $config->primary_model;

        $currentUsage = AiRateLimiter::getUsage($providerSlug, $model);
        $allUsage = AiRateLimiter::getAllUsage($providerSlug);

        return response()->json([
            'data' => [
                'provider' => $providerSlug,
                'model' => $model,
                'current' => $currentUsage,
                'all_models' => $allUsage,
            ],
        ]);
    }

}
