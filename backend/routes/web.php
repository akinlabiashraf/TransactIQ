<?php

use App\Http\Controllers\DocsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/docs');
});

// Interactive OpenAPI Documentation (Stage 16)
Route::get('/docs', [DocsController::class, 'index']);
Route::get('/docs/openapi.json', [DocsController::class, 'openapi']);
