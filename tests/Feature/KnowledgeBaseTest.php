<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $this->user->companies()->attach($this->company->id);
    }

    public function test_can_list_knowledge_base_entries(): void
    {
        KnowledgeBase::factory()->count(3)->create([
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/knowledge-base');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_cannot_see_other_company_entries(): void
    {
        $otherCompany = Company::factory()->create();
        
        KnowledgeBase::factory()->create([
            'company_id' => $otherCompany->id,
            'title' => 'Other Company Entry',
        ]);

        KnowledgeBase::factory()->create([
            'company_id' => $this->company->id,
            'title' => 'My Company Entry',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/knowledge-base');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['title' => 'My Company Entry'])
            ->assertJsonMissing(['title' => 'Other Company Entry']);
    }

    public function test_can_create_text_entry(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/knowledge-base', [
                'title' => 'FAQ Document',
                'content' => 'This is a test FAQ content with important information.',
                'category' => 'FAQ',
                'priority' => 50,
            ]);

        $response->assertCreated()
            ->assertJsonFragment([
                'title' => 'FAQ Document',
                'category' => 'FAQ',
                'priority' => 50,
            ]);

        $this->assertDatabaseHas('knowledge_base', [
            'company_id' => $this->company->id,
            'title' => 'FAQ Document',
        ]);
    }

    public function test_can_upload_txt_file(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent(
            'test-document.txt',
            'This is test content from a text file.'
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/knowledge-base', [
                'title' => 'Uploaded Document',
                'file' => $file,
                'category' => 'Documents',
            ]);

        $response->assertCreated()
            ->assertJsonFragment([
                'title' => 'Uploaded Document',
                'file_type' => 'txt',
            ]);

        $this->assertDatabaseHas('knowledge_base', [
            'title' => 'Uploaded Document',
            'file_type' => 'txt',
        ]);
    }

    public function test_can_view_single_entry(): void
    {
        $entry = KnowledgeBase::factory()->create([
            'company_id' => $this->company->id,
            'title' => 'Test Entry',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/knowledge-base/{$entry->id}");

        $response->assertOk()
            ->assertJsonFragment(['title' => 'Test Entry']);
    }

    public function test_cannot_view_other_company_entry(): void
    {
        $otherCompany = Company::factory()->create();
        $entry = KnowledgeBase::factory()->create([
            'company_id' => $otherCompany->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/knowledge-base/{$entry->id}");

        $response->assertNotFound();
    }

    public function test_can_update_entry(): void
    {
        $entry = KnowledgeBase::factory()->create([
            'company_id' => $this->company->id,
            'title' => 'Original Title',
            'priority' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/knowledge-base/{$entry->id}", [
                'title' => 'Updated Title',
                'priority' => 80,
                'category' => 'Updated Category',
            ]);

        $response->assertOk()
            ->assertJsonFragment([
                'title' => 'Updated Title',
                'priority' => 80,
            ]);

        $this->assertDatabaseHas('knowledge_base', [
            'id' => $entry->id,
            'title' => 'Updated Title',
            'priority' => 80,
        ]);
    }

    public function test_can_delete_entry(): void
    {
        $entry = KnowledgeBase::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/knowledge-base/{$entry->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('knowledge_base', ['id' => $entry->id]);
    }

    public function test_can_toggle_status(): void
    {
        $entry = KnowledgeBase::factory()->create([
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/knowledge-base/{$entry->id}/toggle");

        $response->assertOk()
            ->assertJsonFragment(['is_active' => false]);

        // Toggle back
        $response = $this->actingAs($this->user)
            ->postJson("/api/knowledge-base/{$entry->id}/toggle");

        $response->assertOk()
            ->assertJsonFragment(['is_active' => true]);
    }

    public function test_can_filter_by_category(): void
    {
        KnowledgeBase::factory()->create([
            'company_id' => $this->company->id,
            'category' => 'FAQ',
        ]);

        KnowledgeBase::factory()->create([
            'company_id' => $this->company->id,
            'category' => 'Products',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/knowledge-base?category=FAQ');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['category' => 'FAQ']);
    }

    public function test_can_filter_by_active_status(): void
    {
        KnowledgeBase::factory()->create([
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        KnowledgeBase::factory()->create([
            'company_id' => $this->company->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/knowledge-base?is_active=true');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_can_search_entries(): void
    {
        KnowledgeBase::factory()->create([
            'company_id' => $this->company->id,
            'title' => 'Return Policy',
            'content' => 'Our return policy allows returns within 30 days.',
            'is_active' => true,
        ]);

        KnowledgeBase::factory()->create([
            'company_id' => $this->company->id,
            'title' => 'Shipping Information',
            'content' => 'We ship worldwide.',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/knowledge-base/search?query=return');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['title' => 'Return Policy']);
    }

    public function test_can_get_categories(): void
    {
        KnowledgeBase::factory()->create([
            'company_id' => $this->company->id,
            'category' => 'FAQ',
        ]);

        KnowledgeBase::factory()->create([
            'company_id' => $this->company->id,
            'category' => 'Products',
        ]);

        KnowledgeBase::factory()->create([
            'company_id' => $this->company->id,
            'category' => 'FAQ', // Duplicate
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/knowledge-base/categories');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_validation_requires_title(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/knowledge-base', [
                'content' => 'Some content',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);
    }

    public function test_validation_requires_file_or_content(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/knowledge-base', [
                'title' => 'Test Entry',
            ]);

        $response->assertUnprocessable();
    }
}
