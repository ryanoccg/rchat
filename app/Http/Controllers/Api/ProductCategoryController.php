<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCategoryResource;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductCategoryController extends Controller
{
    /**
     * Get all categories for the company
     */
    public function index(Request $request)
    {
        $query = ProductCategory::where('company_id', $request->company_id);

        // Filter by parent (root categories if parent_id is null)
        if ($request->has('parent_id')) {
            if ($request->parent_id === 'null' || $request->parent_id === '') {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $request->parent_id);
            }
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $categories = $query->with(['parent', 'children'])
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'categories' => ProductCategoryResource::collection($categories),
        ]);
    }

    /**
     * Get category tree structure
     */
    public function tree(Request $request)
    {
        $categories = ProductCategory::where('company_id', $request->company_id)
            ->whereNull('parent_id')
            ->with(['children' => function ($q) {
                $q->orderBy('sort_order')->with('children');
            }])
            ->withCount('products')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'categories' => ProductCategoryResource::collection($categories),
        ]);
    }

    /**
     * Store a new category
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:product_categories,id',
            'description' => 'nullable|string',
            'image_url' => 'nullable|url',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $validated['company_id'] = $request->company_id;
        $validated['slug'] = Str::slug($validated['name']);

        // Ensure unique slug within company
        $slugBase = $validated['slug'];
        $counter = 1;
        while (ProductCategory::where('company_id', $validated['company_id'])
            ->where('slug', $validated['slug'])
            ->exists()) {
            $validated['slug'] = $slugBase . '-' . $counter++;
        }

        $category = ProductCategory::create($validated);

        return response()->json([
            'message' => 'Category created successfully',
            'category' => new ProductCategoryResource($category->load('parent')),
        ], 201);
    }

    /**
     * Get a specific category
     */
    public function show(Request $request, ProductCategory $category)
    {
        if ($category->company_id !== $request->company_id) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        return response()->json([
            'category' => new ProductCategoryResource($category->load(['parent', 'children', 'products'])),
        ]);
    }

    /**
     * Update a category
     */
    public function update(Request $request, ProductCategory $category)
    {
        if ($category->company_id !== $request->company_id) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'parent_id' => 'nullable|exists:product_categories,id',
            'description' => 'nullable|string',
            'image_url' => 'nullable|url',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        // Prevent setting self as parent
        if (isset($validated['parent_id']) && $validated['parent_id'] == $category->id) {
            return response()->json([
                'message' => 'Category cannot be its own parent',
            ], 422);
        }

        // Update slug if name changed
        if (isset($validated['name']) && $validated['name'] !== $category->name) {
            $validated['slug'] = Str::slug($validated['name']);
            $slugBase = $validated['slug'];
            $counter = 1;
            while (ProductCategory::where('company_id', $category->company_id)
                ->where('slug', $validated['slug'])
                ->where('id', '!=', $category->id)
                ->exists()) {
                $validated['slug'] = $slugBase . '-' . $counter++;
            }
        }

        $category->update($validated);

        return response()->json([
            'message' => 'Category updated successfully',
            'category' => new ProductCategoryResource($category->fresh(['parent', 'children'])),
        ]);
    }

    /**
     * Delete a category
     */
    public function destroy(Request $request, ProductCategory $category)
    {
        if ($category->company_id !== $request->company_id) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        // Check if category has products
        if ($category->products()->exists()) {
            return response()->json([
                'message' => 'Cannot delete category with products. Move or delete products first.',
            ], 422);
        }

        // Move child categories to parent
        $category->children()->update(['parent_id' => $category->parent_id]);

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully',
        ]);
    }

    /**
     * Toggle category active status
     */
    public function toggleStatus(Request $request, ProductCategory $category)
    {
        if ($category->company_id !== $request->company_id) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $category->update(['is_active' => !$category->is_active]);

        return response()->json([
            'message' => 'Category status updated',
            'category' => new ProductCategoryResource($category),
        ]);
    }

    /**
     * Reorder categories
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => 'required|exists:product_categories,id',
            'categories.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($validated['categories'] as $item) {
            ProductCategory::where('id', $item['id'])
                ->where('company_id', $request->company_id)
                ->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json([
            'message' => 'Categories reordered successfully',
        ]);
    }
}
