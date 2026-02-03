<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Company;
use App\Models\User;
use App\Services\Media\MediaLibraryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use App\Services\ActivityLogService;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class SettingsController extends Controller
{
    protected MediaLibraryService $mediaService;

    public function __construct(MediaLibraryService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    /**
     * Get company settings
     */
    public function companySettings(Request $request)
    {
        $company = Company::findOrFail($request->get('company_id'));

        return response()->json([
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'email' => $company->email,
                'phone' => $company->phone,
                'address' => $company->address,
                'timezone' => $company->timezone,
                'business_hours' => $company->business_hours,
                'logo' => $company->logo ?? null,
                'is_active' => $company->is_active,
                'created_at' => $company->created_at,
            ],
        ]);
    }

    /**
     * Update company settings
     */
    public function updateCompanySettings(Request $request)
    {
        $company = Company::findOrFail($request->get('company_id'));

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'timezone' => 'required|string|timezone',
            'business_hours' => 'nullable|array',
            'business_hours.*.day' => 'required_with:business_hours|string',
            'business_hours.*.open' => 'nullable|string',
            'business_hours.*.close' => 'nullable|string',
            'business_hours.*.is_open' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $company->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'timezone' => $request->timezone,
            'business_hours' => $request->business_hours,
        ]);

        ActivityLogService::settingsChanged('company', [
            'name' => $request->name,
            'timezone' => $request->timezone,
        ]);

        return response()->json([
            'message' => 'Company settings updated successfully',
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'email' => $company->email,
                'phone' => $company->phone,
                'address' => $company->address,
                'timezone' => $company->timezone,
                'business_hours' => $company->business_hours,
                'logo' => $company->logo ?? null,
                'is_active' => $company->is_active,
                'created_at' => $company->created_at,
            ],
        ]);
    }

    /**
     * Upload company logo
     * Uses MediaLibraryService for centralized file management
     */
    public function uploadLogo(Request $request)
    {
        // Check if file was uploaded
        if (!$request->hasFile('logo')) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => ['logo' => ['No file was uploaded. Please select an image file.']],
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $company = Company::findOrFail($request->get('company_id'));
        $userId = $request->user()->id;

        // Delete old logo media if exists
        if ($company->logo_media_id) {
            $oldMedia = \App\Models\Media::find($company->logo_media_id);
            if ($oldMedia) {
                $this->mediaService->delete($oldMedia);
            }
        }

        // Store new logo using MediaLibraryService
        $media = $this->mediaService->upload($request->file('logo'), $company->id, $userId, [
            'collection' => 'logos',
            'folder' => 'logos',
            'title' => $company->name . ' Logo',
            'source' => 'company_logo',
        ]);

        // Update company with new logo reference
        $company->logo = $media->url;
        $company->logo_media_id = $media->id;
        $company->save();

        return response()->json([
            'message' => 'Logo uploaded successfully',
            'logo' => $media->url,
            'media_id' => $media->id,
        ]);
    }

    /**
     * Delete company logo
     * Uses MediaLibraryService for centralized file management
     */
    public function deleteLogo(Request $request)
    {
        $company = Company::findOrFail($request->get('company_id'));

        // Delete logo media if exists
        if ($company->logo_media_id) {
            $media = \App\Models\Media::find($company->logo_media_id);
            if ($media) {
                $this->mediaService->delete($media);
            }
        }

        // Also delete legacy logo file if exists
        if ($company->logo && Storage::disk('public')->exists($company->logo)) {
            Storage::disk('public')->delete($company->logo);
        }

        $company->logo = null;
        $company->logo_media_id = null;
        $company->save();

        return response()->json([
            'message' => 'Logo deleted successfully',
        ]);
    }

    /**
     * Get user profile and preferences
     */
    public function userProfile(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar ?? null,
                'preferences' => $user->preferences ?? $this->getDefaultPreferences(),
                'email_verified_at' => $user->email_verified_at,
                'two_factor_enabled' => $user->two_factor_secret !== null,
                'created_at' => $user->created_at,
            ],
        ]);
    }

    /**
     * Update user profile
     */
    public function updateUserProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
        ]);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Update user preferences (notifications, etc.)
     */
    public function updatePreferences(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'preferences' => 'required|array',
            'preferences.email_notifications' => 'boolean',
            'preferences.push_notifications' => 'boolean',
            'preferences.sound_enabled' => 'boolean',
            'preferences.desktop_notifications' => 'boolean',
            'preferences.notification_frequency' => 'in:instant,hourly,daily',
            'preferences.theme' => 'in:light,dark,system',
            'preferences.language' => 'string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $currentPreferences = $user->preferences ?? $this->getDefaultPreferences();
        $user->preferences = array_merge($currentPreferences, $request->preferences);
        $user->save();

        return response()->json([
            'message' => 'Preferences updated successfully',
            'preferences' => $user->preferences,
        ]);
    }

    /**
     * Change user password
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect',
                'errors' => ['current_password' => ['The current password is incorrect.']],
            ], 422);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully',
        ]);
    }

    /**
     * Get API tokens for the user
     */
    public function getApiTokens(Request $request)
    {
        $user = $request->user();

        $tokens = $user->tokens()
            ->select('id', 'name', 'abilities', 'last_used_at', 'created_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'tokens' => $tokens,
        ]);
    }

    /**
     * Create a new API token
     */
    public function createApiToken(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'abilities' => 'nullable|array',
            'abilities.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $abilities = $request->abilities ?? ['*'];
        $token = $user->createToken($request->name, $abilities);

        return response()->json([
            'message' => 'API token created successfully',
            'token' => $token->plainTextToken,
            'token_id' => $token->accessToken->id,
        ], 201);
    }

    /**
     * Delete an API token
     */
    public function deleteApiToken(Request $request, string $tokenId)
    {
        $user = $request->user();

        $token = $user->tokens()->find($tokenId);

        if (!$token) {
            return response()->json([
                'message' => 'Token not found',
            ], 404);
        }

        $token->delete();

        return response()->json([
            'message' => 'API token deleted successfully',
        ]);
    }

    /**
     * Get available timezones
     */
    public function timezones()
    {
        $timezones = collect(timezone_identifiers_list())
            ->map(function ($tz) {
                $offset = now()->setTimezone($tz)->format('P');
                return [
                    'label' => "(UTC{$offset}) {$tz}",
                    'value' => $tz,
                ];
            })
            ->sortBy('label')
            ->values()
            ->all();

        return response()->json([
            'timezones' => $timezones,
        ]);
    }

    /**
     * Enable two-factor authentication - generate secret and QR code
     */
    public function enableTwoFactor(Request $request)
    {
        $user = $request->user();

        if ($user->two_factor_confirmed_at) {
            return response()->json([
                'message' => 'Two-factor authentication is already enabled.',
            ], 422);
        }

        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $user->two_factor_secret = $secret;
        $user->save();

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );

        // Generate SVG QR code
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $qrCodeSvg = $writer->writeString($qrCodeUrl);

        return response()->json([
            'secret' => $secret,
            'qr_code_svg' => $qrCodeSvg,
        ]);
    }

    /**
     * Confirm two-factor authentication with a valid OTP code
     */
    public function confirmTwoFactor(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        if (!$user->two_factor_secret) {
            return response()->json([
                'message' => 'Two-factor authentication has not been initiated.',
            ], 422);
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($user->two_factor_secret, $request->code);

        if (!$valid) {
            return response()->json([
                'message' => 'The provided code is invalid.',
                'errors' => ['code' => ['The provided two-factor authentication code is invalid.']],
            ], 422);
        }

        // Generate recovery codes
        $recoveryCodes = collect(range(1, 8))->map(fn () => Str::random(10))->all();

        $user->two_factor_confirmed_at = now();
        $user->two_factor_recovery_codes = $recoveryCodes;
        $user->save();

        return response()->json([
            'message' => 'Two-factor authentication has been enabled.',
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Disable two-factor authentication
     */
    public function disableTwoFactor(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'password' => 'required|string',
        ]);

        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'The provided password is incorrect.',
                'errors' => ['password' => ['The provided password is incorrect.']],
            ], 422);
        }

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return response()->json([
            'message' => 'Two-factor authentication has been disabled.',
        ]);
    }

    /**
     * Verify a two-factor authentication code during login
     */
    public function verifyTwoFactor(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'code' => 'required|string',
        ]);

        $user = \App\Models\User::findOrFail($request->user_id);

        if (!$user->two_factor_secret || !$user->two_factor_confirmed_at) {
            return response()->json(['message' => '2FA is not enabled.'], 422);
        }

        $google2fa = new Google2FA();

        // Try as OTP code
        if (strlen($request->code) === 6 && $google2fa->verifyKey($user->two_factor_secret, $request->code)) {
            $token = $user->createToken('auth-token')->plainTextToken;
            $user->update(['last_login_at' => now()]);
            ActivityLogService::login($user);

            return response()->json([
                'user' => new UserResource($user->load('currentCompany')),
                'token' => $token,
            ]);
        }

        // Try as recovery code
        $recoveryCodes = $user->two_factor_recovery_codes ?? [];
        if (in_array($request->code, $recoveryCodes)) {
            $user->two_factor_recovery_codes = array_values(array_diff($recoveryCodes, [$request->code]));
            $user->save();

            $token = $user->createToken('auth-token')->plainTextToken;
            $user->update(['last_login_at' => now()]);
            ActivityLogService::login($user);

            return response()->json([
                'user' => new UserResource($user->load('currentCompany')),
                'token' => $token,
            ]);
        }

        return response()->json([
            'message' => 'The provided two-factor authentication code is invalid.',
            'errors' => ['code' => ['Invalid code.']],
        ], 422);
    }

    /**
     * Get recovery codes
     */
    public function getRecoveryCodes(Request $request)
    {
        $user = $request->user();

        if (!$user->two_factor_confirmed_at) {
            return response()->json(['message' => '2FA is not enabled.'], 422);
        }

        return response()->json([
            'recovery_codes' => $user->two_factor_recovery_codes ?? [],
        ]);
    }

    /**
     * Regenerate recovery codes
     */
    public function regenerateRecoveryCodes(Request $request)
    {
        $user = $request->user();

        if (!$user->two_factor_confirmed_at) {
            return response()->json(['message' => '2FA is not enabled.'], 422);
        }

        $recoveryCodes = collect(range(1, 8))->map(fn () => Str::random(10))->all();

        $user->two_factor_recovery_codes = $recoveryCodes;
        $user->save();

        return response()->json([
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Get default user preferences
     */
    protected function getDefaultPreferences(): array
    {
        return [
            'email_notifications' => true,
            'push_notifications' => true,
            'sound_enabled' => true,
            'desktop_notifications' => true,
            'notification_frequency' => 'instant',
            'theme' => 'system',
            'language' => 'en',
        ];
    }
}
