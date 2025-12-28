<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BundleController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\HighlightController;
use App\Http\Controllers\CommentController;

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
            Route::get('/', [DocumentController::class, 'index']);
            Route::post('/upload', [DocumentController::class, 'upload']);
            Route::post('/', [DocumentController::class, 'store']);
            Route::post('/reorder', [DocumentController::class, 'reorder']);
        });

        // Highlight routes nested under bundles
        Route::prefix('/{bundle}/highlights')->group(function () {
            Route::get('/', [HighlightController::class, 'index']);
            Route::post('/', [HighlightController::class, 'store']);
            Route::post('/bulk', [HighlightController::class, 'bulkStore']);
            Route::post('/bulk-delete', [HighlightController::class, 'bulkDestroy']);
        });

        // Comment routes nested under bundles
        Route::prefix('/{bundle}/comments')->group(function () {
            // Get all comments for a bundle
            Route::get('/', [CommentController::class, 'index']);
            
            // Create a comment
            Route::post('/', [CommentController::class, 'store']);
            
            // Bulk delete comments
            Route::post('/bulk-delete', [CommentController::class, 'bulkDestroy']);
            
            // Get unresolved count
            Route::get('/unresolved-count', [CommentController::class, 'unresolvedCount']);
        });
    });

    // Document-specific routes
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
        Route::delete('/{document}/pages/{page}/highlights', [HighlightController::class, 'clearPage']);

        // Comment routes for specific document
        Route::get('/{document}/comments', [CommentController::class, 'getByDocument']);
        Route::delete('/{document}/comments', [CommentController::class, 'clearDocument']);
        Route::delete('/{document}/pages/{page}/comments', [CommentController::class, 'clearPage']);
    });

    // Individual highlight operations
    Route::prefix('/highlights')->group(function () {
        Route::get('/{highlight}', [HighlightController::class, 'show']);
        Route::put('/{highlight}', [HighlightController::class, 'update']);
        Route::delete('/{highlight}', [HighlightController::class, 'destroy']);
    });

    // Individual comment operations
    Route::prefix('/comments')->group(function () {
        Route::get('/{comment}', [CommentController::class, 'show']);
        Route::put('/{comment}', [CommentController::class, 'update']);
        Route::delete('/{comment}', [CommentController::class, 'destroy']);
        Route::post('/{comment}/toggle-resolved', [CommentController::class, 'toggleResolved']);
    });
});