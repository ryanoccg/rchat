<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\KnowledgeBaseResource;
use App\Models\KnowledgeBase;
use App\Services\KnowledgeBase\DocumentProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KnowledgeBaseController extends Controller
{
    public function __construct(
        protected DocumentProcessor $documentProcessor
    ) {}

    /**
     * List all knowledge base entries for the company
     */
    public function index(Request $request)
    {
        $companyId = $request->company_id;

        $query = KnowledgeBase::where('company_id', $companyId);

        // Filter by category
        if ($request->has('category') && $request->category) {
            $query->where('category', $request->category);
        }

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        $entries = $query->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return KnowledgeBaseResource::collection($entries);
    }

    /**
     * Get all unique categories for the company
     */
    public function categories(Request $request)
    {
        $companyId = $request->company_id;

        $categories = KnowledgeBase::where('company_id', $companyId)
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category');

        return response()->json([
            'data' => $categories,
        ]);
    }

    /**
     * Upload and process a document
     */
    public function store(Request $request)
    {
        $companyId = $request->company_id;

        $validated = $request->validate([
            'file' => 'required_without:content|file|mimes:pdf,txt,docx,doc,csv|max:10240', // 10MB max
            'content' => 'required_without:file|string|max:100000',
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'priority' => 'nullable|integer|min:0|max:100',
        ]);

        $entry = new KnowledgeBase([
            'company_id' => $companyId,
            'title' => $validated['title'],
            'category' => $validated['category'] ?? null,
            'priority' => $validated['priority'] ?? 0,
            'is_active' => true,
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $filePath = "knowledge-base/{$companyId}/{$fileName}";

            // Store file
            Storage::disk('local')->put($filePath, file_get_contents($file));

            $entry->file_name = $file->getClientOriginalName();
            $entry->file_path = $filePath;
            $entry->file_type = $file->getClientOriginalExtension();
            $entry->file_size = $file->getSize();

            // Extract content from file
            $content = $this->documentProcessor->extractText($file);
            $entry->content = $content;
        } else {
            // Direct text content
            $entry->file_type = 'text';
            $entry->content = $validated['content'];
        }

        $entry->save();

        // Create embeddings/chunks for the content
        if ($entry->content) {
            $this->documentProcessor->createChunks($entry);
        }

        return response()->json([
            'message' => 'Knowledge base entry created successfully',
            'data' => new KnowledgeBaseResource($entry),
        ], 201);
    }

    /**
     * Get a single knowledge base entry
     */
    public function show(Request $request, string $id)
    {
        $companyId = $request->company_id;

        $entry = KnowledgeBase::where('company_id', $companyId)
            ->findOrFail($id);

        return response()->json([
            'data' => new KnowledgeBaseResource($entry),
        ]);
    }

    /**
     * Update a knowledge base entry
     */
    public function update(Request $request, string $id)
    {
        $companyId = $request->company_id;

        $entry = KnowledgeBase::where('company_id', $companyId)
            ->findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string|max:100000',
            'category' => 'nullable|string|max:100',
            'priority' => 'sometimes|integer|min:0|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        $contentChanged = isset($validated['content']) && $validated['content'] !== $entry->content;

        $entry->update($validated);

        // Re-create chunks if content changed
        if ($contentChanged) {
            $entry->embeddings()->delete();
            $this->documentProcessor->createChunks($entry);
        }

        return response()->json([
            'message' => 'Knowledge base entry updated successfully',
            'data' => new KnowledgeBaseResource($entry),
        ]);
    }

    /**
     * Delete a knowledge base entry
     */
    public function destroy(Request $request, string $id)
    {
        $companyId = $request->company_id;

        $entry = KnowledgeBase::where('company_id', $companyId)
            ->findOrFail($id);

        // Delete file if exists
        if ($entry->file_path && Storage::disk('local')->exists($entry->file_path)) {
            Storage::disk('local')->delete($entry->file_path);
        }

        $entry->delete();

        return response()->json([
            'message' => 'Knowledge base entry deleted successfully',
        ]);
    }

    /**
     * Toggle active status
     */
    public function toggleStatus(Request $request, string $id)
    {
        $companyId = $request->company_id;

        $entry = KnowledgeBase::where('company_id', $companyId)
            ->findOrFail($id);

        $entry->is_active = !$entry->is_active;
        $entry->save();

        return response()->json([
            'message' => $entry->is_active ? 'Entry activated' : 'Entry deactivated',
            'data' => new KnowledgeBaseResource($entry),
        ]);
    }

    /**
     * Download the original file
     */
    public function download(Request $request, string $id)
    {
        $companyId = $request->company_id;

        $entry = KnowledgeBase::where('company_id', $companyId)
            ->findOrFail($id);

        if (!$entry->file_path || !Storage::disk('local')->exists($entry->file_path)) {
            return response()->json([
                'message' => 'File not found',
            ], 404);
        }

        return Storage::disk('local')->download($entry->file_path, $entry->file_name);
    }

    /**
     * Search knowledge base content
     */
    public function search(Request $request)
    {
        $companyId = $request->company_id;

        $validated = $request->validate([
            'query' => 'required|string|min:3|max:500',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $query = $validated['query'];
        $limit = $validated['limit'] ?? 5;

        // Simple keyword search for now
        // TODO: Implement semantic search with embeddings
        $results = KnowledgeBase::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($q) use ($query) {
                $q->where('content', 'like', "%{$query}%")
                    ->orWhere('title', 'like', "%{$query}%");
            })
            ->orderBy('priority', 'desc')
            ->take($limit)
            ->get(['id', 'title', 'category', 'priority', 'content']);

        // Extract relevant snippets
        $results = $results->map(function ($entry) use ($query) {
            $snippet = $this->extractSnippet($entry->content, $query);
            return [
                'id' => $entry->id,
                'title' => $entry->title,
                'category' => $entry->category,
                'priority' => $entry->priority,
                'snippet' => $snippet,
            ];
        });

        return response()->json([
            'data' => $results,
        ]);
    }

    /**
     * Extract a relevant snippet from content around the search query
     */
    protected function extractSnippet(string $content, string $query, int $contextLength = 150): string
    {
        $position = stripos($content, $query);
        
        if ($position === false) {
            return Str::limit($content, $contextLength * 2);
        }

        $start = max(0, $position - $contextLength);
        $length = strlen($query) + ($contextLength * 2);
        
        $snippet = substr($content, $start, $length);
        
        if ($start > 0) {
            $snippet = '...' . $snippet;
        }
        
        if ($start + $length < strlen($content)) {
            $snippet .= '...';
        }

        return $snippet;
    }
}
