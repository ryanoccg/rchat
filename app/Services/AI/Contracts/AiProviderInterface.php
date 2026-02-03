<?php

namespace App\Services\AI\Contracts;

interface AiProviderInterface
{
    /**
     * Send a message to the AI and get a response
     *
     * @param string $message The user's message
     * @param array $context Additional context (conversation history, knowledge base, etc.)
     * @param array $options Provider-specific options (max_tokens, temperature, etc.)
     * @return AiResponseInterface
     */
    public function sendMessage(string $message, array $context = [], array $options = []): AiResponseInterface;

    /**
     * Generate a response based on a system prompt and user message
     *
     * @param string $systemPrompt The system/instruction prompt
     * @param string $userMessage The user's message
     * @param array $options Provider-specific options
     * @return AiResponseInterface
     */
    public function generateResponse(string $systemPrompt, string $userMessage, array $options = []): AiResponseInterface;

    /**
     * Check if the provider supports a specific capability
     *
     * @param string $capability The capability to check (e.g., 'text', 'image', 'audio')
     * @return bool
     */
    public function supportsCapability(string $capability): bool;

    /**
     * Get the provider's name/slug
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get available models for this provider
     *
     * @return array
     */
    public function getAvailableModels(): array;

    /**
     * Validate the provider's credentials
     *
     * @return bool
     */
    public function validateCredentials(): bool;
}
