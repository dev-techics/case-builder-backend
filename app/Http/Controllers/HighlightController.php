<?php

namespace App\Http\Controllers;

use App\Models\Highlight;
use App\Models\Bundle;
use App\Models\Document;
use Illuminate\Http\Request;

class HighlightController extends Controller
{
    /**
     * Get all highlights for a bundle
     * GET /api/bundles/{bundle}/highlights
     */
    public function index(Request $request, Bundle $bundle)
    {
        // Verify user owns the bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $highlights = Highlight::where('bundle_id', $bundle->id)
            ->with('document:id,name') // Include document info
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'highlights' => $highlights,
        ]);
    }

    /**
     * Get highlights for a specific document
     * GET /api/documents/{document}/highlights
     */
    public function getByDocument(Request $request, Document $document)
    {
        // Verify user owns the bundle
        if ($document->bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $highlights = Highlight::where('document_id', $document->id)
            ->orderBy('page_number')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'highlights' => $highlights,
        ]);
    }

    /**
     * Store a newly created highlight
     * POST /api/bundles/{bundle}/highlights
     */
    public function store(Request $request, Bundle $bundle)
    {
        // Verify user owns the bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'document_id' => 'required|exists:documents,id',
            'page_number' => 'required|integer|min:1',
            'x' => 'required|numeric',
            'y' => 'required|numeric',
            'width' => 'required|numeric|min:0',
            'height' => 'required|numeric|min:0',
            'text' => 'required|string',
            'color_name' => 'required|string',
            'color_hex' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'color_rgb' => 'required|array',
            'color_rgb.r' => 'required|numeric|min:0|max:255',
            'color_rgb.g' => 'required|numeric|min:0|max:255',
            'color_rgb.b' => 'required|numeric|min:0|max:255',
            'opacity' => 'required|numeric|min:0|max:1',
        ]);

        // Verify document belongs to this bundle
        $document = Document::findOrFail($validated['document_id']);
        if ($document->bundle_id !== $bundle->id) {
            abort(422, 'Document does not belong to this bundle');
        }

        $highlight = Highlight::create([
            'bundle_id' => $bundle->id,
            'document_id' => $validated['document_id'],
            'user_id' => $request->user()->id,
            'page_number' => $validated['page_number'],
            'x' => $validated['x'],
            'y' => $validated['y'],
            'width' => $validated['width'],
            'height' => $validated['height'],
            'text' => $validated['text'],
            'color_name' => $validated['color_name'],
            'color_hex' => $validated['color_hex'],
            'color_rgb' => $validated['color_rgb'],
            'opacity' => $validated['opacity'],
        ]);

        return response()->json([
            'success' => true,
            'highlight' => $highlight,
        ], 201);
    }

    /**
     * Bulk create highlights (for batch operations)
     * POST /api/bundles/{bundle}/highlights/bulk
     */
    public function bulkStore(Request $request, Bundle $bundle)
    {
        // Verify user owns the bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'highlights' => 'required|array|min:1',
            'highlights.*.document_id' => 'required|exists:documents,id',
            'highlights.*.page_number' => 'required|integer|min:1',
            'highlights.*.x' => 'required|numeric',
            'highlights.*.y' => 'required|numeric',
            'highlights.*.width' => 'required|numeric|min:0',
            'highlights.*.height' => 'required|numeric|min:0',
            'highlights.*.text' => 'required|string',
            'highlights.*.color_name' => 'required|string',
            'highlights.*.color_hex' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'highlights.*.color_rgb' => 'required|array',
            'highlights.*.opacity' => 'required|numeric|min:0|max:1',
        ]);

        $createdHighlights = [];

        foreach ($validated['highlights'] as $highlightData) {
            // Verify document belongs to this bundle
            $document = Document::findOrFail($highlightData['document_id']);
            if ($document->bundle_id !== $bundle->id) {
                continue; // Skip invalid documents
            }

            $highlight = Highlight::create([
                'bundle_id' => $bundle->id,
                'document_id' => $highlightData['document_id'],
                'user_id' => $request->user()->id,
                'page_number' => $highlightData['page_number'],
                'x' => $highlightData['x'],
                'y' => $highlightData['y'],
                'width' => $highlightData['width'],
                'height' => $highlightData['height'],
                'text' => $highlightData['text'],
                'color_name' => $highlightData['color_name'],
                'color_hex' => $highlightData['color_hex'],
                'color_rgb' => $highlightData['color_rgb'],
                'opacity' => $highlightData['opacity'],
            ]);

            $createdHighlights[] = $highlight;
        }

        return response()->json([
            'success' => true,
            'highlights' => $createdHighlights,
            'count' => count($createdHighlights),
        ], 201);
    }

    /**
     * Return a specific highlight
     * GET /api/highlights/{highlight}
     */
    public function show(Request $request, Highlight $highlight)
    {
        // Verify user owns the highlight
        if ($highlight->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        return response()->json([
            'highlight' => $highlight->load('document'),
        ]);
    }

    /**
     * Remove a specific highlight
     * DELETE /api/highlights/{highlight}
     */
    public function destroy(Request $request, Highlight $highlight)
    {
        // Verify user owns the highlight
        if ($highlight->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $highlight->delete();

        return response()->json([
            'success' => true,
            'message' => 'Highlight deleted successfully',
        ]);
    }

    /**
     * Bulk delete highlights
     * POST /api/bundles/{bundle}/highlights/bulk-delete
     */
    public function bulkDestroy(Request $request, Bundle $bundle)
    {
        // Verify user owns the bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'highlight_ids' => 'required|array|min:1',
            'highlight_ids.*' => 'required|exists:highlights,id',
        ]);

        // Delete only highlights belonging to this user and bundle
        $deleted = Highlight::whereIn('id', $validated['highlight_ids'])
            ->where('bundle_id', $bundle->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'success' => true,
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Clear all highlights for a document
     * DELETE /api/documents/{document}/highlights
     */
    public function clearDocument(Request $request, Document $document)
    {
        // Verify user owns the bundle
        if ($document->bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $deleted = Highlight::where('document_id', $document->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'success' => true,
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Clear all highlights for a specific page
     * DELETE /api/documents/{document}/pages/{page}/highlights
     */
    public function clearPage(Request $request, Document $document, int $page)
    {
        // Verify user owns the bundle
        if ($document->bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $deleted = Highlight::where('document_id', $document->id)
            ->where('page_number', $page)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'success' => true,
            'deleted_count' => $deleted,
        ]);
    }
}