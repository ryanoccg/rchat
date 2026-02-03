<?php

namespace App\Services\Messaging;

use InvalidArgumentException;

class MessageHandlerFactory
{
    protected static array $handlers = [
        'facebook' => FacebookMessageHandler::class,
        'whatsapp' => WhatsAppMessageHandler::class,
        'telegram' => TelegramMessageHandler::class,
        'line' => LineMessageHandler::class,
    ];

    public static function create(string $platform): MessageHandlerInterface
    {
        if (!isset(self::$handlers[$platform])) {
            throw new InvalidArgumentException("Unsupported platform: {$platform}");
        }

        $handlerClass = self::$handlers[$platform];

        return new $handlerClass();
    }

    public static function supports(string $platform): bool
    {
        return isset(self::$handlers[$platform]);
    }
}
