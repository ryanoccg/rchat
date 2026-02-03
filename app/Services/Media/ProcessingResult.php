<?php

namespace App\Services\Media;

use App\Services\Media\Contracts\MediaProcessingResultInterface;

class ProcessingResult implements MediaProcessingResultInterface
{
    public function __construct(
        protected bool $successful,
        protected string $mediaType,
        protected string $processor,
        protected ?string $textContent = null,
        protected array $analysisData = [],
        protected ?string $error = null,
        protected ?int $processingTimeMs = null,
    ) {}

    public static function success(
        string $mediaType,
        string $processor,
        string $textContent,
        array $analysisData = [],
        ?int $processingTimeMs = null
    ): self {
        return new self(
            successful: true,
            mediaType: $mediaType,
            processor: $processor,
            textContent: $textContent,
            analysisData: $analysisData,
            processingTimeMs: $processingTimeMs,
        );
    }

    public static function failure(
        string $mediaType,
        string $processor,
        string $error
    ): self {
        return new self(
            successful: false,
            mediaType: $mediaType,
            processor: $processor,
            error: $error,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getTextContent(): ?string
    {
        return $this->textContent;
    }

    public function getAnalysisData(): array
    {
        return $this->analysisData;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getProcessingTimeMs(): ?int
    {
        return $this->processingTimeMs;
    }

    public function getProcessor(): string
    {
        return $this->processor;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }
}
