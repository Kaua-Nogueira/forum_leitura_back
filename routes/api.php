<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ModerationController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\SeriesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| VERITAS & LITTERAE — API Routes (Decoupled Laravel REST API + RBAC Auth)
|--------------------------------------------------------------------------
*/

// Autenticação & RBAC (Bearer Token)
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::get('/auth/me', [AuthController::class, 'me']);
Route::match(['PUT', 'POST', 'PATCH'], '/auth/profile', [AuthController::class, 'updateProfile']);
Route::post('/auth/logout', [AuthController::class, 'logout']);

// Gestão de Usuários e Papéis RBAC (ADMIN / MODERATOR)
Route::get('/users', [AuthController::class, 'listUsers']);
Route::match(['PATCH', 'POST', 'PUT'], '/users/{id}/role', [AuthController::class, 'updateUserRole']);

// Dados Iniciais do Acervo & Fórum
Route::get('/bootstrap', [PlatformController::class, 'bootstrap']);

// Catálogo Curado de Obras & Afiliados
Route::post('/works', [PlatformController::class, 'createWork']);
Route::match(['PUT', 'PATCH'], '/works/{id}', [PlatformController::class, 'updateWork']);
Route::post('/works/{id}/affiliate-click', [PlatformController::class, 'trackAffiliateClick']);

// Fórum de Discussão Comunitária & Resenhas Aninhadas
Route::post('/topics', [PlatformController::class, 'createTopic']);
Route::match(['PUT', 'PATCH'], '/topics/{id}', [PlatformController::class, 'updateTopic']);
Route::post('/comments', [PlatformController::class, 'createComment']);
Route::post('/upvotes', [PlatformController::class, 'toggleUpvote']);

// Séries & Cadernos (Publicações Recorrentes — RBAC: COLUMNIST, MODERATOR, ADMIN)
Route::get('/series', [SeriesController::class, 'index']);
Route::post('/series', [SeriesController::class, 'store']);
Route::match(['PUT', 'PATCH'], '/series/{id}', [SeriesController::class, 'update']);
Route::delete('/series/{id}', [SeriesController::class, 'destroy']);
Route::post('/series/{id}/entries', [SeriesController::class, 'storeEntry']);
Route::match(['PUT', 'PATCH'], '/series/{seriesId}/entries/{entryId}', [SeriesController::class, 'updateEntry']);
Route::delete('/series/{seriesId}/entries/{entryId}', [SeriesController::class, 'destroyEntry']);

// Governança, Fila de Moderação, Logs do Sistema, Exclusão Auditada & Tag Global de Afiliado Amazon
Route::get('/moderation/logs', [ModerationController::class, 'listLogs']);
Route::post('/flags', [ModerationController::class, 'createFlag']);
Route::post('/moderation/flags/{id}/resolve', [ModerationController::class, 'resolveFlag']);
Route::post('/moderation/works/{id}/status', [ModerationController::class, 'moderateWork']);
Route::match(['PATCH', 'PUT', 'POST'], '/moderation/works/{id}/affiliate', [ModerationController::class, 'updateWorkAffiliate']);
Route::post('/moderation/topics/{id}/status', [ModerationController::class, 'moderateTopic']);
Route::post('/moderation/delete', [ModerationController::class, 'deleteItem']);
Route::put('/moderation/settings', [ModerationController::class, 'updateSettings']);
