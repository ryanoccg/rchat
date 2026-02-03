<?php

namespace App\Services\Media\Contracts;

interface MediaProcessorInterface
{
    /**
     * Process media content and return analysis results
     *
     * @param string $mediaContent Base64 encoded media content
     * @param string $mimeType MIME type of the media
     * @param array $options Additional processing options
     * @return MediaProcessingResultInterface
     */
    public function process(string $mediaContent, string $mimeType, array $options = []): MediaProcessingResultInterface;

    /**
     * Get supported MIME types for this processor
     *
     * @return array<string>
     */
    public function getSupportedMimeTypes(): array;

    /**
     * Check if this processor supports the given MIME type
     *
     * @param string $mimeType
     * @return bool
     */
    public function supports(string $mimeType): bool;

    /**
     * Get the processor name/identifier
     *
     * @return string
     */
    public function getName(): string;
}
