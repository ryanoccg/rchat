<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\KnowledgeBase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\KnowledgeBase>
 */
class KnowledgeBaseFactory extends Factory
{
    protected $model = KnowledgeBase::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categories = ['FAQ', 'Products', 'Policies', 'Support', 'General', null];

        return [
            'company_id' => Company::factory(),
            'title' => fake()->sentence(3),
            'file_name' => null,
            'file_path' => null,
            'file_type' => 'text',
            'file_size' => null,
            'content' => fake()->paragraphs(3, true),
            'category' => fake()->randomElement($categories),
            'priority' => fake()->numberBetween(0, 100),
            'is_active' => true,
            'metadata' => null,
        ];
    }

    /**
     * Set the entry as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set a specific category.
     */
    public function category(string $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => $category,
        ]);
    }

    /**
     * Set as a PDF file entry.
     */
    public function pdf(): static
    {
        return $this->state(fn (array $attributes) => [
            'file_name' => fake()->word() . '.pdf',
            'file_path' => 'knowledge-base/' . fake()->uuid() . '.pdf',
            'file_type' => 'pdf',
            'file_size' => fake()->numberBetween(10000, 5000000),
        ]);
    }

    /**
     * Set high priority.
     */
    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => fake()->numberBetween(80, 100),
        ]);
    }
}
