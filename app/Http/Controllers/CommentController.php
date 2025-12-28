<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Bundle;
use App\Models\Document;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    /**
     * Get all comments for a bundle
     * GET /api/bundles/{bundle}/comments
     */
    public function index(Request $request, Bundle $bundle)
    {
        // Verify user owns the bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $comments = Comment::where('bundle_id', $bundle->id)
            ->with(['document:id,name', 'user:id,name']) // Include related data
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'comments' => $comments,
        ]);
    }

    /**
     * Get comments for a specific document
     * GET /api/documents/{document}/comments
     */
    public function getByDocument(Request $request, Document $document)
    {
        // Verify user owns the bundle
        if ($document->bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $comments = Comment::where('document_id', $document->id)
            ->with('user:id,name')
            ->orderBy('page_number')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'comments' => $comments,
        ]);
    }

    /**
     * Store a newly created comment
     * POST /api/bundles/{bundle}/comments
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
            'text' => 'required|string',
            'selected_text' => 'nullable|string',
            'x' => 'required|numeric',
            'y' => 'required|numeric',
            'page_y' => 'required|numeric',
        ]);

        // Verify document belongs to this bundle
        $document = Document::findOrFail($validated['document_id']);
        if ($document->bundle_id !== $bundle->id) {
            abort(422, 'Document does not belong to this bundle');
        }

        $comment = Comment::create([
            'bundle_id' => $bundle->id,
            'document_id' => $validated['document_id'],
            'user_id' => $request->user()->id,
            'page_number' => $validated['page_number'],
            'text' => $validated['text'],
            'selected_text' => $validated['selected_text'] ?? null,
            'x' => $validated['x'],
            'y' => $validated['y'],
            'page_y' => $validated['page_y'],
            'resolved' => false,
        ]);

        // Load user relationship for response
        $comment->load('user:id,name');

        return response()->json([
            'success' => true,
            'comment' => $comment,
        ], 201);
    }

    /**
     * Update a comment
     * PUT /api/comments/{comment}
     */
    public function update(Request $request, Comment $comment)
    {
        // Verify user owns the comment
        if ($comment->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'text' => 'sometimes|required|string',
            'resolved' => 'sometimes|boolean',
        ]);

        $comment->update($validated);
        $comment->load('user:id,name');

        return response()->json([
            'success' => true,
            'comment' => $comment,
        ]);
    }

    /**
     * Toggle comment resolved status
     * POST /api/comments/{comment}/toggle-resolved
     */
    public function toggleResolved(Request $request, Comment $comment)
    {
        // Verify user owns the comment
        if ($comment->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $comment->update([
            'resolved' => !$comment->resolved,
        ]);

        return response()->json([
            'success' => true,
            'comment' => $comment,
        ]);
    }

    /**
     * Return a specific comment
     * GET /api/comments/{comment}
     */
    public function show(Request $request, Comment $comment)
    {
        // Verify user owns the comment
        if ($comment->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $comment->load(['user:id,name', 'document:id,name']);

        return response()->json([
            'comment' => $comment,
        ]);
    }

    /**
     * Remove a specific comment
     * DELETE /api/comments/{comment}
     */
    public function destroy(Request $request, Comment $comment)
    {
        // Verify user owns the comment
        if ($comment->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $comment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Comment deleted successfully',
        ]);
    }

    /**
     * Bulk delete comments
     * POST /api/bundles/{bundle}/comments/bulk-delete
     */
    public function bulkDestroy(Request $request, Bundle $bundle)
    {
        // Verify user owns the bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'comment_ids' => 'required|array|min:1',
            'comment_ids.*' => 'required|exists:comments,id',
        ]);

        // Delete only comments belonging to this user and bundle
        $deleted = Comment::whereIn('id', $validated['comment_ids'])
            ->where('bundle_id', $bundle->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'success' => true,
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Clear all comments for a document
     * DELETE /api/documents/{document}/comments
     */
    public function clearDocument(Request $request, Document $document)
    {
        // Verify user owns the bundle
        if ($document->bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $deleted = Comment::where('document_id', $document->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'success' => true,
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Clear all comments for a specific page
     * DELETE /api/documents/{document}/pages/{page}/comments
     */
    public function clearPage(Request $request, Document $document, int $page)
    {
        // Verify user owns the bundle
        if ($document->bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $deleted = Comment::where('document_id', $document->id)
            ->where('page_number', $page)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'success' => true,
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Get unresolved comments count
     * GET /api/bundles/{bundle}/comments/unresolved-count
     */
    public function unresolvedCount(Request $request, Bundle $bundle)
    {
        // Verify user owns the bundle
        if ($bundle->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $count = Comment::where('bundle_id', $bundle->id)
            ->where('resolved', false)
            ->count();

        return response()->json([
            'count' => $count,
        ]);
    }
}