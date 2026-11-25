<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DirectMessage;
use App\Models\User;
use App\Models\UserFollow;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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
     * Retorna a rede de seguidores, diretório público de usuários, mensagens diretas e notificações do usuário autenticado.
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
        $notifications = collect([]);
        $unreadNotificationsCount = 0;

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

            if (Schema::hasTable('user_notifications')) {
                $notifications = UserNotification::where('user_id', $user->id)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(150)
                    ->get();

                $unreadNotificationsCount = UserNotification::where('user_id', $user->id)
                    ->whereNull('read_at')
                    ->count();
            }
        }

        return response()->json([
            'follows' => $follows,
            'users' => $directoryUsers,
            'messages' => $messages,
            'unread_count' => $unreadCount,
            'notifications' => $notifications,
            'unread_notifications_count' => $unreadNotificationsCount,
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

        $isMutual = UserFollow::where('follower_id', $target->id)
            ->where('following_id', $user->id)
            ->exists();

        UserNotification::notify(
            $target->id,
            $user,
            'FOLLOW',
            $isMutual
                ? $user->name . ' seguiu você de volta!'
                : $user->name . ' começou a seguir você',
            $isMutual
                ? 'Vocês agora se seguem mutuamente — o chat privado está liberado!'
                : 'Siga de volta para liberar o chat privado e acompanhar suas publicações.',
            'USER',
            $user->id,
            '/perfil/' . $user->id
        );

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
     * Envia uma mensagem privada direta para outro usuário, respeitando a política de privacidade (MUTUAL / FOLLOWERS / EVERYONE).
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
            'force_staff_override' => 'nullable|boolean',
        ]);

        $recipientId = (int) $validated['recipient_id'];
        if ($recipientId === $user->id) {
            return response()->json([
                'message' => 'Selecione outro membro para conversar.',
            ], 422);
        }

        $recipient = User::findOrFail($recipientId);

        $policy = strtoupper((string) ($recipient->dm_policy ?: 'MUTUAL'));
        $senderFollowsRecipient = UserFollow::where('follower_id', $user->id)
            ->where('following_id', $recipient->id)
            ->exists();
        $recipientFollowsSender = UserFollow::where('follower_id', $recipient->id)
            ->where('following_id', $user->id)
            ->exists();

        $isStaffOverride = ! empty($validated['force_staff_override']) && $user->hasRole('ADMIN', 'MODERATOR');

        $allowed = $isStaffOverride
            || $policy === 'EVERYONE'
            || ($policy === 'FOLLOWERS' && $senderFollowsRecipient)
            || ($policy === 'MUTUAL' && $senderFollowsRecipient && $recipientFollowsSender);

        if (! $allowed) {
            if ($policy === 'FOLLOWERS') {
                $reason = 'Para enviar mensagem privada a ' . $recipient->name . ', siga esta pessoa primeiro.';
            } elseif (! $senderFollowsRecipient && $recipientFollowsSender) {
                $reason = $recipient->name . ' já segue você! Siga de volta para liberar o chat privado.';
            } elseif ($senderFollowsRecipient && ! $recipientFollowsSender) {
                $reason = 'Chat privado liberado apenas quando ambos se seguem. Aguarde ' . $recipient->name . ' seguir você de volta.';
            } else {
                $reason = 'Chat privado disponível apenas entre seguidores mútuos. Siga ' . $recipient->name . ' e aguarde ser seguido de volta.';
            }

            return response()->json([
                'message' => $reason,
            ], 403);
        }

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

        UserNotification::notify(
            $recipient->id,
            $user,
            'DIRECT_MESSAGE',
            'Nova mensagem privada de ' . $user->name,
            Str::limit($msg->body, 140),
            'USER',
            $user->id,
            '/mensagens/' . $user->id
        );

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

    /**
     * GET /api/notifications
     * Lista as notificações pessoais do usuário autenticado.
     */
    public function listNotifications(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (! $user) {
            return response()->json([
                'message' => 'Faça login para visualizar suas notificações.',
            ], 401);
        }

        if (! Schema::hasTable('user_notifications')) {
            return response()->json([
                'notifications' => [],
                'unread_count' => 0,
            ]);
        }

        $notifications = UserNotification::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(150)
            ->get();

        $unreadCount = UserNotification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * POST /api/notifications/{id}/read
     * Marca uma notificação específica do usuário como lida.
     */
    public function markNotificationRead(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (! $user) {
            return response()->json(['message' => 'Autenticação necessária.'], 401);
        }

        $notification = UserNotification::where('user_id', $user->id)->findOrFail($id);
        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json([
            'notification' => $notification,
        ]);
    }

    /**
     * POST /api/notifications/read-all
     * Marca todas as notificações do usuário como lidas.
     */
    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (! $user) {
            return response()->json(['message' => 'Autenticação necessária.'], 401);
        }

        $updated = 0;
        if (Schema::hasTable('user_notifications')) {
            $updated = UserNotification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        return response()->json([
            'updated_count' => $updated,
        ]);
    }

    /**
     * DELETE /api/notifications/{id}
     * Exclui uma notificação do usuário autenticado.
     */
    public function deleteNotification(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (! $user) {
            return response()->json(['message' => 'Autenticação necessária.'], 401);
        }

        $notification = UserNotification::where('user_id', $user->id)->findOrFail($id);
        $notification->delete();

        return response()->json([
            'deleted' => true,
            'notification_id' => $id,
        ]);
    }
}
