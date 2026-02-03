<?php

namespace App\Services\Media\Contracts;

interface MediaProcessingResultInterface
{
    /**
     * Check if processing was successful
     *
     * @return bool
     */
    public function isSuccessful(): bool;

    /**
     * Get the extracted text content (transcription or description)
     *
     * @return string|null
     */
    public function getTextContent(): ?string;

    /**
     * Get additional analysis data
     *
     * @return array
     */
    public function getAnalysisData(): array;

    /**
     * Get the error message if processing failed
     *
     * @return string|null
     */
    public function getError(): ?string;

    /**
     * Get processing time in milliseconds
     *
     * @return int|null
     */
    public function getProcessingTimeMs(): ?int;

    /**
     * Get the processor that was used
     *
     * @return string
     */
    public function getProcessor(): string;

    /**
     * Get the media type that was processed
     *
     * @return string
     */
    public function getMediaType(): string;
}
