<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\ModerationFlag;
use App\Models\ModerationLog;
use App\Models\PlatformSetting;
use App\Models\Topic;
use App\Models\Upvote;
use App\Models\User;
use App\Models\Work;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ModerationController extends Controller
{
    protected function resolveCurrentUser(Request $request): ?User
    {
        $user = AuthController::resolveUserFromRequest($request);
        if ($user && $user->is_banned) {
            return null;
        }
        return $user;
    }

    public static function recordAuditLog(
        User $actor,
        string $actionType,
        string $targetType,
        ?int $targetId,
        string $targetTitle,
        ?string $targetAuthorName,
        ?string $reason,
        Request $request
    ): ModerationLog {
        return ModerationLog::create([
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_role' => $actor->role,
            'action_type' => $actionType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_title' => Str::limit($targetTitle, 240),
            'target_author_name' => $targetAuthorName,
            'reason' => $reason ?: 'Ação de moderação registrada no livro de auditoria.',
            'ip_address' => $request->ip() ?? '127.0.0.1',
        ]);
    }

    /**
     * POST /api/flags (Aberto a todos os leitores: Visitantes, Membros, Moderadores e Admins)
     */
    public function createFlag(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        $validated = $request->validate([
            'target_type' => 'required|in:WORK,TOPIC,COMMENT',
            'target_id' => 'required|integer',
            'target_title' => 'required|string|max:255',
            'target_excerpt' => 'nullable|string',
            'target_author_name' => 'nullable|string|max:255',
            'reason' => 'required|string|max:40',
            'details' => 'nullable|string',
        ]);

        $targetAuthorId = null;
        $targetAuthorName = $validated['target_author_name'] ?? null;

        if ($validated['target_type'] === 'WORK') {
            $work = Work::with('submittedBy')->find($validated['target_id']);
            if ($work) {
                $targetAuthorId = $work->submitted_by_id;
                $targetAuthorName = $targetAuthorName ?: ($work->submittedBy?->name ?? $work->creator ?? 'Autor da Obra');
            }
        } elseif ($validated['target_type'] === 'TOPIC') {
            $topic = Topic::with('author')->find($validated['target_id']);
            if ($topic) {
                $targetAuthorId = $topic->author_id;
                $targetAuthorName = $targetAuthorName ?: ($topic->author?->name ?? 'Autor do Ensaio');
            }
        } elseif ($validated['target_type'] === 'COMMENT') {
            $comment = Comment::with('author')->find($validated['target_id']);
            if ($comment) {
                $targetAuthorId = $comment->author_id;
                $targetAuthorName = $targetAuthorName ?: ($comment->author?->name ?? 'Autor do Comentário');
            }
        }

        if (empty($targetAuthorName)) {
            $targetAuthorName = 'Autor Sinalizado';
        }

        $excerpt = !empty($validated['target_excerpt'])
            ? $validated['target_excerpt']
            : Str::limit($validated['target_title'], 180);

        $details = !empty($validated['details'])
            ? $validated['details']
            : 'Conteúdo impróprio ou ofensivo sinalizado para análise da moderação.';

        $reporterId = $user ? $user->id : null;
        $reporterName = $user ? $user->name : 'Visitante / Leitor Anônimo';

        $flag = ModerationFlag::create([
            'target_type' => $validated['target_type'],
            'target_id' => $validated['target_id'],
            'target_title' => $validated['target_title'],
            'target_excerpt' => $excerpt,
            'target_author_id' => $targetAuthorId,
            'target_author_name' => $targetAuthorName,
            'reporter_id' => $reporterId,
            'reporter_name' => $reporterName,
            'reason' => $validated['reason'],
            'details' => $details,
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

        $log = self::recordAuditLog(
            $user,
            'RESOLVE_FLAG_' . $validated['action'],
            $flag->target_type,
            $flag->target_id,
            $flag->target_title,
            $flag->target_author_name,
            $validated['moderator_note'] ?? 'Deliberação registrada na fila de moderação.',
            $request
        );

        return response()->json([
            'flag' => $flag,
            'log' => $log,
        ]);
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

        $log = self::recordAuditLog(
            $user,
            'UPDATE_SETTINGS',
            'SETTINGS',
            1,
            'Tag Global de Afiliado Amazon: ' . $settings->amazon_associate_tag,
            $user->name,
            'Atualização das configurações globais de afiliados e diretrizes editoriais.',
            $request
        );

        return response()->json([
            'settings' => $settings,
            'log' => $log,
        ]);
    }

    /**
     * POST /api/moderation/works/{id}/status (RBAC: MODERATOR or ADMIN)
     */
    public function moderateWork(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user || !$user->hasRole('ADMIN', 'MODERATOR')) {
            return response()->json([
                'message' => 'Acesso negado (RBAC): apenas Moderadores e Administradores podem aprovar ou reprovar textos.',
            ], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:APPROVED,REJECTED,HIDDEN',
            'moderation_note' => 'nullable|string',
        ]);

        $work = Work::with('submittedBy')->findOrFail($id);
        $work->update([
            'status' => $validated['status'],
            'moderation_note' => $validated['moderation_note'] ?? null,
        ]);

        ModerationFlag::where('target_type', 'WORK')
            ->where('target_id', $work->id)
            ->where('status', 'PENDING')
            ->update([
                'status' => $validated['status'] === 'APPROVED' ? 'RESOLVED_APPROVED' : 'RESOLVED_HIDDEN',
                'moderator_note' => $validated['moderation_note'] ?? 'Revisado pela Moderação.',
                'resolved_at' => now(),
            ]);

        $log = self::recordAuditLog(
            $user,
            $validated['status'] === 'APPROVED' ? 'APPROVE_WORK' : 'REJECT_WORK',
            'WORK',
            $work->id,
            $work->title,
            $work->submittedBy?->name ?? 'Autor Comunitário',
            $validated['moderation_note'] ?? 'Revisão editorial de obra/resenha.',
            $request
        );

        $work->load(['category', 'submittedBy', 'comments']);

        return response()->json([
            'work' => $work,
            'log' => $log,
        ]);
    }

    /**
     * POST /api/moderation/topics/{id}/status (RBAC: MODERATOR or ADMIN)
     */
    public function moderateTopic(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user || !$user->hasRole('ADMIN', 'MODERATOR')) {
            return response()->json([
                'message' => 'Acesso negado (RBAC): apenas Moderadores e Administradores podem aprovar ou reprovar ensaios.',
            ], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:APPROVED,REJECTED,HIDDEN',
            'moderation_note' => 'nullable|string',
        ]);

        $topic = Topic::with('author')->findOrFail($id);
        $topic->update([
            'status' => $validated['status'],
            'moderation_note' => $validated['moderation_note'] ?? null,
        ]);

        ModerationFlag::where('target_type', 'TOPIC')
            ->where('target_id', $topic->id)
            ->where('status', 'PENDING')
            ->update([
                'status' => $validated['status'] === 'APPROVED' ? 'RESOLVED_APPROVED' : 'RESOLVED_HIDDEN',
                'moderator_note' => $validated['moderation_note'] ?? 'Revisado pela Moderação.',
                'resolved_at' => now(),
            ]);

        $log = self::recordAuditLog(
            $user,
            $validated['status'] === 'APPROVED' ? 'APPROVE_TOPIC' : 'REJECT_TOPIC',
            'TOPIC',
            $topic->id,
            $topic->title,
            $topic->author?->name ?? 'Autor Comunitário',
            $validated['moderation_note'] ?? 'Revisão editorial de ensaio.',
            $request
        );

        $topic->load(['category', 'work', 'author', 'comments']);

        return response()->json([
            'topic' => $topic,
            'log' => $log,
        ]);
    }

    /**
     * POST /api/moderation/delete
     * Allows authors to delete their own content (WORK, TOPIC, COMMENT)
     * and Moderators/Admins to delete any content.
     */
    public function deleteItem(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) {
            return response()->json([
                'message' => 'Faça login para realizar esta ação.',
            ], 401);
        }

        $validated = $request->validate([
            'target_type' => 'required|in:WORK,TOPIC,COMMENT',
            'target_id' => 'required|integer',
            'reason' => 'nullable|string|max:500',
        ]);

        $targetType = $validated['target_type'];
        $targetId = (int) $validated['target_id'];

        $targetTitle = '';
        $targetAuthorName = 'Membro da Comunidade';
        $isAuthor = false;

        if ($targetType === 'WORK') {
            $work = Work::with('submittedBy')->findOrFail($targetId);
            $isAuthor = ($work->submitted_by_id === $user->id);
            if (!$user->hasRole('ADMIN', 'MODERATOR') && !$isAuthor) {
                return response()->json([
                    'message' => 'Você não tem permissão para excluir esta publicação.',
                ], 403);
            }
            $targetTitle = $work->title;
            $targetAuthorName = $work->submittedBy?->name ?? $work->creator ?? 'Autor';
            Comment::where('work_id', $work->id)->forceDelete();
            $work->forceDelete();
        } elseif ($targetType === 'TOPIC') {
            $topic = Topic::with('author')->findOrFail($targetId);
            $isAuthor = ($topic->author_id === $user->id);
            if (!$user->hasRole('ADMIN', 'MODERATOR') && !$isAuthor) {
                return response()->json([
                    'message' => 'Você não tem permissão para excluir este ensaio.',
                ], 403);
            }
            $targetTitle = $topic->title;
            $targetAuthorName = $topic->author?->name ?? 'Autor';
            Comment::where('topic_id', $topic->id)->forceDelete();
            $topic->forceDelete();
        } elseif ($targetType === 'COMMENT') {
            $comment = Comment::with('author')->findOrFail($targetId);
            $isAuthor = ($comment->author_id === $user->id);
            if (!$user->hasRole('ADMIN', 'MODERATOR') && !$isAuthor) {
                return response()->json([
                    'message' => 'Você não tem permissão para excluir este comentário.',
                ], 403);
            }
            $targetTitle = 'Comentário #' . $comment->id . ': ' . Str::limit($comment->body, 90);
            $targetAuthorName = $comment->author?->name ?? 'Autor';

            if ($comment->work_id) {
                Work::where('id', $comment->work_id)->where('reviews_count', '>', 0)->decrement('reviews_count');
            }
            if ($comment->topic_id) {
                Topic::where('id', $comment->topic_id)->where('comments_count', '>', 0)->decrement('comments_count');
            }

            Comment::where('parent_id', $comment->id)->forceDelete();
            $comment->forceDelete();
        }

        $reason = !empty($validated['reason'])
            ? $validated['reason']
            : ($isAuthor ? 'Excluído pelo próprio autor.' : 'Excluído pela Moderação.');

        ModerationFlag::where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->delete();

        Upvote::where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->delete();

        $log = self::recordAuditLog(
            $user,
            'DELETE_' . $targetType,
            $targetType,
            $targetId,
            $targetTitle,
            $targetAuthorName,
            $reason,
            $request
        );

        return response()->json([
            'deleted' => true,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'log' => $log,
            'message' => 'Conteúdo excluído com sucesso.',
        ]);
    }
}
