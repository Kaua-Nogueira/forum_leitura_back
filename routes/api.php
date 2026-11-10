<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ModerationController;
use App\Http\Controllers\Api\PlatformController;
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
Route::post('/auth/logout', [AuthController::class, 'logout']);

// Gestão de Usuários e Papéis RBAC (ADMIN / MODERATOR)
Route::get('/users', [AuthController::class, 'listUsers']);
Route::patch('/users/{id}/role', [AuthController::class, 'updateUserRole']);

// Dados Iniciais do Acervo & Fórum
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
Route::post('/moderation/works/{id}/status', [ModerationController::class, 'moderateWork']);
Route::post('/moderation/topics/{id}/status', [ModerationController::class, 'moderateTopic']);
Route::put('/moderation/settings', [ModerationController::class, 'updateSettings']);
