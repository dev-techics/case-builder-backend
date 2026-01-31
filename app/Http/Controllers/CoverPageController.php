<?php

namespace App\Http\Controllers;

use App\Models\CoverPage;
use App\Http\Requests\UpdateCoverPageRequest;
use App\Http\Requests\StoreCoverPageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;


class CoverPageController extends Controller
{
    /**
     * Get all cover pages for authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        $query = CoverPage::where('user_id', Auth::id());

        // Filter by type if provided
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Order by default first, then by created_at
        $coverPages = $query
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($coverPages);
    }

    /**
     * Get a specific cover page
     * TODO: fix it
     */
    public function show(CoverPage $coverPage): JsonResponse
    {
        $this->authorize('view', $coverPage);

        return response()->json($coverPage);
    }

    /**
     * Create a new cover page
     */
    public function store(StoreCoverPageRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = Auth::id();

        $coverPage = CoverPage::create($data);

        if (!empty($data['is_default'])) {
            $coverPage->setAsDefault();
        }

        return response()->json([
            'message' => 'Cover page created successfully',
            'cover_page' => $coverPage->fresh(),
        ], 201);
    }

    /**
     * Update an existing cover page
     */
    
    public function update(Request $request, CoverPage $coverPage): JsonResponse
    {
        // Ensure the user owns this cover page
        if ($coverPage->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|max:500',
            'type' => 'sometimes|in:front,back',
            'template_key' => 'sometimes|string|max:100',
            'values' => 'sometimes|array',
            'is_default' => 'sometimes|boolean',
        ]);

        Log::info("cover page info:",$validated);

        // Update the cover page
        $coverPage->update($validated);

        // If marked as default, set it
        if ($request->is_default) {
            $coverPage->setAsDefault();
        }

        return response()->json([
            'message' => 'Cover page updated successfully',
            'cover_page' => $coverPage->fresh(),
        ]);
    }


    /**
     * Delete a cover page
     */
    public function destroy(CoverPage $coverPage): JsonResponse
    {
        $this->authorize('delete', $coverPage);

        // Check if used in any bundles
        if ($coverPage->bundles()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete cover page that is in use by bundles',
                'bundles_count' => $coverPage->bundles()->count(),
            ], 422);
        }

        $coverPage->delete();

        return response()->json([
            'message' => 'Cover page deleted successfully',
        ]);
    }

    /**
     * Duplicate a cover page
     */
    public function duplicate(CoverPage $coverPage): JsonResponse
    {
        $this->authorize('view', $coverPage);

        $newCoverPage = $coverPage->replicate();
        $newCoverPage->name = $coverPage->name . ' (Copy)';
        $newCoverPage->is_default = false;
        $newCoverPage->user_id = Auth::id();
        $newCoverPage->save();

        return response()->json([
            'message' => 'Cover page duplicated successfully',
            'cover_page' => $newCoverPage,
        ], 201);
    }

    /**
     * Set cover page as default
     */
    public function setDefault(CoverPage $coverPage): JsonResponse
    {
        $this->authorize('update', $coverPage);

        $coverPage->setAsDefault();

        return response()->json([
            'message' => 'Cover page set as default',
            'cover_page' => $coverPage->fresh(),
        ]);
    }
}
