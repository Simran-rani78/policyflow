<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PolicyRequestController;
use App\Http\Controllers\Api\InsurerController;

// Policy Request Core Endpoints
Route::post('/policy-requests', [PolicyRequestController::class, 'store']);
Route::get('/policy-requests', [PolicyRequestController::class, 'index']);
Route::get('/policy-requests/{id}', [PolicyRequestController::class, 'show']);

// Insurer Submission, Reconciliation & Webhook Endpoints
Route::post('/policy-requests/{id}/submit', [InsurerController::class, 'submit']);
Route::post('/policy-requests/{id}/reconcile', [InsurerController::class, 'reconcile']);
Route::get('/mock-insurer/status/{provider_reference}', [InsurerController::class, 'statusLookup']);
Route::post('/mock-insurer/callback', [InsurerController::class, 'callback']);
