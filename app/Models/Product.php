<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'category_id',
        'sku',
        'name',
        'short_description',
        'description',
        'brand',
        'price',
        'sale_price',
        'cost_price',
        'currency',
        'stock_status',
        'stock_quantity',
        'track_inventory',
        'images',
        'thumbnail_url',
        'specifications',
        'variants',
        'tags',
        'slug',
        'meta_description',
        'is_active',
        'is_featured',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'images' => 'array',
            'specifications' => 'array',
            'variants' => 'array',
            'tags' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'track_inventory' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Stock status constants
     */
    public const STOCK_IN_STOCK = 'in_stock';
    public const STOCK_OUT_OF_STOCK = 'out_of_stock';
    public const STOCK_BACKORDER = 'backorder';
    public const STOCK_PREORDER = 'preorder';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });

        // Generate embeddings when product is created or updated
        // Use job dispatch instead of afterResponse() for queue compatibility
        static::saved(function ($product) {
            if ($product->is_active) {
                \App\Jobs\GenerateProductEmbeddings::dispatch($product->id);
            }
        });
    }

    /**
     * Get the company that owns this product
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the category this product belongs to
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * Get the product embeddings
     */
    public function embeddings(): HasMany
    {
        return $this->hasMany(ProductEmbedding::class);
    }

    /**
     * Scope to get active products only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get featured products
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope to get in-stock products
     */
    public function scopeInStock($query)
    {
        return $query->where('stock_status', self::STOCK_IN_STOCK);
    }

    /**
     * Scope to filter by category
     */
    public function scopeInCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Scope to filter by price range
     */
    public function scopePriceRange($query, $min = null, $max = null)
    {
        if ($min !== null) {
            $query->where('price', '>=', $min);
        }
        if ($max !== null) {
            $query->where('price', '<=', $max);
        }
        return $query;
    }

    /**
     * Scope to search products by keyword
     */
    public function scopeSearch($query, $keyword)
    {
        return $query->where(function ($q) use ($keyword) {
            $q->where('name', 'like', "%{$keyword}%")
              ->orWhere('description', 'like', "%{$keyword}%")
              ->orWhere('short_description', 'like', "%{$keyword}%")
              ->orWhere('sku', 'like', "%{$keyword}%")
              ->orWhere('brand', 'like', "%{$keyword}%");
        });
    }

    /**
     * Get the current price (sale price if available, otherwise regular price)
     */
    public function getCurrentPriceAttribute(): float
    {
        return $this->sale_price ?? $this->price;
    }

    /**
     * Check if product is on sale
     */
    public function getIsOnSaleAttribute(): bool
    {
        return $this->sale_price !== null && $this->sale_price < $this->price;
    }

    /**
     * Get discount percentage
     */
    public function getDiscountPercentageAttribute(): ?int
    {
        if (!$this->is_on_sale) {
            return null;
        }
        return (int) round((($this->price - $this->sale_price) / $this->price) * 100);
    }

    /**
     * Get formatted price with currency
     */
    public function getFormattedPriceAttribute(): string
    {
        return $this->currency . ' ' . number_format($this->current_price, 2);
    }

    /**
     * Get the primary image URL
     */
    public function getPrimaryImageAttribute(): ?string
    {
        if ($this->thumbnail_url) {
            return $this->thumbnail_url;
        }
        return $this->images[0] ?? null;
    }

    /**
     * Generate text for embedding (combines all searchable fields)
     */
    public function getEmbeddingTextAttribute(): string
    {
        $parts = [
            $this->name,
            $this->short_description,
            $this->description,
            $this->brand,
            $this->category?->name,
        ];

        // Add specifications
        if ($this->specifications) {
            foreach ($this->specifications as $key => $value) {
                $parts[] = "{$key}: {$value}";
            }
        }

        // Add tags
        if ($this->tags) {
            $parts[] = implode(', ', $this->tags);
        }

        return implode("\n", array_filter($parts));
    }
}
