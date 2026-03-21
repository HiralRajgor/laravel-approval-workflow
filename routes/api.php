<?php

use App\Http\Controllers\Api\DocumentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Document Approval Workflow
|--------------------------------------------------------------------------
*/

// Public health-check (useful for load balancers / uptime monitors)
Route::get('/health', fn () => response()->json(['status' => 'ok', 'timestamp' => now()->toISOString()]));

// All document routes require authentication via Sanctum
Route::middleware('auth:sanctum')->group(function () {

    // ── Authenticated user ──────────────────────────────────────────────────
    Route::get('/me', fn (Request $request) => $request->user());

    // ── Documents (standard resource routes) ───────────────────────────────
    Route::apiResource('documents', DocumentController::class);

    // ── Workflow-specific actions ───────────────────────────────────────────
    Route::post('documents/{document}/transition', [DocumentController::class, 'transition'])->name('documents.transition');
    Route::get('documents/{document}/history', [DocumentController::class, 'history'])->name('documents.history');
    Route::get('documents/{document}/audit', [DocumentController::class, 'auditLog'])->name('documents.audit');

});
