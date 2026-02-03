<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Customer\CustomerInfoExtractorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractCustomerInfoFromMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected readonly Message $message
    ) {
        $this->onQueue('default');
    }

    public function handle(CustomerInfoExtractorService $extractor): void
    {
        try {
            $extractor->updateCustomerInfo($this->message);
        } catch (\Throwable $e) {
            Log::error('Failed to extract customer info from message', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
