<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'category_id' => $this->category_id,
            'sku' => $this->sku,
            'name' => $this->name,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'brand' => $this->brand,
            'price' => (float) $this->price,
            'sale_price' => $this->sale_price ? (float) $this->sale_price : null,
            'cost_price' => $this->cost_price ? (float) $this->cost_price : null,
            'currency' => $this->currency,
            'stock_status' => $this->stock_status,
            'stock_quantity' => $this->stock_quantity,
            'track_inventory' => $this->track_inventory,
            'images' => $this->images ?? [],
            'thumbnail_url' => $this->thumbnail_url,
            'specifications' => $this->specifications ?? [],
            'variants' => $this->variants ?? [],
            'tags' => $this->tags ?? [],
            'slug' => $this->slug,
            'meta_description' => $this->meta_description,
            'is_active' => $this->is_active,
            'is_featured' => $this->is_featured,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'category' => $this->when($this->category, function () {
                return [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                    'description' => $this->category->description,
                ];
            }),
        ];
    }
}