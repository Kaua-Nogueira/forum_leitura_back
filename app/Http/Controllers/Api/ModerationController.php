<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\ModerationFlag;
use App\Models\PlatformSetting;
use App\Models\Topic;
use App\Models\User;
use App\Models\Work;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModerationController extends Controller
{
    protected function resolveCurrentUser(Request $request): ?User
    {
        $bearerUser = AuthController::resolveUserFromRequest($request);
        if ($bearerUser) {
            return $bearerUser->is_banned ? null : $bearerUser;
        }

        $roleHeader = $request->header('X-Demo-Role');
        if ($roleHeader && strtoupper($roleHeader) !== 'VISITOR') {
            return User::where('role', strtoupper($roleHeader))->first();
        }

        return null;
    }

    /**
     * POST /api/flags (RBAC: MEMBER, MODERATOR, ADMIN)
     */
    public function createFlag(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) {
            return response()->json(['message' => 'Faça login para sinalizar conteúdos à moderação.'], 401);
        }

        $validated = $request->validate([
            'target_type' => 'required|in:WORK,TOPIC,COMMENT',
            'target_id' => 'required|integer',
            'target_title' => 'required|string|max:255',
            'target_excerpt' => 'required|string',
            'reason' => 'required|string|max:40',
            'details' => 'required|string',
        ]);

        $flag = ModerationFlag::create([
            'target_type' => $validated['target_type'],
            'target_id' => $validated['target_id'],
            'target_title' => $validated['target_title'],
            'target_excerpt' => $validated['target_excerpt'],
            'target_author_id' => null,
            'target_author_name' => 'Membro Sinalizado',
            'reporter_id' => $user->id,
            'reporter_name' => $user->name,
            'reason' => $validated['reason'],
            'details' => $validated['details'],
            'status' => 'PENDING',
        ]);

        return response()->json(['flag' => $flag], 201);
    }

    /**
     * POST /api/moderation/flags/{id}/resolve (RBAC: MODERATOR or ADMIN)
     */
    public function resolveFlag(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user || !$user->hasRole('ADMIN', 'MODERATOR')) {
            return response()->json([
                'message' => 'Acesso negado (RBAC): apenas Moderadores e Administradores podem deliberar na fila de moderação.',
            ], 403);
        }

        $validated = $request->validate([
            'action' => 'required|in:APPROVE,HIDE,BAN_USER',
            'moderator_note' => 'nullable|string',
        ]);

        if ($validated['action'] === 'BAN_USER' && !$user->hasRole('ADMIN')) {
            return response()->json([
                'message' => 'Acesso negado (RBAC): apenas o papel ADMIN tem permissão para banir contas permanentemente.',
            ], 403);
        }

        $flag = ModerationFlag::findOrFail($id);

        $statusMap = [
            'APPROVE' => 'RESOLVED_APPROVED',
            'HIDE' => 'RESOLVED_HIDDEN',
            'BAN_USER' => 'RESOLVED_BANNED',
        ];

        $flag->update([
            'status' => $statusMap[$validated['action']],
            'moderator_note' => $validated['moderator_note'] ?? 'Deliberação registrada pela Moderação.',
            'resolved_at' => now(),
        ]);

        $targetStatus = $validated['action'] === 'APPROVE' ? 'APPROVED' : 'HIDDEN';

        if ($flag->target_type === 'WORK') {
            Work::where('id', $flag->target_id)->update([
                'status' => $targetStatus,
                'moderation_note' => $validated['moderator_note'] ?? null,
            ]);
        } elseif ($flag->target_type === 'TOPIC') {
            Topic::where('id', $flag->target_id)->update([
                'status' => $targetStatus,
                'moderation_note' => $validated['moderator_note'] ?? null,
            ]);
        } elseif ($flag->target_type === 'COMMENT') {
            Comment::where('id', $flag->target_id)->update([
                'status' => $targetStatus,
                'moderation_note' => $validated['moderator_note'] ?? null,
            ]);
        }

        if ($validated['action'] === 'BAN_USER' && $flag->target_author_id) {
            User::where('id', $flag->target_author_id)->update([
                'is_banned' => true,
                'api_token' => null,
            ]);
        }

        return response()->json(['flag' => $flag]);
    }

    /**
     * PUT /api/moderation/settings (RBAC: ADMIN only)
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user || !$user->hasRole('ADMIN')) {
            return response()->json([
                'message' => 'Acesso negado (RBAC): apenas Administradores podem alterar a tag global de afiliados.',
            ], 403);
        }

        $validated = $request->validate([
            'amazon_associate_tag' => 'required|string|max:100',
            'default_affiliate_disclaimer' => 'nullable|string',
            'require_moderation_for_new_works' => 'nullable|boolean',
        ]);

        $settings = PlatformSetting::first() ?? new PlatformSetting();
        $settings->fill($validated);
        $settings->save();

        return response()->json(['settings' => $settings]);
    }
}
