<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\Media\MediaLibraryService;
use App\Services\Media\MediaStorageService;
use App\Services\Products\ProductEmbeddingService;
use App\Services\Products\ProductRagService;
use App\Services\Media\Processors\ImageProcessor;
use App\Services\ActivityLogService;
use App\Jobs\GenerateProductEmbeddings;

class ProductController extends Controller
{
    protected MediaLibraryService $mediaService;

    public function __construct(MediaLibraryService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    /**
     * Get all products for the company with filters
     */
    public function index(Request $request)
    {
        $query = Product::where('company_id', $request->company_id);

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        // Filter by category
        if ($request->has('category_id') && $request->category_id) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by stock status
        if ($request->has('stock_status') && $request->stock_status) {
            $query->where('stock_status', $request->stock_status);
        }

        // Filter by featured
        if ($request->has('is_featured')) {
            $query->where('is_featured', $request->boolean('is_featured'));
        }

        // Filter by price range
        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // Sorting
        $sortField = $request->get('sort_field', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $allowedSorts = ['name', 'price', 'created_at', 'stock_quantity', 'sku'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

// Pagination
        $perPage = min($request->get('per_page', 20), 100);
        $products = $query->with('category')->paginate($perPage);

        return ProductResource::collection($products);
    }

    /**
     * Get product statistics
     */
    public function stats(Request $request)
    {
        $companyId = $request->company_id;

        $stats = [
            'total' => Product::where('company_id', $companyId)->count(),
            'active' => Product::where('company_id', $companyId)->where('is_active', true)->count(),
            'out_of_stock' => Product::where('company_id', $companyId)->where('stock_status', 'out_of_stock')->count(),
            'featured' => Product::where('company_id', $companyId)->where('is_featured', true)->count(),
            'categories' => ProductCategory::where('company_id', $companyId)->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Store a new product
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:product_categories,id',
            'sku' => 'nullable|string|max:100',
            'short_description' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'brand' => 'nullable|string|max:100',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'stock_status' => 'nullable|in:in_stock,out_of_stock,backorder,preorder',
            'stock_quantity' => 'nullable|integer|min:0',
            'track_inventory' => 'boolean',
            'images' => 'nullable|array',
            'images.*' => 'url',
                'thumbnail_url' => 'nullable|string',
            'specifications' => 'nullable|array',
            'variants' => 'nullable|array',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'meta_description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ]);

        $validated['company_id'] = $request->company_id;
        $validated['slug'] = Str::slug($validated['name']);

        // Ensure unique slug within company
        $slugBase = $validated['slug'];
        $counter = 1;
        while (Product::where('company_id', $validated['company_id'])
            ->where('slug', $validated['slug'])
            ->exists()) {
            $validated['slug'] = $slugBase . '-' . $counter++;
        }

        // Ensure unique SKU within company if provided
        if (!empty($validated['sku'])) {
            $skuExists = Product::where('company_id', $validated['company_id'])
                ->where('sku', $validated['sku'])
                ->exists();
            if ($skuExists) {
                return response()->json([
                    'message' => 'SKU already exists',
                    'errors' => ['sku' => ['This SKU is already in use']],
                ], 422);
            }
        }

        $product = Product::create($validated);

        // Generate embeddings in background via queued job
        GenerateProductEmbeddings::dispatch($product->id);

        ActivityLogService::productAdded($product, $product->name);

$product->load('category');
        
        return response()->json([
            'message' => 'Product created successfully',
            'product' => new ProductResource($product),
        ], 201);
    }

    /**
     * Get a specific product
     */
public function show(Request $request, Product $product)
    {
        if ($product->company_id !== $request->company_id) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $product->load('category');
        return new ProductResource($product);
    }

    /**
     * Update a product
     */
    public function update(Request $request, Product $product)
    {
        if ($product->company_id !== $request->company_id) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category_id' => 'nullable|exists:product_categories,id',
            'sku' => 'nullable|string|max:100',
            'short_description' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'brand' => 'nullable|string|max:100',
            'price' => 'sometimes|required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'stock_status' => 'nullable|in:in_stock,out_of_stock,backorder,preorder',
            'stock_quantity' => 'nullable|integer|min:0',
            'track_inventory' => 'boolean',
            'images' => 'nullable|array',
            'images.*' => 'url',
                'thumbnail_url' => 'nullable|string',
            'specifications' => 'nullable|array',
            'variants' => 'nullable|array',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'meta_description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ]);

        // Update slug if name changed
        if (isset($validated['name']) && $validated['name'] !== $product->name) {
            $validated['slug'] = Str::slug($validated['name']);
            $slugBase = $validated['slug'];
            $counter = 1;
            while (Product::where('company_id', $product->company_id)
                ->where('slug', $validated['slug'])
                ->where('id', '!=', $product->id)
                ->exists()) {
                $validated['slug'] = $slugBase . '-' . $counter++;
            }
        }

        // Check SKU uniqueness if changed
        if (isset($validated['sku']) && $validated['sku'] !== $product->sku) {
            $skuExists = Product::where('company_id', $product->company_id)
                ->where('sku', $validated['sku'])
                ->where('id', '!=', $product->id)
                ->exists();
            if ($skuExists) {
                return response()->json([
                    'message' => 'SKU already exists',
                    'errors' => ['sku' => ['This SKU is already in use']],
                ], 422);
            }
        }

$product->update($validated);
        $product->load('category');

        return response()->json([
            'message' => 'Product updated successfully',
            'product' => new ProductResource($product),
        ]);
    }

    /**
     * Delete a product
     */
    public function destroy(Request $request, Product $product)
    {
        if ($product->company_id !== $request->company_id) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $productName = $product->name;
        $product->delete();

        ActivityLogService::deleted($product, "product: {$productName}");

        return response()->json([
            'message' => 'Product deleted successfully',
        ]);
    }

    /**
     * Bulk delete products
     */
    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:products,id',
        ]);

        $deleted = Product::where('company_id', $request->company_id)
            ->whereIn('id', $validated['ids'])
            ->delete();

        ActivityLogService::bulkAction('deleted', 'product', $deleted);

        return response()->json([
            'message' => "{$deleted} products deleted successfully",
        ]);
    }

    /**
     * Toggle product active status
     */
    public function toggleStatus(Request $request, Product $product)
    {
        if ($product->company_id !== $request->company_id) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $product->update(['is_active' => !$product->is_active]);

        return response()->json([
            'message' => 'Product status updated',
            'product' => $product,
        ]);
    }

    /**
     * Toggle product featured status
     */
    public function toggleFeatured(Request $request, Product $product)
    {
        if ($product->company_id !== $request->company_id) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $product->update(['is_featured' => !$product->is_featured]);

        return response()->json([
            'message' => 'Product featured status updated',
            'product' => $product,
        ]);
    }

    /**
     * Regenerate embeddings for a product
     */
    public function regenerateEmbeddings(Request $request, Product $product)
    {
        if ($product->company_id !== $request->company_id) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $embeddingService = app(ProductEmbeddingService::class);
        $success = $embeddingService->generateEmbeddings($product);

        return response()->json([
            'message' => $success ? 'Embeddings regenerated successfully' : 'Failed to regenerate embeddings',
            'success' => $success,
        ]);
    }

    /**
     * Regenerate embeddings for all products
     */
    public function regenerateAllEmbeddings(Request $request)
    {
        $embeddingService = app(ProductEmbeddingService::class);

        dispatch(function () use ($request, $embeddingService) {
            $count = $embeddingService->regenerateAllForCompany($request->company_id);
            Log::info('Regenerated embeddings for products', ['count' => $count]);
        })->afterResponse();

        return response()->json([
            'message' => 'Embedding regeneration started in background',
        ]);
    }

    /**
     * Search products (for AI/customer queries)
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string|max:500',
            'category_id' => 'nullable|exists:product_categories,id',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $company = $request->user()->company;
        $ragService = app(ProductRagService::class);

        $filters = array_filter([
            'category_id' => $validated['category_id'] ?? null,
            'min_price' => $validated['min_price'] ?? null,
            'max_price' => $validated['max_price'] ?? null,
        ]);

        // Also extract filters from natural language
        $nlFilters = $ragService->extractFiltersFromQuery($validated['query']);
        $filters = array_merge($nlFilters, $filters);

        $products = $ragService->searchProducts(
            $company,
            $validated['query'],
            $filters,
            $validated['limit'] ?? 5
        );

        return response()->json([
            'products' => $products,
            'query' => $validated['query'],
            'filters_applied' => $filters,
        ]);
    }

    /**
     * Import products from CSV/JSON
     */
    public function import(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:csv,json,txt|max:10240',
        ]);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();
        $content = file_get_contents($file->getRealPath());

