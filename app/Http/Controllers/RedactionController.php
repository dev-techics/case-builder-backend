<?php

namespace App\Http\Controllers;

use App\Models\Redaction;
use App\Models\Bundle;
use App\Models\Document;
use Illuminate\Http\Request;

class RedactionController extends Controller
{
    /**
     * Get all redactions for a bundle
     * GET /api/bundles/{bundle}/redactions
     */
    public function index(Request $request, Bundle $bundle)
    {
        // Verify user owns the bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $redactions = Redaction::where('bundle_id', $bundle->id)
            ->with('document:id,name') // Include document info
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'redactions' => $redactions,
        ]);
    }

    /**
     * Get redactions for a specific document
     * GET /api/documents/{document}/redactions
     */
    public function getByDocument(Request $request, Document $document)
    {
        // Verify user owns the bundle
        if ($document->bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $redactions = Redaction::where('document_id', $document->id)
            ->orderBy('page_number')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'redactions' => $redactions,
        ]);
    }

    /**
     * Store a newly created redaction
     * POST /api/bundles/{bundle}/redactions
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
            'name' => 'required|string',
            'fill_hex' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'opacity' => 'required|numeric|min:0|max:1',
            'border_hex' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'border_width' => 'required|numeric|min:0',
        ]);

        // Verify document belongs to this bundle
        $document = Document::findOrFail($validated['document_id']);
        if ($document->bundle_id !== $bundle->id) {
            abort(422, 'Document does not belong to this bundle');
        }

        $redaction = Redaction::create([
            'bundle_id' => $bundle->id,
            'document_id' => $validated['document_id'],
            'user_id' => $request->user()->id,
            'page_number' => $validated['page_number'],
            'x' => $validated['x'],
            'y' => $validated['y'],
            'width' => $validated['width'],
            'height' => $validated['height'],
            'name' => $validated['name'],
            'fill_hex' => $validated['fill_hex'],
            'opacity' => $validated['opacity'],
            'border_hex' => $validated['border_hex'],
            'border_width' => $validated['border_width'],
        ]);

        return response()->json([
            'success' => true,
            'redaction' => $redaction,
        ], 201);
    }

    /**
     * Bulk create redactions (for batch operations)
     * POST /api/bundles/{bundle}/redactions/bulk
     */
    public function bulkStore(Request $request, Bundle $bundle)
    {
        // Verify user owns the bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'redactions' => 'required|array|min:1',
            'redactions.*.document_id' => 'required|exists:documents,id',
            'redactions.*.page_number' => 'required|integer|min:1',
            'redactions.*.x' => 'required|numeric',
            'redactions.*.y' => 'required|numeric',
            'redactions.*.width' => 'required|numeric|min:0',
            'redactions.*.height' => 'required|numeric|min:0',
            'redactions.*.name' => 'required|string',
            'redactions.*.fill_hex' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'redactions.*.opacity' => 'required|numeric|min:0|max:1',
            'redactions.*.border_hex' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'redactions.*.border_width' => 'required|numeric|min:0',
        ]);

        $createdRedactions = [];

        foreach ($validated['redactions'] as $redactionData) {
            // Verify document belongs to this bundle
            $document = Document::findOrFail($redactionData['document_id']);
            if ($document->bundle_id !== $bundle->id) {
                continue; // Skip invalid documents
            }

            $redaction = Redaction::create([
                'bundle_id' => $bundle->id,
                'document_id' => $redactionData['document_id'],
                'user_id' => $request->user()->id,
                'page_number' => $redactionData['page_number'],
                'x' => $redactionData['x'],
                'y' => $redactionData['y'],
                'width' => $redactionData['width'],
                'height' => $redactionData['height'],
                'name' => $redactionData['name'],
                'fill_hex' => $redactionData['fill_hex'],
                'opacity' => $redactionData['opacity'],
                'border_hex' => $redactionData['border_hex'],
                'border_width' => $redactionData['border_width'],
            ]);

            $createdRedactions[] = $redaction;
        }

        return response()->json([
            'success' => true,
            'redactions' => $createdRedactions,
            'count' => count($createdRedactions),
        ], 201);
    }

    /**
     * Return a specific redaction
     * GET /api/redactions/{redaction}
     */
    public function show(Request $request, Redaction $redaction)
    {
        // Verify user owns the redaction
        if ($redaction->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        return response()->json([
            'redaction' => $redaction->load('document'),
        ]);
    }

    /**
     * Update a redaction
     * PUT /api/redactions/{redaction}
     */
    public function update(Request $request, Redaction $redaction)
    {
        // Verify user owns the redaction
        if ($redaction->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'page_number' => 'sometimes|required|integer|min:1',
            'x' => 'sometimes|required|numeric',
            'y' => 'sometimes|required|numeric',
            'width' => 'sometimes|required|numeric|min:0',
            'height' => 'sometimes|required|numeric|min:0',
            'name' => 'sometimes|required|string',
            'fill_hex' => 'sometimes|required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'opacity' => 'sometimes|required|numeric|min:0|max:1',
            'border_hex' => 'sometimes|required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'border_width' => 'sometimes|required|numeric|min:0',
        ]);

        $redaction->update($validated);

        return response()->json([
            'success' => true,
            'redaction' => $redaction,
        ]);
    }

    /**
     * Remove a specific redaction
     * DELETE /api/redactions/{redaction}
     */
    public function destroy(Request $request, Redaction $redaction)
    {
        // Verify user owns the redaction
        if ($redaction->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $redaction->delete();

        return response()->json([
            'success' => true,
            'message' => 'Redaction deleted successfully',
        ]);
    }

    /**
     * Bulk delete redactions
     * POST /api/bundles/{bundle}/redactions/bulk-delete
     */
    public function bulkDestroy(Request $request, Bundle $bundle)
    {
        // Verify user owns the bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'redaction_ids' => 'required|array|min:1',
            'redaction_ids.*' => 'required|exists:redactions,id',
        ]);

        // Delete only redactions belonging to this user and bundle
        $deleted = Redaction::whereIn('id', $validated['redaction_ids'])
            ->where('bundle_id', $bundle->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'success' => true,
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Clear all redactions for a document
     * DELETE /api/documents/{document}/redactions
     */
    public function clearDocument(Request $request, Document $document)
    {
        // Verify user owns the bundle
        if ($document->bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $deleted = Redaction::where('document_id', $document->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'success' => true,
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Clear all redactions for a specific page
     * DELETE /api/documents/{document}/pages/{page}/redactions
     */
    public function clearPage(Request $request, Document $document, int $page)
    {
        // Verify user owns the bundle
        if ($document->bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $deleted = Redaction::where('document_id', $document->id)
            ->where('page_number', $page)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'success' => true,
            'deleted_count' => $deleted,
        ]);
    }
}
