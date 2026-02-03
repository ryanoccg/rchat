<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Resources\CompanyResource;
use App\Models\AiConfiguration;
use App\Models\AiProvider;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\ActivityLogService;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'company_name' => 'required|string|max:255',
        ]);

        // Create company
        $company = Company::create([
            'name' => $validated['company_name'],
            'slug' => Str::slug($validated['company_name']) . '-' . Str::random(6),
            'email' => $validated['email'],
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        // Create user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'current_company_id' => $company->id,
            'is_active' => true,
        ]);

        // Attach user to company as Company Owner
        $user->companies()->attach($company->id, [
            'role' => 'Company Owner',
            'joined_at' => now(),
        ]);

        // Assign Company Owner role (using web guard which is the default for Spatie)
        if (\Spatie\Permission\Models\Role::where('name', 'Company Owner')->where('guard_name', 'web')->exists()) {
            $user->assignRole('Company Owner');
        }

        // Create default AI configuration for the company
        $this->createDefaultAiConfiguration($company);

        // Generate token
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user->load('currentCompany')),
            'company' => new CompanyResource($company),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated.'],
            ]);
        }

        // Check if 2FA is enabled
        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            return response()->json([
                'two_factor' => true,
                'user_id' => $user->id,
            ]);
        }

        // Update last login
        $user->update(['last_login_at' => now()]);

        // Generate token
        $token = $user->createToken('auth-token')->plainTextToken;

        // Log activity
        ActivityLogService::login($user);

        return response()->json([
            'user' => new UserResource($user->load('currentCompany')),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        // Delete token first for fast response
        $user->currentAccessToken()->delete();

        // Log activity after response (non-blocking)
        dispatch(function () use ($user) {
            ActivityLogService::logout($user);
        })->afterResponse();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    public function user(Request $request)
    {
        return response()->json([
            'user' => new UserResource($request->user()->load(['currentCompany', 'roles', 'permissions'])),
        ]);
    }

    /**
     * Create default AI configuration for a new company
     */
    protected function createDefaultAiConfiguration(Company $company): void
    {
        // Get OpenAI provider (most commonly used)
        $openAiProvider = AiProvider::where('slug', 'openai')->first();

        if (!$openAiProvider) {
            // Fallback to first available provider
            $openAiProvider = AiProvider::where('is_active', true)->first();
        }

        if (!$openAiProvider) {
            return; // No providers available
        }

        // Create default AI configuration
        AiConfiguration::create([
            'company_id' => $company->id,
            'primary_provider_id' => $openAiProvider->id,
            'primary_model' => 'gpt-4o-mini',
            'system_prompt' => "You are a helpful and professional customer service assistant for {$company->name}. Your goal is to assist customers with their inquiries in a friendly, professional, and efficient manner.

Key guidelines:
- Be polite, patient, and empathetic
- Provide clear and accurate information
- If you don't know something, be honest about it
- Keep responses concise but helpful
- Ask clarifying questions when needed",
            'personality_tone' => 'professional',
            'prohibited_topics' => [],
            'custom_instructions' => [],
            'confidence_threshold' => 0.7,
            'auto_respond' => true,
            'max_tokens' => 500,
            'temperature' => 0.7,
        ]);
    }
}
