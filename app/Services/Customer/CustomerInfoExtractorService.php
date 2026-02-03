<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerInfoExtractorService
{
    /**
     * Extract customer information from message content.
     * Returns array with keys: phone, email, name if found.
     */
    public function extractFromMessage(string $content): array
    {
        $extracted = [];
        $content = preg_replace('/\s+/', ' ', $content);

        // Extract phone numbers (international formats)
        if (preg_match_all('/(?:\+?(\d{1,3})?[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}(?:\s?(?:ext|x|extension)\s?\d{1,5})?/', $content, $matches)) {
            $phones = array_filter($matches[0], fn($p) => strlen(preg_replace('/[^0-9]/', '', $p)) >= 7);
            if (!empty($phones)) {
                $extracted['phone'] = $this->formatPhoneNumber($phones[array_key_first($phones)]);
            }
        }

        // Extract email addresses
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $content, $matches)) {
            $extracted['email'] = $matches[0];
        }

        return $extracted;
    }

    /**
     * Format phone number to standard format.
     */
    protected function formatPhoneNumber(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        return $digits;
    }

    /**
     * Update customer with extracted information.
     * Only updates fields that are currently empty.
     */
    public function updateCustomerInfo(Message $message): void
    {
        if (!$message->is_from_customer || empty($message->content)) {
            return;
        }

        $customer = $message->conversation?->customer;
        if (!$customer) {
            return;
        }

        $extracted = $this->extractFromMessage($message->content);
        if (empty($extracted)) {
            return;
        }

        $updates = [];
        $changes = [];

        // Update phone if empty and extracted
        if (!empty($extracted['phone']) && empty($customer->phone)) {
            $updates['phone'] = $extracted['phone'];
            $changes[] = 'phone';
        }

        // Update email if empty and extracted
        if (!empty($extracted['email']) && empty($customer->email)) {
            $updates['email'] = $extracted['email'];
            $changes[] = 'email';
        }

        if (!empty($updates)) {
            $customer->update($updates);
            Log::channel('ai')->info('Customer info updated from message', [
                'customer_id' => $customer->id,
                'message_id' => $message->id,
                'fields_updated' => $changes,
            ]);
        }
    }
}
