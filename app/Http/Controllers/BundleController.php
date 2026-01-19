<?php

namespace App\Http\Controllers;

use App\Models\Bundle;
use App\Services\BundleExportService;
use App\Services\IndexGenerationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;


class BundleController extends Controller
{
    protected IndexGenerationService $indexGenerator;

     public function __construct(
        private BundleExportService $exportService,
        IndexGenerationService $indexGenerator
    ) {
        $this->indexGenerator = $indexGenerator;
    }

     public function streamIndex(Bundle $bundle, Request $request): StreamedResponse
    {
        // Check if user owns this bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        // Regenerate if needed
        if ($this->indexGenerator->needsRegeneration($bundle)) {
            $this->indexGenerator->generateIndex($bundle);
        }

        $indexPath = $this->indexGenerator->getIndexPath($bundle);

        if (!$indexPath || !Storage::exists($indexPath)) {
            abort(404, 'Index not found');
        }

        return response()->stream(function () use ($indexPath) {
            $stream = Storage::readStream($indexPath);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => Storage::size($indexPath),
            'Content-Disposition' => 'inline; filename="index.pdf"',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

        /**
     * Export bundle as single PDF
     * POST /api/bundles/{bundle}/export
     */
    public function export(Bundle $bundle, Request $request)
    {
        // Check authorization
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'include_index' => 'boolean',
            'index_entries' => 'array',
            'highlights' => 'array',
        ]);

        try {
            $path = $this->exportService->exportBundle(
                $bundle,
                $validated['include_index'] ?? true,
                $validated['index_entries'] ?? null,
                $validated['highlights'] ?? null
            );

            // Return download response
            return response()->download(
                Storage::path($path),
                basename($path),
                ['Content-Type' => 'application/pdf']
            )->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Export failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

   /**
     * Display a listing of bundles for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $bundles = Bundle::where('user_id', Auth::id())
            ->whereNull('deleted_at')
            ->withCount('documents')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($bundles);
    }

    /**
     * Store a newly created bundle.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'case_number' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'metadata.headers' => 'nullable|array',
            'metadata.headers.left' => 'nullable|array',
            'metadata.headers.right' => 'nullable|array',
            'metadata.footer' => 'nullable|array',
            'metadata.pageNumber' => 'nullable|array',
        ]);

        $bundle = Bundle::create([
            'user_id' => Auth::id(),
            'name' => $validated['name'],
            'case_number' => $validated['case_number'] ?? null,
            'total_documents' => 0,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return response()->json([
            'message' => 'Bundle created successfully',
            'bundle' => $bundle
        ], 201);
    }

    /**
     * Display the specified bundle.
     */
    public function show(Bundle $bundle): JsonResponse
    {
        // Check if the bundle belongs to the authenticated user
        if ($bundle->user_id !== Auth::id()) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        // Load documents relationship
        $bundle->load('documents');

        return response()->json($bundle);
    }

    /**
     * Update the specified bundle.
     */
    public function update(Request $request, Bundle $bundle): JsonResponse
    {
        // Check if the bundle belongs to the authenticated user
        if ($bundle->user_id !== Auth::id()) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'case_number' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'metadata.headers' => 'nullable|array',
            'metadata.headers.left' => 'nullable|array',
            'metadata.headers.right' => 'nullable|array',
            'metadata.footer' => 'nullable|array',
            'metadata.pageNumber' => 'nullable|array',
        ]);

        $bundle->update($validated);

        return response()->json([
            'message' => 'Bundle updated successfully',
            'bundle' => $bundle
        ]);
    }
    
     /**
     * Update bundle metadata (headers/footers)
     * PATCH /api/bundles/{bundle}/metadata
     */

    public function updateMetadata(Request $request, Bundle $bundle): JsonResponse
    {
        // Check if the bundle belongs to the authenticated user
        if ($bundle->user_id !== Auth::id()) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'header_left' => 'nullable|string|max:255',
            'header_right' => 'nullable|string|max:255',
            'footer' => 'nullable|string|max:255',
        ]);

        // Merge with existing metadata
        $metadata = $bundle->metadata ?? [];
        $metadata['header_left'] = $validated['header_left'] ?? '';
        $metadata['header_right'] = $validated['header_right'] ?? '';
        $metadata['footer'] = $validated['footer'] ?? '';

        $bundle->update(['metadata' => $metadata]);

        return response()->json([
            'message' => 'Bundle metadata updated successfully',
            'metadata' => $metadata
        ]);
    }

    /**
     * Remove the specified bundle.
     */
    public function destroy(Bundle $bundle): JsonResponse
    {
        // Check if the bundle belongs to the authenticated user
        if ($bundle->user_id !== Auth::id()) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $bundle->softDelete();

        return response()->json([
            'message' => 'Bundle deleted successfully'
        ]);
    }
}