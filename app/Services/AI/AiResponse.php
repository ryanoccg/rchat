<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\AiResponseInterface;

class AiResponse implements AiResponseInterface
{
    public function __construct(
        protected string $content = '',
        protected ?float $confidence = null,
        protected array $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
        protected string $model = '',
        protected bool $successful = true,
        protected ?string $error = null,
        protected array $rawResponse = [],
        protected ?string $finishReason = null,
    ) {}

    public function getContent(): string
    {
        return $this->content;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function getUsage(): array
    {
        return $this->usage;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }

    public function getFinishReason(): ?string
    {
        return $this->finishReason;
    }

    /**
     * Create an error response
     */
    public static function error(string $message, array $rawResponse = []): self
    {
        return new self(
            content: '',
            successful: false,
            error: $message,
            rawResponse: $rawResponse,
        );
    }

    /**
     * Create a successful response
     */
    public static function success(
        string $content,
        string $model,
        array $usage = [],
        ?float $confidence = null,
        ?string $finishReason = null,
        array $rawResponse = [],
    ): self {
        return new self(
            content: $content,
            confidence: $confidence,
            usage: array_merge(['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0], $usage),
            model: $model,
            successful: true,
            finishReason: $finishReason,
            rawResponse: $rawResponse,
        );
    }
}
