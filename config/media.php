<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Media Library Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for the WordPress-like media library system.
    | Handles file uploads, storage, thumbnails, and media management.
    |
    */

    // Enable/disable media library globally
    'enabled' => env('MEDIA_LIBRARY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Size Limits
    |--------------------------------------------------------------------------
    */

    // Maximum image file size in MB
    'max_image_size_mb' => env('MEDIA_MAX_IMAGE_SIZE_MB', 20),

    // Maximum audio file size in MB
    'max_audio_size_mb' => env('MEDIA_MAX_AUDIO_SIZE_MB', 25),

    // Maximum video file size in MB
    'max_video_size_mb' => env('MEDIA_MAX_VIDEO_SIZE_MB', 100),

    // Maximum document file size in MB
    'max_document_size_mb' => env('MEDIA_MAX_DOCUMENT_SIZE_MB', 50),

    // Maximum general file size in MB
    'max_file_size_mb' => env('MEDIA_MAX_FILE_SIZE_MB', 50),

    // Maximum audio duration in seconds
    'max_audio_duration_seconds' => env('MEDIA_MAX_AUDIO_DURATION_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Image Processing
    |--------------------------------------------------------------------------
    */

    // Enable automatic thumbnail generation
    'image_processing_enabled' => env('MEDIA_IMAGE_PROCESSING_ENABLED', true),

    // Thumbnail sizes (name => [width, height])
    'thumbnail_sizes' => [
        'thumbnail' => [150, 150],
        'small' => [300, 300],
        'medium' => [768, 768],
        'large' => [1024, 1024],
        'xlarge' => [1920, 1920],
    ],

    // Quality for JPEG/WebP conversions (0-100)
    'image_quality' => env('MEDIA_IMAGE_QUALITY', 85),

    // Enable WebP conversion
    'convert_to_webp' => env('MEDIA_CONVERT_TO_WEBP', false),

    /*
    |--------------------------------------------------------------------------
    | AI Analysis Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for automatic AI-powered media analysis.
    |
    */

    // Enable automatic AI analysis on upload
    'ai_analysis_enabled' => env('MEDIA_AI_ANALYSIS_ENABLED', true),

    // Default vision provider: openai, anthropic, gemini
    'vision_provider' => env('MEDIA_VISION_PROVIDER', 'openai'),

    // OpenAI Vision model
    'openai_vision_model' => env('OPENAI_VISION_MODEL', 'gpt-4o'),

    // Claude Vision model
    'claude_vision_model' => env('CLAUDE_VISION_MODEL', 'claude-3-5-sonnet-20241022'),

    // Gemini Vision model
    'gemini_vision_model' => env('GEMINI_VISION_MODEL', 'gemini-2.0-flash'),

    /*
    |--------------------------------------------------------------------------
    | Audio Transcription Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for audio/voice message transcription using Whisper.
    |
    */

    // OpenAI Whisper model
    'whisper_model' => env('OPENAI_WHISPER_MODEL', 'whisper-1'),

    /*
    |--------------------------------------------------------------------------
    | Supported MIME Types
    |--------------------------------------------------------------------------
    */

    'supported_image_types' => [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'image/avif',
    ],

    'supported_audio_types' => [
        'audio/mpeg',
        'audio/mp3',
        'audio/mp4',
        'audio/m4a',
        'audio/wav',
        'audio/webm',
        'audio/ogg',
        'audio/flac',
        'audio/x-m4a',
        'audio/aac',
    ],

    'supported_video_types' => [
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
    ],

    'supported_document_types' => [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
        'text/csv',
        'application/zip',
        'application/x-rar-compressed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    */

    // Default storage disk
    'default_disk' => env('MEDIA_DISK', 'public'),

    // Storage path structure
    'path_structure' => 'media/{company_id}/{media_type}s/{filename}',

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | Predefined collections for organizing media.
    |
    */

    'collections' => [
        'products' => 'Product Images',
        'messages' => 'Message Attachments',
        'attachments' => 'General Attachments',
        'avatars' => 'Profile Photos',
        'logos' => 'Company Logos',
        'documents' => 'Documents',
        'broadcasts' => 'Broadcast Media',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    */

    // Queue name for media processing jobs
    'queue' => env('MEDIA_PROCESSING_QUEUE', 'media-processing'),

    // Number of retry attempts for failed processing
    'max_retries' => env('MEDIA_PROCESSING_MAX_RETRIES', 3),

    // Timeout in seconds for processing jobs
    'job_timeout' => env('MEDIA_PROCESSING_JOB_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Legacy Media Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the legacy media processing system (message media).
    | This is being phased out in favor of the Media Library.
    |
    */

    'processing_enabled' => env('MEDIA_PROCESSING_ENABLED', true),
];
