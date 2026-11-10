<?php

use App\Http\Controllers\Api\ModerationController;
use App\Http\Controllers\Api\PlatformController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| VERITAS & LITTERAE — API Routes (Decoupled Laravel REST API)
|--------------------------------------------------------------------------
*/

Route::get('/bootstrap', [PlatformController::class, 'bootstrap']);

// Catálogo Curado de Obras & Afiliados
Route::post('/works', [PlatformController::class, 'createWork']);
Route::post('/works/{id}/affiliate-click', [PlatformController::class, 'trackAffiliateClick']);

// Fórum de Discussão Comunitária & Resenhas Aninhadas
Route::post('/topics', [PlatformController::class, 'createTopic']);
Route::post('/comments', [PlatformController::class, 'createComment']);
Route::post('/upvotes', [PlatformController::class, 'toggleUpvote']);

// Governança, Fila de Moderação & Tag Global de Afiliado Amazon
Route::post('/flags', [ModerationController::class, 'createFlag']);
Route::post('/moderation/flags/{id}/resolve', [ModerationController::class, 'resolveFlag']);
Route::put('/moderation/settings', [ModerationController::class, 'updateSettings']);
