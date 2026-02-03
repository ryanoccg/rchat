<?php

namespace App\Services\Media;

use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProfilePhotoService
{
    /**
     * Download profile photo from platform URL and store locally
     *
     * @param string|null $photoUrl Platform profile photo URL
     * @param int $companyId Company ID for organizing storage
     * @param int $customerId Customer ID for filename
     * @return string|null Returns local URL, or null on failure
     */
    public function downloadAndStore(?string $photoUrl, int $companyId, int $customerId): ?string
    {
        if (empty($photoUrl)) {
            return null;
        }

        try {
            // Download the photo
            $imageContent = file_get_contents($photoUrl);
            
            if (empty($imageContent)) {
                Log::warning('ProfilePhotoService: Failed to download photo content', [
                    'url' => $photoUrl,
                ]);
                return null;
            }

            // Detect image type
            $imageInfo = getimagesizefromstring($imageContent);
            if (!$imageInfo) {
                Log::warning('ProfilePhotoService: Not a valid image', [
                    'url' => $photoUrl,
                ]);
                return null;
            }

            $mime = $imageInfo['mime'];
            $extension = $this->getExtensionFromMimeType($mime);
            
            // Generate storage path
            $filename = "customer_{$customerId}_" . Str::uuid() . '.' . $extension;
            $path = "media/{$companyId}/profile-photos/{$filename}";

            // Store in public disk
            Storage::disk('public')->put($path, $imageContent);

            // Generate relative URL (without domain)
            $localUrl = '/storage/' . $path;

            Log::info('ProfilePhotoService: Profile photo stored successfully', [
                'customer_id' => $customerId,
                'company_id' => $companyId,
                'path' => $path,
                'size' => strlen($imageContent),
                'original_url' => $photoUrl,
            ]);

            return $localUrl;

        } catch (\Exception $e) {
            Log::error('ProfilePhotoService: Failed to download and store profile photo', [
                'url' => $photoUrl,
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Update customer profile photo if needed
     * Downloads and stores photo locally if current photo is a platform URL
     *
     * @param \App\Models\Customer $customer Customer model
     * @return string|null Updated profile photo URL or null
     */
    public function updateCustomerProfilePhoto($customer): ?string
    {
        if (!$customer || !$customer->profile_photo_url) {
            return null;
        }

        $currentUrl = $customer->profile_photo_url;

        // Check if it's a platform URL that needs downloading
        // Facebook URLs, temporary URLs, or URLs without our domain
        if ($this->shouldDownloadProfilePhoto($currentUrl)) {
            Log::info('ProfilePhotoService: Downloading platform profile photo', [
                'customer_id' => $customer->id,
                'current_url' => $currentUrl,
            ]);

            $localUrl = $this->downloadAndStore(
                $currentUrl,
                $customer->company_id,
                $customer->id
            );

            if ($localUrl) {
                // Update customer record
                $customer->profile_photo_url = $localUrl;
                $customer->save();

                return $localUrl;
            }
        }

        return $currentUrl;
    }

    /**
     * Check if profile photo URL should be downloaded
     *
     * @param string $url Profile photo URL
     * @return bool True if URL should be downloaded
     */
    protected function shouldDownloadProfilePhoto(string $url): bool
    {
        // Already a local URL
        if (str_contains($url, url('/storage'))) {
            return false;
        }

        // Facebook platform-lookaside URLs (temporary)
        if (str_contains($url, 'platform-lookaside.fbsbx.com')) {
            return true;
        }

        // Facebook CDN URLs
        if (str_contains($url, 'scontent')) {
            return true;
        }

        // Facebook graph API URLs
        if (str_contains($url, 'graph.facebook.com')) {
            return true;
        }

        // WhatsApp profile photos
        if (str_contains($url, 'pp.whatsapp.net')) {
            return true;
        }

        // Telegram profile photos
        if (str_contains($url, 't.me') || str_contains($url, 'telegram.org')) {
            return true;
        }

        // LINE profile photos
        if (str_contains($url, 'profile.line-scdn.net')) {
            return true;
        }

        // Any other external URL
        return !str_contains($url, url('/'));
    }

    /**
     * Get file extension from MIME type
     */
    protected function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeType = explode(';', $mimeType)[0];
        $mimeType = trim($mimeType);

        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/avif' => 'avif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            default => 'jpg',
        };
    }
}
