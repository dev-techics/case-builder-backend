<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BundleController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\HighlightController;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Bundle routes
    Route::prefix('/bundles')->group(function () {
        Route::get('/', [BundleController::class, 'index']);
        Route::post('/', [BundleController::class, 'store']);
        Route::get('/{bundle}', [BundleController::class, 'show']);
        Route::put('/{bundle}', [BundleController::class, 'update']);
        Route::delete('/{bundle}', [BundleController::class, 'destroy']);

        // Document routes nested under bundles
        Route::prefix('/{bundle}/documents')->group(function () {
            // Get file tree for the editor
            Route::get('/', [DocumentController::class, 'index']);
            
            // Upload new files (accepts multiple files)
            Route::post('/upload', [DocumentController::class, 'upload']);
            
            // Create folder
            Route::post('/', [DocumentController::class, 'store']);
            
            // Reorder items (drag & drop)
            Route::post('/reorder', [DocumentController::class, 'reorder']);
        });

        // Highlight routes nested under bundles
        Route::prefix('/{bundle}/highlights')->group(function () {
            // Get all highlights for a bundle
            Route::get('/', [HighlightController::class, 'index']);
            
            // Create a single highlight
            Route::post('/', [HighlightController::class, 'store']);
            
            // Bulk create highlights
            Route::post('/bulk', [HighlightController::class, 'bulkStore']);
            
            // Bulk delete highlights
            Route::post('/bulk-delete', [HighlightController::class, 'bulkDestroy']);
        });
    });

    // Document-specific routes (need document ID directly)
    Route::prefix('/documents')->group(function () {
        // Stream/download file
        Route::get('/{document}/stream', [DocumentController::class, 'stream'])
            ->name('documents.stream');
        
        // Rename file/folder
        Route::patch('/{document}/rename', [DocumentController::class, 'rename']);
        
        // Delete file/folder
        Route::delete('/{document}', [DocumentController::class, 'destroy']);

        // Highlight routes for specific document
        Route::get('/{document}/highlights', [HighlightController::class, 'getByDocument']);
        Route::delete('/{document}/highlights', [HighlightController::class, 'clearDocument']);
        
        // Highlight routes for specific page
        Route::delete('/{document}/pages/{page}/highlights', [HighlightController::class, 'clearPage']);
    });

    // Individual highlight operations
    Route::prefix('/highlights')->group(function () {
        Route::get('/{highlight}', [HighlightController::class, 'show']);
        Route::put('/{highlight}', [HighlightController::class, 'update']);
        Route::delete('/{highlight}', [HighlightController::class, 'destroy']);
    });
});