<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\Document;
use App\Services\DocumentTreeBuilder;
use App\Services\PdfModifierService;
use App\Services\IndexGenerationService;
use App\Services\FileConversionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    protected DocumentTreeBuilder $treeBuilder;
    protected PdfModifierService $pdfModifier;
    protected IndexGenerationService $indexGenerator;
    protected FileConversionService $fileConverter;


    public function __construct(DocumentTreeBuilder $treeBuilder, PdfModifierService $pdfModifier, IndexGenerationService $indexGenerator,  FileConversionService $fileConverter)
    {
        $this->treeBuilder = $treeBuilder;
        $this->pdfModifier = $pdfModifier;
        $this->indexGenerator = $indexGenerator;
        $this->fileConverter = $fileConverter;
    }

    /**
     * Move a document to a different parent folder
     * PATCH /api/documents/{document}/move
     */
    public function move(Request $request, Document $document)
    {
        // Check if user owns this bundle
        if ($document->bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $data = $request->validate([
            'parent_id' => 'nullable|exists:documents,id',
        ]);

        // If parent_id is provided, verify it belongs to same bundle and is a folder
        if ($data['parent_id']) {
            $parentFolder = Document::findOrFail($data['parent_id']);

            if ($parentFolder->bundle_id !== $document->bundle_id) {
                return response()->json([
                    'error' => 'Parent folder does not belong to this bundle'
                ], 422);
            }

            if ($parentFolder->type !== 'folder') {
                return response()->json([
                    'error' => 'Parent must be a folder'
                ], 422);
            }

            // Prevent moving a folder into itself or its descendants
            if ($document->type === 'folder') {
                if ($this->isDescendantOf($data['parent_id'], $document->id)) {
                    return response()->json([
                        'error' => 'Cannot move folder into itself or its descendants'
                    ], 422);
                }
            }
        }

        // Get the highest order in the new parent
        $maxOrder = Document::where('bundle_id', $document->bundle_id)
            ->where('parent_id', $data['parent_id'] ?? null)
            ->max('order') ?? -1;

        // Update document's parent and order
        $document->update([
            'parent_id' => $data['parent_id'] ?? null,
            'order' => $maxOrder + 1,
        ]);

        // Regenerate index after moving
        app(\App\Services\IndexGenerationService::class)
            ->generateIndex($document->bundle);

        return response()->json([
            'success' => true,
            'message' => 'Document moved successfully',
            'document' => [
                'id' => $document->id,
                'parent_id' => $document->parent_id,
                'order' => $document->order,
            ],
        ]);
    }

    /**
     * Check if a folder is a descendant of another folder
     */
    private function isDescendantOf(string $potentialDescendantId, string $ancestorId): bool
    {
        $current = Document::find($potentialDescendantId);

        while ($current && $current->parent_id) {
            if ($current->parent_id === $ancestorId) {
                return true;
            }
            $current = Document::find($current->parent_id);
        }

        return false;
    }


    /**
     * * Get full document tree for a bundle (Editor load)
     * * GET /api/bundles/{bundle}/documents
     */
    public function index(Request $request, Bundle $bundle)
    {
        // Check if user owns this bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        // Regenerate index if needed
        if ($this->indexGenerator->needsRegeneration($bundle)) {
            $this->indexGenerator->generateIndex($bundle);
        }

        $documents = Document::where('bundle_id', $bundle->id)->get();
        // Log::info("Documents", [dump($documents)]);
        // Get index path
        $indexPath = $this->indexGenerator->getIndexPath($bundle);

        $tree = [
            'id' => 'bundle-' . $bundle->id,
            'projectName' => $bundle->name,
            'type' => 'folder',
            'indexUrl' => $indexPath ? route('bundles.index-stream', $bundle->id) : null,
            'children' => $this->treeBuilder->build($documents),
        ];
        // Log::info("Document tree builder: ", $this->treeBuilder->build($documents));
        return response()->json($tree);
    }

    /**
     * Upload one or multiple files (PDF, images, documents, etc.)
     * POST /api/bundles/{bundle}/documents/upload
     */
    public function upload(Request $request, Bundle $bundle)
    {
        Log::info('Upload request received', ['request' => $request->all()]);

        // Check if user owns this bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|max:102400', // 100MB max per file
            'parent_id' => 'nullable|exists:documents,id',
        ]);

        $parentId = $request->parent_id;
        $uploadedDocuments = [];
        $conversionStatuses = [];

        // Get the current max order for this parent
        $maxOrder = Document::where('bundle_id', $bundle->id)
            ->where('parent_id', $parentId)
            ->max('order') ?? -1;

        foreach ($request->file('files') as $uploadedFile) {
            $maxOrder++;

            try {
                $originalName = $uploadedFile->getClientOriginalName();
                $mimeType = $uploadedFile->getMimeType();
                $fileSize = $uploadedFile->getSize();

                Log::info('Processing file', [
                    'name' => $originalName,
                    'mime_type' => $mimeType,
                    'size' => $fileSize
                ]);

                $storagePath = null;
                $wasConverted = false;

                // Check if file is already a PDF
                if ($mimeType === 'application/pdf') {
                    // Store PDF directly
                    $filename = Str::uuid() . '.pdf';
                    $storagePath = "bundles/{$bundle->id}/" . $filename;
                    Storage::put($storagePath, file_get_contents($uploadedFile));

                    Log::info('PDF stored directly', ['path' => $storagePath]);
                } elseif ($this->fileConverter->isSupported($mimeType)) {
                    // Convert to PDF
                    Log::info('Converting file to PDF', [
                        'name' => $originalName,
                        'type' => $this->fileConverter->getFileTypeName($mimeType)
                    ]);

                    try {
                        // Save uploaded file temporarily
                        $tempPath = $uploadedFile->store('temp');
                        $tempFullPath = Storage::path($tempPath);

                        // Convert to PDF
                        $convertedTempPath = $this->fileConverter->convertToPdf(
                            $tempFullPath,
                            $mimeType,
                            $originalName
                        );

                        // Move converted PDF to final location
                        $filename = Str::uuid() . '.pdf';
                        $storagePath = "bundles/{$bundle->id}/" . $filename;

                        Storage::move($convertedTempPath, $storagePath);

                        // Clean up temp file
                        Storage::delete($tempPath);

                        $wasConverted = true;

                        $conversionStatuses[] = [
                            'fileName' => $originalName,
                            'status' => 'success',
                            'message' => 'Converted to PDF successfully'
                        ];

                        Log::info('File converted and stored', ['path' => $storagePath]);
                    } catch (\Exception $e) {
                        Log::error('Conversion failed', [
                            'file' => $originalName,
                            'error' => $e->getMessage()
                        ]);

                        $conversionStatuses[] = [
                            'fileName' => $originalName,
                            'status' => 'failed',
                            'message' => $e->getMessage()
                        ];

                        // Skip this file if conversion fails
                        continue;
                    }
                } else {
                    // Unsupported file type
                    Log::warning('Unsupported file type', [
                        'file' => $originalName,
                        'mime_type' => $mimeType
                    ]);

                    $conversionStatuses[] = [
                        'fileName' => $originalName,
                        'status' => 'failed',
                        'message' => 'Unsupported file type'
                    ];

                    continue;
                }

                // Create document record
                $document = Document::create([
                    'bundle_id' => $bundle->id,
                    'parent_id' => $parentId,
                    'name' => $originalName,
                    'type' => 'file',
                    'mime_type' => 'application/pdf',
                    'storage_path' => $storagePath,
                    'order' => $maxOrder,
                    'metadata' => [
                        'size' => $fileSize,
                        'original_name' => $originalName,
                        'original_mime_type' => $mimeType,
                        'was_converted' => $wasConverted,
                        'conversion_source' => $wasConverted ? $this->fileConverter->getFileTypeName($mimeType) : null,
                    ],
                ]);

                $uploadedDocuments[] = [
                    'id' => (string) $document->id,
                    'parent_id' => $document->parent_id,
                    'name' => $document->name,
                    'type' => 'file',
                    'url' => route('documents.stream', $document->id),
                    'was_converted' => $wasConverted,
                ];
            } catch (\Exception $e) {
                Log::error('Failed to process file', [
                    'file' => $uploadedFile->getClientOriginalName(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                $conversionStatuses[] = [
                    'fileName' => $uploadedFile->getClientOriginalName(),
                    'status' => 'failed',
                    'message' => 'Failed to process file: ' . $e->getMessage()
                ];
            }
        }

        // Regenerate index after upload
        $this->indexGenerator->generateIndex($bundle);

        $response = [
            'success' => true,
            'message' => count($uploadedDocuments) . ' file(s) uploaded successfully',
            'documents' => $uploadedDocuments,
        ];

        // Include conversion statuses if any conversions were attempted
        if (!empty($conversionStatuses)) {
            $response['conversion_statuses'] = $conversionStatuses;
        }

        return response()->json($response, 201);
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

        // Regenerate index after folder creation
        $this->indexGenerator->generateIndex($bundle);

        return response()->json([
            'id' => (string) $document->id,
            'name' => $document->name,
            'type' => 'folder',
            'children' => [],
        ], 201);
    }

    /**
     * Stream a PDF securely
     * GET /api/documents/{document}/stream?original=true
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

        // Check if original PDF is requested (for export purposes)
        $returnOriginal = $request->query('original', 'false') === 'true';

        if ($returnOriginal) {
            Log::info('Serving original PDF (for export)', ['document_id' => $document->id]);

            // Stream original PDF without any modifications
            return response()->stream(function () use ($document) {
                $stream = Storage::readStream($document->storage_path);
                fpassthru($stream);
                fclose($stream);
            }, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Length' => Storage::size($document->storage_path),
                'Content-Disposition' => 'inline; filename="' . $document->name . '"',
                'X-PDF-Modified' => 'false',
                'X-PDF-Original' => 'true',
            ]);
        }

        // Get full path to original file
        $sourcePath = Storage::path($document->storage_path);

        // Get header/footer from bundle metadata
        $bundle = $document->bundle;
        $metadata = $bundle->metadata ?? [];

        Log::info('Bundle metadata', ['metadata' => $metadata]);

        $headerFooter = [
            'headerLeft' => $metadata['header_left'] ?? '',
            'headerRight' => $metadata['header_right'] ?? '',
            'footer' => $metadata['footer'] ?? '',
        ];

        Log::info('Header/Footer config', ['config' => $headerFooter]);

        // Check if any header/footer is set (check for non-empty strings)
        $hasModifications = (!empty($headerFooter['headerLeft']) && $headerFooter['headerLeft'] !== '') ||
            (!empty($headerFooter['headerRight']) && $headerFooter['headerRight'] !== '') ||
            (!empty($headerFooter['footer']) && $headerFooter['footer'] !== '');

        Log::info('Has modifications?', ['has_modifications' => $hasModifications]);

        if ($hasModifications) {
            // Generate cache key based on document and bundle metadata
            $cacheKey = md5($document->id . json_encode($headerFooter));

            try {
                Log::info('Attempting to generate modified PDF', [
                    'document_id' => $document->id,
                    'cache_key' => $cacheKey
                ]);

                // Generate modified PDF (uses cache if available)
                $modifiedPath = $this->pdfModifier->generate(
                    $sourcePath,
                    $headerFooter,
                    $cacheKey
                );

                Log::info('Modified PDF generated', ['path' => $modifiedPath]);

                // Stream the modified PDF
                return response()->stream(function () use ($modifiedPath) {
                    $stream = Storage::readStream($modifiedPath);
                    fpassthru($stream);
                    fclose($stream);
                }, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Length' => Storage::size($modifiedPath),
                    'Content-Disposition' => 'inline; filename="' . $document->name . '"',
                    'X-PDF-Modified' => 'true',
                ]);
            } catch (\Exception $e) {
                Log::error('PDF modification failed', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                // Fall back to original PDF if modification fails
            }
        }

        Log::info('Serving original PDF');

        // Stream original PDF (no modifications or fallback)
        return response()->stream(function () use ($document) {
            $stream = Storage::readStream($document->storage_path);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => Storage::size($document->storage_path),
            'Content-Disposition' => 'inline; filename="' . $document->name . '"',
            'X-PDF-Modified' => 'false',
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

        // Regenerate index after rename
        $this->indexGenerator->generateIndex($document->bundle);

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

        // If it's a folder, delete all children recursively
        if ($document->isFolder()) {
            $children = Document::where('parent_id', $document->id)->get();
            foreach ($children as $child) {
                $this->destroy($child, $request);
            }
        }

        $document->delete();
        // Regenerate index after deletion
        $this->indexGenerator->generateIndex($document->bundle);

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

        // Regenerate index after reorder
        $this->indexGenerator->generateIndex($bundle);

        return response()->json([
            'success' => true,
            'message' => 'Documents reordered successfully',
        ]);
    }
}
