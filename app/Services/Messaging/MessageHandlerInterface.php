<?php

namespace App\Services\Messaging;

use App\Models\PlatformConnection;
use Illuminate\Http\Request;

interface MessageHandlerInterface
{
    public function handleIncoming(Request $request, PlatformConnection $connection): array;

    public function sendMessage(PlatformConnection $connection, string $recipientId, string $message, array $options = []): array;

    public function sendImage(PlatformConnection $connection, string $recipientId, string $imageUrl, ?string $caption = null): array;

    public function parseIncomingMessage(Request $request): array;
}
