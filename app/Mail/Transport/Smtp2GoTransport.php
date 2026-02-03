<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

class Smtp2GoTransport extends AbstractTransport
{
    protected string $apiKey;
    protected string $endpoint = 'https://api.smtp2go.com/v3/email/send';

    public function __construct(string $apiKey)
    {
        parent::__construct();
        $this->apiKey = $apiKey;
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $payload = [
            'api_key' => $this->apiKey,
            'sender' => $email->getFrom()[0]->toString(),
            'to' => array_map(fn(Address $a) => $a->toString(), $email->getTo()),
            'subject' => $email->getSubject(),
        ];

        if ($email->getCc()) {
            $payload['cc'] = array_map(fn(Address $a) => $a->toString(), $email->getCc());
        }

        if ($email->getBcc()) {
            $payload['bcc'] = array_map(fn(Address $a) => $a->toString(), $email->getBcc());
        }

        if ($email->getHtmlBody()) {
            $payload['html_body'] = $email->getHtmlBody();
        }

        if ($email->getTextBody()) {
            $payload['text_body'] = $email->getTextBody();
        }

        $response = Http::post($this->endpoint, $payload);

        if (!$response->successful()) {
            Log::error('SMTP2GO API error', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException('SMTP2GO API error: ' . ($response->json('data.error') ?? $response->body()));
        }
    }

    public function __toString(): string
    {
        return 'smtp2go';
    }
}
