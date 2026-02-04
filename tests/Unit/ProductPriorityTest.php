<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Product;
use App\Services\Products\ProductRagService;
use Database\Seeders\MessagingPlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPriorityTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected ProductRagService $ragService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MessagingPlatformSeeder::class);

        $this->company = Company::factory()->create();
        $this->ragService = new ProductRagService();
    }

    /** @test */
    public function featured_products_get_score_boost(): void
    {
        // Create regular and featured products
        $regularProduct = Product::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Regular Product',
            'is_featured' => false,
        ]);

        $featuredProduct = Product::factory()->featured()->create([
            'company_id' => $this->company->id,
            'name' => 'Featured Product',
        ]);

        // Use reflection to test private method
        $method = new \ReflectionMethod(ProductRagService::class, 'formatProductResult');
        $method->setAccessible(true);

        $regularResult = $method->invoke($this->ragService, $regularProduct, 0.5, 'test');
        $featuredResult = $method->invoke($this->ragService, $featuredProduct, 0.5, 'test');

        // Featured should have higher score (+0.15 boost)
        $this->assertEquals(0.5, $regularResult['relevance_score']);
        $this->assertEquals(0.65, $featuredResult['relevance_score']);
        $this->assertContains('featured', $featuredResult['boost_reasons']);
    }

    /** @test */
    public function on_sale_products_get_score_boost(): void
    {
        // Create regular and on-sale products
        $regularProduct = Product::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Regular Product',
        ]);

        $saleProduct = Product::factory()->onSale(20)->create([
            'company_id' => $this->company->id,
            'name' => 'Sale Product',
        ]);

        $method = new \ReflectionMethod(ProductRagService::class, 'formatProductResult');
        $method->setAccessible(true);

        $regularResult = $method->invoke($this->ragService, $regularProduct, 0.5, 'test');
        $saleResult = $method->invoke($this->ragService, $saleProduct, 0.5, 'test');

        // On-sale should have higher score (+0.10 boost)
        $this->assertEquals(0.5, $regularResult['relevance_score']);
        $this->assertEquals(0.6, $saleResult['relevance_score']);
        $this->assertContains('on_sale', $saleResult['boost_reasons']);
    }

    /** @test */
    public function featured_and_on_sale_products_get_combined_boost(): void
    {
        $product = Product::factory()->featured()->onSale(20)->create([
            'company_id' => $this->company->id,
            'name' => 'Featured Sale Product',
        ]);

        $method = new \ReflectionMethod(ProductRagService::class, 'formatProductResult');
        $method->setAccessible(true);

        $result = $method->invoke($this->ragService, $product, 0.5, 'test');

        // Should have combined boost (+0.15 + +0.10 = +0.25)
        $this->assertEquals(0.75, $result['relevance_score']);
        $this->assertContains('featured', $result['boost_reasons']);
        $this->assertContains('on_sale', $result['boost_reasons']);
    }

    /** @test */
    public function score_is_capped_at_one(): void
    {
        $product = Product::factory()->featured()->onSale(20)->create([
            'company_id' => $this->company->id,
            'name' => 'Super Product',
        ]);

        $method = new \ReflectionMethod(ProductRagService::class, 'formatProductResult');
        $method->setAccessible(true);

        // Start with high score that would exceed 1.0 with boosts
        $result = $method->invoke($this->ragService, $product, 0.9, 'test');

        // Score should be capped at 1.0
        $this->assertEquals(1.0, $result['relevance_score']);
    }

    /** @test */
    public function format_products_for_context_shows_badges(): void
    {
        $featuredProduct = Product::factory()->featured()->create([
            'company_id' => $this->company->id,
            'name' => 'Featured Item',
            'price' => 100,
            'currency' => 'MYR',
        ]);

        // Create sale product with explicit price and sale_price to ensure is_on_sale returns true
        $saleProduct = Product::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Sale Item',
            'price' => 100,
            'sale_price' => 80, // Explicitly set sale price lower than price
            'currency' => 'MYR',
        ]);

        $method = new \ReflectionMethod(ProductRagService::class, 'formatProductResult');
        $method->setAccessible(true);

        $products = [
            $method->invoke($this->ragService, $featuredProduct, 0.8, 'test'),
            $method->invoke($this->ragService, $saleProduct, 0.7, 'test'),
        ];

        $context = $this->ragService->formatProductsForContext($products);

        // Check that context contains badges
        $this->assertStringContainsString('[FEATURED]', $context);
        $this->assertStringContainsString('[ON SALE]', $context);
        $this->assertStringContainsString('FEATURED - Prioritize recommending!', $context);
        $this->assertStringContainsString('GREAT DEAL!', $context);
    }

    /** @test */
    public function result_includes_is_featured_field(): void
    {
        $product = Product::factory()->featured()->create([
            'company_id' => $this->company->id,
        ]);

        $method = new \ReflectionMethod(ProductRagService::class, 'formatProductResult');
        $method->setAccessible(true);

        $result = $method->invoke($this->ragService, $product, 0.5, 'test');

        $this->assertArrayHasKey('is_featured', $result);
        $this->assertTrue($result['is_featured']);
    }

    /** @test */
    public function result_includes_original_score(): void
    {
        $product = Product::factory()->featured()->create([
            'company_id' => $this->company->id,
        ]);

        $method = new \ReflectionMethod(ProductRagService::class, 'formatProductResult');
        $method->setAccessible(true);

        $result = $method->invoke($this->ragService, $product, 0.5, 'test');

        $this->assertArrayHasKey('original_score', $result);
        $this->assertEquals(0.5, $result['original_score']);
        $this->assertEquals(0.65, $result['relevance_score']); // boosted
    }
}
