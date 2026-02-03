<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();

            // Basic info
            $table->string('sku')->nullable();
            $table->string('name');
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('brand')->nullable();

            // Pricing
            $table->decimal('price', 12, 2);
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->string('currency', 3)->default('MYR');

            // Inventory
            $table->enum('stock_status', ['in_stock', 'out_of_stock', 'backorder', 'preorder'])->default('in_stock');
            $table->integer('stock_quantity')->nullable();
            $table->boolean('track_inventory')->default(false);

            // Media
            $table->json('images')->nullable(); // Array of image URLs
            $table->string('thumbnail_url')->nullable();

            // Attributes & Specifications
            $table->json('specifications')->nullable(); // {color, size, weight, dimensions, etc.}
            $table->json('variants')->nullable(); // For product variations
            $table->json('tags')->nullable(); // Searchable tags

            // SEO & Display
            $table->string('slug');
            $table->text('meta_description')->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'sku']);
            $table->unique(['company_id', 'slug']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'category_id']);
            $table->index(['company_id', 'stock_status']);
            $table->index(['company_id', 'is_featured']);
            $table->index('price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
