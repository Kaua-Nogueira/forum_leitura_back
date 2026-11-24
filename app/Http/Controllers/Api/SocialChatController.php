<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DirectMessage;
use App\Models\User;
use App\Models\UserFollow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SocialChatController extends Controller
{
    protected function resolveCurrentUser(Request $request): ?User
    {
        $user = AuthController::resolveUserFromRequest($request);
        if ($user && $user->is_banned) {
            return null;
        }
        return $user;
    }

    /**
     * GET /api/social/overview
     * Retorna a rede de seguidores, diretório público de usuários e mensagens diretas do usuário autenticado.
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        $follows = UserFollow::select(['follower_id', 'following_id', 'created_at'])->get();
        $directoryUsers = User::where('is_banned', false)
            ->orderBy('name')
            ->get();

        $messages = collect([]);
        $unreadCount = 0;

        if ($user) {
            $messages = DirectMessage::with(['sender', 'recipient'])
                ->where(function ($q) use ($user) {
                    $q->where('sender_id', $user->id)
                      ->orWhere('recipient_id', $user->id);
                })
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(1500)
                ->get();

            $unreadCount = DirectMessage::where('recipient_id', $user->id)
                ->whereNull('read_at')
                ->count();
        }

        return response()->json([
            'follows' => $follows,
            'users' => $directoryUsers,
            'messages' => $messages,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * POST /api/social/follow/{targetUserId}
     * Segue ou deixa de seguir um usuário (Toggle Follow).
     */
    public function toggleFollow(Request $request, int $targetUserId): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (! $user) {
            return response()->json([
                'message' => 'Faça login na sua conta para seguir membros da comunidade.',
            ], 401);
        }

        if ($user->id === $targetUserId) {
            return response()->json([
                'message' => 'Você não pode seguir o próprio perfil.',
            ], 422);
        }

        $target = User::findOrFail($targetUserId);

        $existing = UserFollow::where('follower_id', $user->id)
            ->where('following_id', $target->id)
            ->first();

        if ($existing) {
            $existing->delete();

            $log = ModerationController::recordAuditLog(
                $user,
                'UNFOLLOW_USER',
                'USER',
                $target->id,
                $target->name . ' (@' . ($target->username ?? 'membro') . ')',
                $target->name,
                $user->name . ' deixou de seguir ' . $target->name . '.',
                $request
            );

            return response()->json([
                'following' => false,
                'follower_id' => $user->id,
                'following_id' => $target->id,
                'log' => $log,
                'message' => 'Você deixou de seguir ' . $target->name . '.',
            ]);
        }

        UserFollow::create([
            'follower_id' => $user->id,
            'following_id' => $target->id,
        ]);

        $log = ModerationController::recordAuditLog(
            $user,
            'FOLLOW_USER',
            'USER',
            $target->id,
            $target->name . ' (@' . ($target->username ?? 'membro') . ')',
            $target->name,
            $user->name . ' começou a seguir ' . $target->name . '.',
            $request
        );

        return response()->json([
            'following' => true,
            'follower_id' => $user->id,
            'following_id' => $target->id,
            'log' => $log,
            'message' => 'Agora você segue ' . $target->name . '!',
        ]);
    }

    /**
     * POST /api/chat/messages
     * Envia uma mensagem privada direta para outro usuário.
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (! $user) {
            return response()->json([
                'message' => 'Faça login para enviar mensagens privadas.',
            ], 401);
        }

        $validated = $request->validate([
            'recipient_id' => 'required|integer|exists:users,id',
            'body' => 'required|string|max:4000',
            'shared_type' => 'nullable|in:WORK,TOPIC,SERIES,SERIES_ENTRY',
            'shared_slug' => 'nullable|string|max:255',
            'shared_title' => 'nullable|string|max:255',
        ]);

        $recipientId = (int) $validated['recipient_id'];
        if ($recipientId === $user->id) {
            return response()->json([
                'message' => 'Selecione outro membro para conversar.',
            ], 422);
        }

        $recipient = User::findOrFail($recipientId);

        $msg = DirectMessage::create([
            'sender_id' => $user->id,
            'recipient_id' => $recipient->id,
            'body' => trim($validated['body']),
            'shared_type' => $validated['shared_type'] ?? null,
            'shared_slug' => $validated['shared_slug'] ?? null,
            'shared_title' => $validated['shared_title'] ?? null,
            'read_at' => null,
        ]);

        $msg->load(['sender', 'recipient']);

        $log = ModerationController::recordAuditLog(
            $user,
            'SEND_DIRECT_MESSAGE',
            'USER',
            $recipient->id,
            'Mensagem privada para ' . $recipient->name,
            $recipient->name,
            'Mensagem enviada no chat privado (' . Str::limit($msg->body, 90) . ').',
            $request
        );

        return response()->json([
            'message_item' => $msg,
            'log' => $log,
        ], 201);
    }

    /**
     * POST /api/chat/messages/{partnerId}/read
     * Marca todas as mensagens recebidas de $partnerId como lidas.
     */
    public function markConversationRead(Request $request, int $partnerId): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (! $user) {
            return response()->json(['read_count' => 0]);
        }

        $updated = DirectMessage::where('sender_id', $partnerId)
            ->where('recipient_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'read_count' => $updated,
            'partner_id' => $partnerId,
        ]);
    }

    /**
     * DELETE /api/chat/messages/{messageId}
     * Exclui uma mensagem enviada pelo próprio usuário (ou pela moderação).
     */
    public function deleteMessage(Request $request, int $messageId): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (! $user) {
            return response()->json([
                'message' => 'Faça login para excluir mensagens.',
            ], 401);
        }

        $msg = DirectMessage::with(['sender', 'recipient'])->findOrFail($messageId);
        if ($msg->sender_id !== $user->id && ! $user->hasRole('ADMIN', 'MODERATOR')) {
            return response()->json([
                'message' => 'Você só pode excluir mensagens enviadas por você.',
            ], 403);
        }

        $recipientName = $msg->recipient?->name ?? 'Membro';
        $msg->delete();

        $log = ModerationController::recordAuditLog(
            $user,
            'DELETE_DIRECT_MESSAGE',
            'USER',
            $msg->recipient_id,
            'Exclusão de mensagem privada com ' . $recipientName,
            $recipientName,
            'Mensagem privada removida por ' . $user->name . '.',
            $request
        );

        return response()->json([
            'deleted' => true,
            'message_id' => $messageId,
            'log' => $log,
        ]);
    }
}
