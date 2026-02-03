<?php

namespace App\Services\AI\Contracts;

interface AiResponseInterface
{
    /**
     * Get the generated text response
     *
     * @return string
     */
    public function getContent(): string;

    /**
     * Get the confidence score (0-1)
     *
     * @return float|null
     */
    public function getConfidence(): ?float;

    /**
     * Get token usage information
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    public function getUsage(): array;

    /**
     * Get the model that was used
     *
     * @return string
     */
    public function getModel(): string;

    /**
     * Check if the response was successful
     *
     * @return bool
     */
    public function isSuccessful(): bool;

    /**
     * Get error message if any
     *
     * @return string|null
     */
    public function getError(): ?string;

    /**
     * Get raw response data from the provider
     *
     * @return array
     */
    public function getRawResponse(): array;

    /**
     * Get finish reason (e.g., 'stop', 'length', 'content_filter')
     *
     * @return string|null
     */
    public function getFinishReason(): ?string;
}