        $products = [];
        $errors = [];

        if ($extension === 'json') {
            $data = json_decode($content, true);
            if (!$data) {
                return response()->json(['message' => 'Invalid JSON file'], 422);
            }
            $products = $data;
        } else {
            // CSV parsing
            $lines = explode("\n", $content);
            $headers = str_getcsv(array_shift($lines));

            foreach ($lines as $index => $line) {
                if (empty(trim($line))) continue;
                $values = str_getcsv($line);
                if (count($values) === count($headers)) {
                    $products[] = array_combine($headers, $values);
                } else {
                    $errors[] = "Line " . ($index + 2) . ": Column count mismatch";
                }
            }
        }

        $imported = 0;
        $companyId = $request->company_id;

        foreach ($products as $index => $productData) {
            try {
                $productData['company_id'] = $companyId;
                $productData['slug'] = Str::slug($productData['name'] ?? 'product-' . ($index + 1));

                // Handle JSON fields
                foreach (['images', 'specifications', 'variants', 'tags'] as $jsonField) {
                    if (isset($productData[$jsonField]) && is_string($productData[$jsonField])) {
                        $productData[$jsonField] = json_decode($productData[$jsonField], true);
                    }
                }

                Product::create($productData);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
            }
        }

        return response()->json([
            'message' => "{$imported} products imported successfully",
            'imported' => $imported,
            'errors' => $errors,
        ]);
    }

    /**
     * Export products to JSON
     */
    public function export(Request $request)
    {
        $products = Product::where('company_id', $request->company_id)
            ->with('category')
            ->get();

        return response()->json([
            'products' => $products,
            'exported_at' => now()->toIso8601String(),
            'count' => $products->count(),
        ]);
    }

    /**
     * Upload product image and get AI description
     * Uses MediaLibraryService for centralized file management
     */
    public function uploadImage(Request $request)
    {
        $validated = $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'generate_description' => 'boolean',
        ]);

        $file = $request->file('image');
        $companyId = $request->company_id;
        $userId = $request->user()->id;

        // Store the image using MediaLibraryService
        $media = $this->mediaService->upload($file, $companyId, $userId, [
            'collection' => 'products',
            'folder' => 'images',
            'title' => 'Product Image',
            'source' => 'product_upload',
        ]);

        $result = [
            'url' => $media->url,
            'path' => $media->path,
            'media_id' => $media->id,
            'thumbnail_url' => $media->thumbnail_url,
        ];

        // Generate AI description if requested
        if ($request->boolean('generate_description', true)) {
            try {
                $imageProcessor = app(ImageProcessor::class);

                // Read image file and convert to base64
                $fullPath = Storage::disk('public')->path($media->path);
                $imageContent = base64_encode(file_get_contents($fullPath));
                $mimeType = $file->getMimeType() ?? 'image/jpeg';

                $analysis = $imageProcessor->process(
                    $imageContent,
                    $mimeType,
                    [
                        'prompt' => 'Analyze this product image. Provide: 1) A short product title (max 50 chars), 2) A brief description (1-2 sentences), 3) Key features/keywords (comma-separated), 4) Suggested category. Format as JSON with keys: title, description, keywords, category.',
                        'product_search' => true,
                    ]
                );

                if ($analysis->isSuccessful()) {
                    $content = $analysis->getTextContent();

                    // Try to parse as JSON, otherwise use as-is
                    $jsonMatch = preg_match('/\{[^}]+\}/s', $content, $matches);
                    if ($jsonMatch) {
                        $parsed = json_decode($matches[0], true);
                        if ($parsed) {
                            $result['ai_analysis'] = $parsed;
                        }
                    }

                    if (!isset($result['ai_analysis'])) {
                        $result['ai_analysis'] = ['description' => $content];
                    }

                    // Store AI analysis in media metadata
                    $media->update([
                        'ai_analysis' => $result['ai_analysis'] ?? null,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to generate AI description for product image', [
                    'error' => $e->getMessage(),
                ]);
                // Delete the uploaded image on AI processing failure if requested
                if ($request->boolean('delete_on_failure', false)) {
                    $this->mediaService->delete($media);
                    return response()->json([
                        'error' => 'Failed to process image with AI',
                        'message' => $e->getMessage(),
                    ], 422);
                }
                // Continue without AI description
            }
        }

        return response()->json($result);
    }

    /**
     * Delete product image
     * Uses MediaLibraryService for centralized file management
     */
    public function deleteImage(Request $request)
    {
        $validated = $request->validate([
            'media_id' => 'nullable|integer|exists:media,id',
            'path' => 'required_without:media_id|string',
        ]);

        $companyId = $request->company_id;

        // If media_id is provided, delete via MediaLibraryService
        if (!empty($validated['media_id'])) {
            $media = \App\Models\Media::where('id', $validated['media_id'])
                ->where('company_id', $companyId)
                ->first();

            if ($media) {
                $this->mediaService->delete($media);
                return response()->json(['message' => 'Image deleted successfully']);
            }

            return response()->json(['message' => 'Media not found'], 404);
        }

        // Legacy support: delete by path
        $path = str_replace(['..', '\\'], '', $validated['path']);
        if (!str_starts_with($path, "products/{$companyId}/") && !str_starts_with($path, "media/{$companyId}/")) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        return response()->json(['message' => 'Image deleted successfully']);
    }
}
