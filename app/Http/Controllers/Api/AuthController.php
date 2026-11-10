<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Resolve authenticated user from Bearer token.
     */
    public static function resolveUserFromRequest(Request $request): ?User
    {
        $bearer = $request->bearerToken();
        if ($bearer) {
            return User::where('api_token', $bearer)->first();
        }

        return null;
    }

    /**
     * POST /api/auth/login
     * Real email & password authentication with RBAC permissions payload.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Credenciais inválidas. Verifique seu e-mail e senha.',
            ], 401);
        }

        if ($user->is_banned) {
            return response()->json([
                'message' => 'Esta conta encontra-se suspensa pelo Conselho de Moderação.',
            ], 403);
        }

        $token = Str::random(64);
        $user->update(['api_token' => $token]);

        return response()->json([
            'token' => $token,
            'user' => $user,
            'permissions' => $user->permissions(),
        ]);
    }

    /**
     * POST /api/auth/register
     * Registers a new Member account and issues a Bearer token.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'title_badge' => 'nullable|string|max:100',
            'bio' => 'nullable|string|max:500',
        ]);

        $username = Str::slug($validated['name'], '.') . '.' . random_int(10, 99);
        $token = Str::random(64);

        $user = User::create([
            'name' => $validated['name'],
            'username' => $username,
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'api_token' => $token,
            'role' => 'MEMBER',
            'title_badge' => $validated['title_badge'] ?? 'Membro Confrade • Leitor Clássico',
            'bio' => $validated['bio'] ?? 'Membro da comunidade Veritas & Litterae.',
            'is_banned' => false,
            'reputation_score' => 50,
        ]);

        return response()->json([
            'token' => $token,
            'user' => $user,
            'permissions' => $user->permissions(),
        ], 201);
    }

    /**
     * GET /api/auth/me
     * Returns current authenticated user & RBAC permissions from Bearer token.
     */
    public function me(Request $request): JsonResponse
    {
        $user = self::resolveUserFromRequest($request);

        if (!$user) {
            return response()->json([
                'message' => 'Sessão não autenticada ou expirada.',
            ], 401);
        }

        if ($user->is_banned) {
            return response()->json([
                'message' => 'Conta suspensa pela moderação.',
            ], 403);
        }

        return response()->json([
            'user' => $user,
            'permissions' => $user->permissions(),
        ]);
    }

    /**
     * POST /api/auth/logout
     * Invalidates active Bearer token.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = self::resolveUserFromRequest($request);
        if ($user) {
            $user->update(['api_token' => null]);
        }

        return response()->json([
            'message' => 'Sessão encerrada com sucesso.',
        ]);
    }

    /**
     * GET /api/users (RBAC: ADMIN or MODERATOR)
     * Lists registered users for RBAC management.
     */
    public function listUsers(Request $request): JsonResponse
    {
        $actor = self::resolveUserFromRequest($request);
        if (!$actor || !$actor->hasRole('ADMIN', 'MODERATOR')) {
            return response()->json([
                'message' => 'Acesso negado: requer permissão de Moderador ou Administrador.',
            ], 403);
        }

        $users = User::orderBy('id')->get();

        return response()->json([
            'users' => $users,
        ]);
    }

    /**
     * PATCH /api/users/{id}/role (RBAC: ADMIN only)
     * Updates a user's RBAC role (MEMBER, MODERATOR, ADMIN) or ban status.
     */
    public function updateUserRole(Request $request, int $id): JsonResponse
    {
        $actor = self::resolveUserFromRequest($request);
        if (!$actor || !$actor->hasRole('ADMIN')) {
            return response()->json([
                'message' => 'Acesso restrito: apenas Administradores podem alterar papéis RBAC.',
            ], 403);
        }

        $validated = $request->validate([
            'role' => 'nullable|in:MEMBER,MODERATOR,ADMIN',
            'is_banned' => 'nullable|boolean',
            'title_badge' => 'nullable|string|max:120',
        ]);

        $target = User::findOrFail($id);

        if (isset($validated['role'])) {
            $target->role = $validated['role'];
        }
        if (isset($validated['is_banned'])) {
            $target->is_banned = $validated['is_banned'];
            if ($target->is_banned) {
                $target->api_token = null;
            }
        }
        if (isset($validated['title_badge'])) {
            $target->title_badge = $validated['title_badge'];
        }

        $target->save();

        $actionType = 'UPDATE_USER_ROLE';
        $reasonText = 'Alteração de papel RBAC para ' . $target->role;
        if (isset($validated['is_banned'])) {
            $actionType = $target->is_banned ? 'BAN_USER' : 'UNBAN_USER';
            $reasonText = $target->is_banned
                ? 'Conta suspensa pelo Administrador.'
                : 'Suspensão revogada pelo Administrador.';
        }

        $log = ModerationController::recordAuditLog(
            $actor,
            $actionType,
            'USER',
            $target->id,
            $target->name . ' (' . $target->email . ')',
            $target->name,
            $reasonText,
            $request
        );

        return response()->json([
            'user' => $target,
            'log' => $log,
            'message' => 'Permissões RBAC atualizadas com sucesso.',
        ]);
    }

    /**
     * PUT /api/auth/profile
     * Updates the current user's public literary profile (name, title_badge, bio).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = self::resolveUserFromRequest($request);
        if (!$user) {
            $userIdHeader = $request->header('X-User-Id');
            if ($userIdHeader && is_numeric($userIdHeader)) {
                $user = User::find((int) $userIdHeader);
            }
        }

        if (!$user) {
            return response()->json([
                'message' => 'Faça login para atualizar seu perfil.',
            ], 401);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'title_badge' => 'nullable|string|max:120',
            'bio' => 'nullable|string|max:600',
        ]);

        $user->update([
            'name' => $validated['name'],
            'title_badge' => $validated['title_badge'] ?? $user->title_badge,
            'bio' => $validated['bio'] ?? $user->bio,
        ]);

        return response()->json([
            'user' => $user,
            'message' => 'Perfil atualizado com sucesso.',
        ]);
    }
}
