<?php

namespace App\Http\Controllers;

use App\Models\Bundle;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class BundleController extends Controller
{
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