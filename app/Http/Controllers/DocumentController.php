<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\Document;
use App\Services\DocumentTreeBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    protected DocumentTreeBuilder $treeBuilder;

    public function __construct(DocumentTreeBuilder $treeBuilder)
    {
        $this->treeBuilder = $treeBuilder;
    }

    /**
     * Get full document tree for a bundle (Editor load)
     * GET /api/bundles/{bundle}/documents
     */
    public function index(Request $request, Bundle $bundle)
    {
        // Check if user owns this bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $documents = Document::where('bundle_id', $bundle->id)->get();

        $tree = [
            'id' => 'bundle-' . $bundle->id,
            'projectName' => $bundle->name,
            'type' => 'folder',
            'children' => $this->treeBuilder->build($documents),
        ];

        return response()->json($tree);
    }

    /**
     * Upload one or multiple PDF files
     * POST /api/bundles/{bundle}/documents/upload
     */
    public function upload(Request $request, Bundle $bundle)
    {
        Log::info('Upload request received', ['request' => $request->all()]);
        // echo 'Upload request received'; // For debugging
        // Check if user owns this bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }
        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|mimes:pdf|max:102400', // 100MB max per file
            'parent_id' => 'nullable|exists:documents,id',
        ]);

        $parentId = $request->parent_id;
        $uploadedDocuments = [];

        // Get the current max order for this parent
        $maxOrder = Document::where('bundle_id', $bundle->id)
            ->where('parent_id', $parentId)
            ->max('order') ?? -1;

        foreach ($request->file('files') as $file) {
            $maxOrder++;
            
            // Generate unique filename
            $filename = Str::uuid() . '.pdf';
            $path = "bundles/{$bundle->id}/" . $filename;

            // Store file
            Storage::put($path, file_get_contents($file));

            // Get file metadata
            $fileSize = $file->getSize();
            
            // Create document record
            $document = Document::create([
                'bundle_id' => $bundle->id,
                'parent_id' => $parentId,
                'name' => $file->getClientOriginalName(),
                'type' => 'file',
                'mime_type' => 'application/pdf',
                'storage_path' => $path,
                'order' => $maxOrder,
                'metadata' => [
                    'size' => $fileSize,
                    'original_name' => $file->getClientOriginalName(),
                ],
            ]);

            $uploadedDocuments[] = [
                'id' => (string) $document->id,
                'name' => $document->name,
                'type' => 'file',
                'url' => route('documents.stream', $document->id),
            ];
        }

        return response()->json([
            'success' => true,
            'message' => count($uploadedDocuments) . ' file(s) uploaded successfully',
            'documents' => $uploadedDocuments,
        ], 201);
    }

    /**
     * Store a new folder
     * POST /api/bundles/{bundle}/documents
     */
    public function store(Request $request, Bundle $bundle)
    {
        // Check if user owns this bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $data = $request->validate([
            'name' => 'required|string',
            'type' => 'required|in:folder',
            'parent_id' => 'nullable|exists:documents,id',
        ]);

        // Verify parent belongs to same bundle
        if ($data['parent_id']) {
            $parent = Document::findOrFail($data['parent_id']);
            if ($parent->bundle_id !== $bundle->id) {
                return response()->json([
                    'error' => 'Parent folder does not belong to this bundle'
                ], 422);
            }
        }

        $document = Document::create([
            'bundle_id' => $bundle->id,
            'parent_id' => $data['parent_id'] ?? null,
            'name' => $data['name'],
            'type' => 'folder',
            'order' => Document::where('bundle_id', $bundle->id)
                ->where('parent_id', $data['parent_id'] ?? null)
                ->max('order') + 1,
        ]);

        return response()->json([
            'id' => (string) $document->id,
            'name' => $document->name,
            'type' => 'folder',
            'children' => [],
        ], 201);
    }

    /**
     * Stream a PDF securely
     * GET /api/documents/{document}/stream
     */
    public function stream(Document $document, Request $request): StreamedResponse
    {
        // Check if user owns this bundle
        if ($document->bundle->user_id !== $request->user()->id) {  
            abort(403, 'Unauthorized');
        }

        if (!$document->storage_path || !Storage::exists($document->storage_path)) {
            abort(404, 'File not found');
        }

        return response()->stream(function () use ($document) {
            $stream = Storage::readStream($document->storage_path);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $document->name . '"',
        ]);
    }

    /**
     * Rename file or folder
     * PATCH /api/documents/{document}/rename
     */
    public function rename(Request $request, Document $document)
    {
        // Check if user owns this bundle
        if ($document->bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $document->update([
            'name' => $request->name,
        ]);

        return response()->json([
            'success' => true,
            'document' => [
                'id' => (string) $document->id,
                'name' => $document->name,
            ],
        ]);
    }

    /**
     * Delete file or folder (soft delete)
     * DELETE /api/documents/{document}
     */
    public function destroy(Document $document, Request $request)
    {
        // Check if user owns this bundle
        if ($document->bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        // If it's a file, optionally delete from storage
        if ($document->isFile() && $document->storage_path) {
            Storage::delete($document->storage_path);
        }

        $document->delete();

        return response()->json([
            'success' => true,
            'message' => 'Document deleted successfully',
        ]);
    }

    /**
     * Reorder files/folders (drag & drop)
     * POST /api/bundles/{bundle}/documents/reorder
     */
    public function reorder(Request $request, Bundle $bundle)
    {
        // Check if user owns this bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:documents,id',
            'items.*.order' => 'required|integer|min:0',
        ]);

        foreach ($request->items as $item) {
            Document::where('id', $item['id'])
                ->where('bundle_id', $bundle->id) // Security: ensure it belongs to this bundle
                ->update(['order' => $item['order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Documents reordered successfully',
        ]);
    }
}