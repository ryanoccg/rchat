<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Services\ActivityLogService;
use App\Services\Media\MediaLibraryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class MediaController extends Controller
{
    protected MediaLibraryService $mediaService;

    public function __construct(MediaLibraryService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    /**
     * Display a listing of media files
     */
    public function index(Request $request)
    {
        $query = Media::where('company_id', $request->company_id);

        // Filter by media type
        if ($request->has('type') && $request->type) {
            $query->ofType($request->type);
        }

        // Filter by collection
        if ($request->has('collection') && $request->collection) {
            $query->inCollection($request->collection);
        }

        // Filter by folder
        if ($request->has('folder')) {
            $query->inFolder($request->folder);
        }

        // Filter by attachment (attached/unattached)
        if ($request->has('attached')) {
            if ($request->boolean('attached')) {
                $query->whereNotNull('mediable_type')
                    ->whereNotNull('mediable_id');
            } else {
                $query->whereNull('mediable_type')
                    ->orWhereNull('mediable_id');
            }
        }

        // Search
        if ($request->has('search') && $request->search) {
            $query->search($request->search);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $allowedSorts = ['file_name', 'file_size', 'media_type', 'created_at', 'last_used_at', 'usage_count'];

        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        // Pagination
        $perPage = min($request->get('per_page', 24), 100);
        $media = $query->paginate($perPage);

        return MediaResource::collection($media);
    }

    /**
     * Store a newly uploaded media file
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|max:' . (config('media.max_file_size_mb', 50) * 1024),
            'collection' => 'nullable|string|max:50',
            'folder' => 'nullable|string|max:255',
            'alt' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'caption' => 'nullable|string|max:1000',
            'ai_analyze' => 'boolean',
        ]);

        if (!$request->hasFile('file')) {
            return response()->json([
                'message' => 'No file provided',
                'errors' => ['file' => ['A file is required']],
            ], 422);
        }

        try {
            $media = $this->mediaService->upload(
                $request->file('file'),
                $request->company_id,
                $request->user()->id,
                [
                    'collection' => $validated['collection'] ?? null,
                    'folder' => $validated['folder'] ?? null,
                    'alt' => $validated['alt'] ?? null,
                    'title' => $validated['title'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'caption' => $validated['caption'] ?? null,
                    'ai_analyze' => $request->boolean('ai_analyze', true),
                ]
            );

            ActivityLogService::mediaUploaded($media, $media->file_name);

            return response()->json([
                'message' => 'File uploaded successfully',
                'media' => new MediaResource($media),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            // File size or validation error - return 413 if file too large
            if (str_contains($e->getMessage(), 'size exceeds')) {
                return response()->json([
                    'message' => 'File size too large',
                    'error' => $e->getMessage(),
                ], 413);
            }

            return response()->json([
                'message' => 'Invalid file',
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to upload media', [
                'error' => $e->getMessage(),
                'company_id' => $request->company_id,
            ]);

            return response()->json([
                'message' => 'Failed to upload file',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload multiple files
     */
    public function bulkUpload(Request $request)
    {
        $validated = $request->validate([
            'files' => 'required|array|min:1|max:20',
            'files.*' => 'required|file|max:' . (config('media.max_file_size_mb', 50) * 1024),
            'collection' => 'nullable|string|max:50',
            'folder' => 'nullable|string|max:255',
            'ai_analyze' => 'boolean',
        ]);

        $uploaded = [];
        $failed = [];

        foreach ($request->file('files') as $index => $file) {
            try {
                $media = $this->mediaService->upload(
                    $file,
                    $request->company_id,
                    $request->user()->id,
                    [
                        'collection' => $validated['collection'] ?? null,
                        'folder' => $validated['folder'] ?? null,
                        'ai_analyze' => $request->boolean('ai_analyze', true),
                    ]
                );
                $uploaded[] = new MediaResource($media);
            } catch (\InvalidArgumentException $e) {
                $errorMsg = str_contains($e->getMessage(), 'size exceeds')
                    ? 'File size too large: ' . $e->getMessage()
                    : $e->getMessage();

                $failed[] = [
                    'file' => $file->getClientOriginalName(),
                    'error' => $errorMsg,
                    'code' => str_contains($e->getMessage(), 'size exceeds') ? 413 : 422,
                ];
            } catch (\Exception $e) {
                $failed[] = [
                    'file' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                    'code' => 500,
                ];
            }
        }

        ActivityLogService::bulkAction('uploaded', 'media', count($uploaded));

        return response()->json([
            'message' => count($uploaded) . ' files uploaded successfully',
            'uploaded' => $uploaded,
            'failed' => $failed,
        ], 201);
    }

    /**
     * Import media from URL
     */
    public function importFromUrl(Request $request)
    {
        $validated = $request->validate([
            'url' => 'required|url|max:1000',
            'file_name' => 'nullable|string|max:255',
            'collection' => 'nullable|string|max:50',
            'folder' => 'nullable|string|max:255',
            'ai_analyze' => 'boolean',
        ]);

        try {
            $media = $this->mediaService->importFromUrl(
                $validated['url'],
                $request->company_id,
                $request->user()->id,
                [
                    'file_name' => $validated['file_name'] ?? null,
                    'collection' => $validated['collection'] ?? null,
                    'folder' => $validated['folder'] ?? null,
                    'ai_analyze' => $request->boolean('ai_analyze', true),
                ]
            );

            if (!$media) {
                return response()->json([
                    'message' => 'Failed to import file from URL',
                    'error' => 'Unable to download or process the file from the provided URL',
                ], 422);
            }

            ActivityLogService::mediaUploaded($media, $media->file_name . ' (imported)');

            return response()->json([
                'message' => 'File imported successfully',
                'media' => new MediaResource($media),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'size exceeds')) {
                return response()->json([
                    'message' => 'File size too large',
                    'error' => $e->getMessage(),
                ], 413);
            }

            return response()->json([
                'message' => 'Invalid file',
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to import media from URL', [
                'url' => $validated['url'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to import file',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified media
     */
    public function show(Request $request, Media $media)
    {
        if ($media->company_id !== $request->company_id) {
            return response()->json(['message' => 'Media not found'], 404);
        }

        return new MediaResource($media);
    }

    /**
     * Update media metadata
     */
    public function update(Request $request, Media $media)
    {
        if ($media->company_id !== $request->company_id) {
            return response()->json(['message' => 'Media not found'], 404);
        }

        $validated = $request->validate([
            'file_name' => 'nullable|string|max:255',
            'alt' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'caption' => 'nullable|string|max:1000',
            'folder_path' => 'nullable|string|max:255',
            'collection' => 'nullable|string|max:50',
            'custom_properties' => 'nullable|array',
        ]);

        $media->update($validated);

        return response()->json([
            'message' => 'Media updated successfully',
            'media' => new MediaResource($media),
        ]);
    }

    /**
     * Remove the specified media
     */
    public function destroy(Request $request, Media $media)
    {
        if ($media->company_id !== $request->company_id) {
            return response()->json(['message' => 'Media not found'], 404);
        }

        $this->mediaService->delete($media);

        ActivityLogService::deleted($media, "media: {$media->file_name}");

        return response()->json([
            'message' => 'Media deleted successfully',
        ]);
    }

    /**
     * Bulk delete media files
     */
    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'exists:media,id',
        ]);

        // Verify ownership
        $mediaIds = Media::where('company_id', $request->company_id)
            ->whereIn('id', $validated['ids'])
            ->pluck('id')
            ->toArray();

        $deleted = $this->mediaService->bulkDelete($mediaIds);

        ActivityLogService::bulkAction('deleted', 'media', $deleted);

        return response()->json([
            'message' => "{$deleted} media files deleted successfully",
            'deleted' => $deleted,
        ]);
    }

    /**
     * Copy media file
     */
    public function copy(Request $request, Media $media)
    {
        if ($media->company_id !== $request->company_id) {
            return response()->json(['message' => 'Media not found'], 404);
        }

        $validated = $request->validate([
            'file_name' => 'nullable|string|max:255',
        ]);

        $copy = $this->mediaService->copy($media, $validated['file_name'] ?? null);

        if (!$copy) {
            return response()->json([
                'message' => 'Failed to copy media file',
            ], 422);
        }

        ActivityLogService::created($copy, "media copy: {$media->file_name}");

        return response()->json([
            'message' => 'Media copied successfully',
            'media' => new MediaResource($copy),
        ], 201);
    }

    /**
     * Move media to folder
     */
    public function moveToFolder(Request $request, Media $media)
    {
        if ($media->company_id !== $request->company_id) {
            return response()->json(['message' => 'Media not found'], 404);
        }

        $validated = $request->validate([
            'folder' => 'required|string|max:255',
        ]);

        $success = $this->mediaService->moveToFolder($media, $validated['folder']);

        if (!$success) {
            return response()->json([
                'message' => 'Failed to move media file',
            ], 422);
        }

        return response()->json([
            'message' => 'Media moved successfully',
            'media' => new MediaResource($media->fresh()),
        ]);
    }

    /**
     * Attach media to a model
     */
    public function attach(Request $request, Media $media)
    {
        if ($media->company_id !== $request->company_id) {
            return response()->json(['message' => 'Media not found'], 404);
        }

        $validated = $request->validate([
            'mediable_type' => 'required|string|max:100',
            'mediable_id' => 'required|integer',
            'order' => 'nullable|integer|min:0',
        ]);

        $this->mediaService->attachToModel(
            $media,
            $validated['mediable_type'],
            $validated['mediable_id'],
            $validated['order'] ?? 0
        );

        return response()->json([
            'message' => 'Media attached successfully',
            'media' => new MediaResource($media->fresh()),
        ]);
    }

    /**
     * Detach media from model
     */
    public function detach(Request $request, Media $media)
    {
        if ($media->company_id !== $request->company_id) {
            return response()->json(['message' => 'Media not found'], 404);
        }

        $this->mediaService->detachFromModel($media);

        return response()->json([
            'message' => 'Media detached successfully',
            'media' => new MediaResource($media->fresh()),
        ]);
    }

    /**
     * Get storage usage statistics
     */
    public function storageUsage(Request $request)
    {
        $usage = $this->mediaService->getStorageUsage($request->company_id);

        return response()->json($usage);
    }

    /**
     * Get all folders
     */
    public function folders(Request $request)
    {
        $folders = Media::where('company_id', $request->company_id)
            ->whereNotNull('folder_path')
            ->where('folder_path', '!=', '')
            ->select('folder_path')
            ->distinct()
            ->get()
            ->pluck('folder_path')
            ->sort()
            ->values();

        return response()->json([
            'folders' => $folders,
        ]);
    }

    /**
     * Get media by collection
     */
    public function byCollection(Request $request, string $collection)
    {
        $query = Media::where('company_id', $request->company_id)
            ->inCollection($collection);

        // Filter by media type
        if ($request->has('type') && $request->type) {
            $query->ofType($request->type);
        }

        $media = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 24));

        return MediaResource::collection($media);
    }

    /**
     * Get media for a specific model (polymorphic)
     */
    public function forModel(Request $request)
    {
        $validated = $request->validate([
            'mediable_type' => 'required|string',
            'mediable_id' => 'required|integer',
        ]);

        $media = Media::where('company_id', $request->company_id)
            ->where('mediable_type', $validated['mediable_type'])
            ->where('mediable_id', $validated['mediable_id'])
            ->orderBy('mediable_order')
            ->orderBy('created_at')
            ->get();

        return MediaResource::collection($media);
    }

    /**
     * Reorder media for a model
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'media' => 'required|array|min:1',
            'media.*.id' => 'required|exists:media,id',
            'media.*.order' => 'required|integer|min:0',
        ]);

        foreach ($validated['media'] as $item) {
            $media = Media::where('company_id', $request->company_id)
                ->where('id', $item['id'])
                ->first();

            if ($media) {
                $media->update(['mediable_order' => $item['order']]);
            }
        }

        return response()->json([
            'message' => 'Media reordered successfully',
        ]);
    }

    /**
     * Trigger AI analysis for media
     */
    public function analyze(Request $request, Media $media)
    {
        if ($media->company_id !== $request->company_id) {
            return response()->json(['message' => 'Media not found'], 404);
        }

        if ($media->media_type !== Media::TYPE_IMAGE) {
            return response()->json([
                'message' => 'AI analysis is only available for images',
            ], 422);
        }

        // This would trigger a job - for now we just mark it
        dispatch(function () use ($media) {
            try {
                $processor = new \App\Services\Media\Processors\ImageProcessor();
                $fullPath = \Illuminate\Support\Facades\Storage::disk($media->disk)->path($media->path);
                $imageContent = base64_encode(file_get_contents($fullPath));

                $analysis = $processor->process($imageContent, $media->mime_type, [
                    'prompt' => 'Describe this image in detail. Include: main subjects, colors, mood, setting, and any text visible.',
                ]);

                if ($analysis->isSuccessful()) {
                    $media->update([
                        'ai_analysis' => $analysis->getTextContent(),
                        'ai_tags' => $this->extractTags($analysis->getTextContent()),
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to analyze media with AI', [
                    'media_id' => $media->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return response()->json([
            'message' => 'AI analysis started',
        ]);
    }

    /**
     * Extract tags from text
     */
    protected function extractTags(string $text): array
    {
        $words = str_word_count(strtolower($text), 1);
        $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'this', 'that', 'with', 'from', 'have', 'has', 'been', 'image', 'shows', 'visible'];

        return array_values(array_unique(array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) > 3 && !in_array($word, $stopWords);
        })));
    }
}
