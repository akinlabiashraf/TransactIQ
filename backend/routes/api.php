<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\SettlementController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| TransactIQ API Routes - Version 1
|--------------------------------------------------------------------------
|
| Core payment infrastructure, authentication, merchant management,
| ledger, webhooks, reconciliation, and security endpoints.
|
*/

Route::prefix('v1')->group(function () {
    // Infrastructure Health Check & OpenAPI Spec
    Route::get('/health', [HealthController::class, 'check']);
    Route::get('/docs/openapi.json', [\App\Http\Controllers\DocsController::class, 'openapi']);

    // Public Loopback Webhook Receiver Endpoint for testing
    Route::post('/webhooks/test-endpoint', [WebhookController::class, 'testEndpoint']);

    // Platform User Authentication (Sanctum)
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:15,1');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

    // Authenticated User Context (Legacy Sanctum route)
    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware('auth:sanctum');

    // Authenticated Operations & Merchant Protected Endpoints
    Route::middleware('merchant.auth')->group(function () {
        // Payments Engine (with rate limiting and RBAC)
        Route::post('/payments', [PaymentController::class, 'store'])
            ->middleware(['throttle:payments', 'role:merchant,admin']);
        Route::get('/payments', [PaymentController::class, 'index'])
            ->middleware('role:merchant,admin,auditor,operations');
        Route::get('/payments/{reference}', [PaymentController::class, 'show'])
            ->middleware('role:merchant,admin,auditor,operations');

        // Webhook Delivery Engine
        Route::get('/webhooks', [WebhookController::class, 'index'])
            ->middleware('role:merchant,admin,operations,auditor');
        Route::get('/webhooks/{id}', [WebhookController::class, 'show'])
            ->middleware('role:merchant,admin,operations,auditor');
        Route::post('/webhooks/{id}/replay', [WebhookController::class, 'replay'])
            ->middleware('role:admin,operations,merchant');

        // Double-Entry Financial Ledger
        Route::get('/ledger/accounts', [LedgerController::class, 'accounts'])
            ->middleware('role:admin,operations,auditor,merchant');
        Route::get('/ledger/entries', [LedgerController::class, 'entries'])
            ->middleware('role:admin,operations,auditor,merchant');
        Route::get('/ledger/integrity', [LedgerController::class, 'integrity'])
            ->middleware('role:admin,auditor,operations,merchant');

        // Merchant Settlements Engine
        Route::get('/settlements', [SettlementController::class, 'index'])
            ->middleware('role:merchant,admin,operations,auditor');
        Route::get('/settlements/{id}', [SettlementController::class, 'show'])
            ->middleware('role:merchant,admin,operations,auditor');
        Route::post('/settlements/generate', [SettlementController::class, 'generate'])
            ->middleware('role:admin,operations,merchant');
        Route::post('/settlements/{id}/complete', [SettlementController::class, 'complete'])
            ->middleware('role:admin,operations,merchant');

        // Multi-Source Automated Reconciliation Engine
        Route::get('/reconciliation/runs', [ReconciliationController::class, 'index'])
            ->middleware('role:admin,operations,auditor,merchant');
        Route::get('/reconciliation/runs/{id}', [ReconciliationController::class, 'show'])
            ->middleware('role:admin,operations,auditor,merchant');
        Route::post('/reconciliation/process', [ReconciliationController::class, 'process'])
            ->middleware('role:admin,operations,merchant');
        Route::post('/reconciliation/generate-sample-file', [ReconciliationController::class, 'generateSampleFile'])
            ->middleware('role:admin,operations,merchant');
        Route::get('/reconciliation/exceptions', [ReconciliationController::class, 'exceptions'])
            ->middleware('role:admin,operations,auditor,merchant');
        Route::post('/reconciliation/exceptions/{id}/resolve', [ReconciliationController::class, 'resolveException'])
            ->middleware('role:admin,operations,merchant');

        // Security, RBAC, Audit Trails & Risk Rules (Stage 10)
        Route::get('/security/audit-logs', [SecurityController::class, 'auditLogs'])
            ->middleware('role:admin,auditor,operations');
        Route::get('/security/audit-logs/verify-chain', [SecurityController::class, 'verifyAuditChain'])
            ->middleware('role:admin,auditor,operations');
        Route::get('/security/risk-metrics', [SecurityController::class, 'riskMetrics'])
            ->middleware('role:admin,operations,auditor');
        Route::post('/security/risk-rules/evaluate', [SecurityController::class, 'evaluateRiskSimulator'])
            ->middleware('role:admin,operations,merchant');
        Route::get('/security/roles-and-users', [SecurityController::class, 'rolesAndUsers'])
            ->middleware('role:admin,auditor,operations');

        // Operational Analytics & Overview Summary (Stage 15)
        Route::get('/analytics/summary', [AnalyticsController::class, 'summary'])
            ->middleware('role:admin,operations,auditor,merchant');
    });
});

