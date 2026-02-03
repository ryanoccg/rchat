<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,

            // File info
            'file_name' => $this->file_name,
            'file_type' => $this->file_type,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'file_size' => $this->file_size,
            'human_size' => $this->getHumanSize(),

            // URLs
            'url' => $this->url,
            'full_url' => $this->getFullUrl(),
            'thumbnail_url' => $this->thumbnail_url,

            // Storage info
            'disk' => $this->disk,
            'path' => $this->path,

            // Media categorization
            'media_type' => $this->media_type,
            'collection' => $this->collection,
            'folder_path' => $this->folder_path,

            // Metadata
            'metadata' => $this->metadata ?? [],
            'custom_properties' => $this->custom_properties ?? [],
            'conversions' => $this->conversions ?? [],

            // Dimensions (from metadata)
            'width' => $this->getWidth(),
            'height' => $this->getHeight(),
            'duration' => $this->getDuration(),

            // Relationships
            'mediable_type' => $this->mediable_type,
            'mediable_id' => $this->mediable_id,
            'mediable_order' => $this->mediable_order,

            // WordPress-like fields
            'alt' => $this->alt,
            'title' => $this->title,
            'description' => $this->description,
            'caption' => $this->caption,

            // AI data
            'ai_analysis' => $this->ai_analysis,
            'ai_tags' => $this->ai_tags ?? [],

            // Usage tracking
            'usage_count' => $this->usage_count,
            'last_used_at' => $this->last_used_at?->toIso8601String(),

            // Upload info
            'uploaded_by' => $this->uploaded_by,
            'uploader' => $this->whenLoaded('uploader', fn() => [
                'id' => $this->uploader?->id,
                'name' => $this->uploader?->name,
            ]),
            'source' => $this->source,
            'source_url' => $this->source_url,

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Computed
            'is_image' => $this->isImage(),
            'is_video' => $this->isVideo(),
            'is_audio' => $this->isAudio(),
            'is_document' => $this->isDocument(),
            'is_viewable' => $this->isViewable(),
            'icon' => $this->getIcon(),
        ];
    }
}
