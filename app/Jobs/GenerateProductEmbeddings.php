<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Products\ProductEmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateProductEmbeddings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $productId;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 60;

    public function __construct(int $productId)
    {
        $this->productId = $productId;
    }

    public function handle(): void
    {
        $product = Product::find($this->productId);

        if (!$product) {
            Log::warning('GenerateProductEmbeddings: Product not found', [
                'product_id' => $this->productId,
            ]);
            return;
        }

        try {
            app(ProductEmbeddingService::class)->generateEmbeddings($product);

            Log::info('GenerateProductEmbeddings: Embeddings generated', [
                'product_id' => $this->productId,
            ]);
        } catch (\Exception $e) {
            Log::error('GenerateProductEmbeddings: Failed to generate embeddings', [
                'product_id' => $this->productId,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Re-throw to trigger retry
        }
    }
}
