<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Comment;
use App\Models\DirectMessage;
use App\Models\ModerationFlag;
use App\Models\ModerationLog;
use App\Models\PlatformSetting;
use App\Models\ReadingSeries;
use App\Models\SeriesEntry;
use App\Models\Topic;
use App\Models\Upvote;
use App\Models\User;
use App\Models\UserFollow;
use App\Models\UserNotification;
use App\Models\Work;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PlatformController extends Controller
{
    /**
     * Resolve authenticated user via Bearer token, X-User-Id, X-User-Email, or request payload.
     * Unauthenticated visitors (VISITOR) always resolve to null.
     */
    protected function resolveCurrentUser(Request $request): ?User
    {
        $user = AuthController::resolveUserFromRequest($request);
        if ($user && $user->is_banned) {
            return null;
        }
        return $user;
    }

    /**
     * GET /api/bootstrap
     * Returns full decoupled initial dataset for the React SPA.
     * Unapproved works/topics (PENDING, REJECTED, HIDDEN) are ONLY returned to MODERATOR and ADMIN users.
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $currentUser = $this->resolveCurrentUser($request);
        $isModerator = $currentUser && $currentUser->hasRole('ADMIN', 'MODERATOR');

        $categories = Category::withCount([
            'works' => fn ($q) => $q->where('status', 'APPROVED'),
            'topics' => fn ($q) => $q->where('status', 'APPROVED'),
        ])->orderBy('id')->get();

        $worksQuery = Work::with([
            'category',
            'submittedBy',
            'comments' => fn ($q) => $isModerator ? $q->with(['author', 'replies.author']) : $q->where('status', 'APPROVED')->with(['author', 'replies' => fn ($rq) => $rq->where('status', 'APPROVED')->with('author')]),
        ])->latest();

        $topicsQuery = Topic::with([
            'category',
            'work',
            'author',
            'comments' => fn ($q) => $isModerator ? $q->with(['author', 'replies.author']) : $q->where('status', 'APPROVED')->with(['author', 'replies' => fn ($rq) => $rq->where('status', 'APPROVED')->with('author')]),
        ])->orderByDesc('is_pinned')->latest();

        if (!$isModerator) {
            if ($currentUser) {
                $worksQuery->where(function ($q) use ($currentUser) {
                    $q->where('status', 'APPROVED')
                      ->orWhere('submitted_by_id', $currentUser->id);
                });
                $topicsQuery->where(function ($q) use ($currentUser) {
                    $q->where('status', 'APPROVED')
                      ->orWhere('author_id', $currentUser->id);
                });
            } else {
                $worksQuery->where('status', 'APPROVED');
                $topicsQuery->where('status', 'APPROVED');
            }
        }

        $works = $worksQuery->get();
        $topics = $topicsQuery->get();

        if ($isModerator) {
            // Garante que qualquer Obra ou Ensaio com status PENDING possua item correspondente na fila de moderação
            foreach ($works as $w) {
                if ($w->status === 'PENDING') {
                    ModerationFlag::firstOrCreate(
                        [
                            'target_type' => 'WORK',
                            'target_id' => $w->id,
                            'status' => 'PENDING',
                        ],
                        [
                            'target_title' => 'Revisão de Nova Obra/Resenha: ' . $w->title,
                            'target_excerpt' => Str::limit($w->synopsis, 220),
                            'target_author_id' => $w->submitted_by_id,
                            'target_author_name' => $w->submittedBy?->name ?? $w->creator ?? 'Membro',
                            'reporter_id' => $w->submitted_by_id,
                            'reporter_name' => 'Fila Obrigatória de Revisão Editorial',
                            'reason' => 'BROKEN_AFFILIATE_LINK',
                            'details' => 'Todo texto publicado aguarda revisão obrigatória da moderação antes de ir ao ar.',
                        ]
                    );
                }
            }

            foreach ($topics as $t) {
                if ($t->status === 'PENDING') {
                    ModerationFlag::firstOrCreate(
                        [
                            'target_type' => 'TOPIC',
                            'target_id' => $t->id,
                            'status' => 'PENDING',
                        ],
                        [
                            'target_title' => 'Revisão de Novo Ensaio: ' . $t->title,
                            'target_excerpt' => Str::limit($t->body, 220),
                            'target_author_id' => $t->author_id,
                            'target_author_name' => $t->author?->name ?? 'Membro',
                            'reporter_id' => $t->author_id,
                            'reporter_name' => 'Fila Obrigatória de Revisão Editorial',
                            'reason' => 'GUIDELINE_VIOLATION',
                            'details' => 'Todo ensaio publicado aguarda revisão obrigatória da moderação antes de ir ao ar.',
                        ]
                    );
                }
            }
        }

        $flags = $isModerator ? ModerationFlag::latest()->get() : collect([]);

        $settings = PlatformSetting::first() ?? PlatformSetting::create([
            'amazon_associate_tag' => 'veritaslitterae-20',
            'default_affiliate_disclaimer' => 'Comprando por este link, você apoia a curadoria e manutenção editorial deste projeto sem custo adicional.',
            'require_moderation_for_new_works' => true,
            'editorial_edition_label' => 'Edição de Outono • Vol. IV — MMXXVI',
        ]);

        $users = User::orderBy('id')->get();

        $moderationLogs = $isModerator
            ? ModerationLog::orderByDesc('created_at')->orderByDesc('id')->limit(750)->get()
            : collect([]);
        $upvotes = Upvote::select(['user_id', 'target_type', 'target_id'])->get();

        $series = ReadingSeries::with([
            'category',
            'work',
            'author',
            'entries.author',
            'entries.comments.author',
            'entries.comments.replies.author',
        ])->latest()->get();

        $userFollows = Schema::hasTable('user_follows')
            ? UserFollow::select(['follower_id', 'following_id', 'created_at'])->get()
            : collect([]);

        $directMessages = ($currentUser && Schema::hasTable('direct_messages'))
            ? DirectMessage::with(['sender', 'recipient'])
                ->where(function ($q) use ($currentUser) {
                    $q->where('sender_id', $currentUser->id)
                      ->orWhere('recipient_id', $currentUser->id);
                })
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(1500)
                ->get()
            : collect([]);

        $notifications = ($currentUser && Schema::hasTable('user_notifications'))
            ? UserNotification::where('user_id', $currentUser->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(120)
                ->get()
            : collect([]);

        return response()->json([
            'categories' => $categories,
            'works' => $works,
            'topics' => $topics,
            'series' => $series,
            'moderation_flags' => $flags,
            'moderation_logs' => $moderationLogs,
            'upvotes' => $upvotes,
            'settings' => $settings,
            'users' => $users,
            'user_follows' => $userFollows,
            'direct_messages' => $directMessages,
            'notifications' => $notifications,
        ]);
    }

    /**
     * POST /api/works
     * Submit a new Curated Work with Affiliate link & Notable Quotes (RBAC: MEMBER, MODERATOR, ADMIN).
     */
    public function createWork(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user || !$user->hasRole('MEMBER', 'COLUMNIST', 'MODERATOR', 'ADMIN')) {
            return response()->json([
                'message' => 'Autenticação necessária: faça login como Membro, Moderador ou Admin para publicar obras.',
            ], 401);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'creator' => 'nullable|string|max:255',
            'media_type' => 'nullable|string|max:32',
            'release_year' => 'nullable|integer',
            'category_id' => 'required|exists:categories,id',
            'cover_url' => 'nullable|string|max:1024',
            'synopsis' => 'required|string',
            'notable_quotes' => 'nullable|array',
            'affiliate_url' => 'nullable|string|max:1024',
            'affiliate_cta_label' => 'nullable|string|max:120',
            'affiliate_price_hint' => 'nullable|string|max:120',
            'publisher_or_studio' => 'nullable|string|max:160',
            'is_featured' => 'nullable|boolean',
        ], [
            'title.required' => 'O título da obra ou ensaio é obrigatório.',
            'synopsis.required' => 'O texto do ensaio/resenha é obrigatório.',
            'category_id.required' => 'Selecione uma categoria temática válida.',
        ]);

        $slug = Str::slug($validated['title']) . '-' . random_int(100, 999);
        $canSetAffiliate = $user->hasRole('ADMIN', 'MODERATOR');

        $work = Work::create([
            'title' => $validated['title'],
            'subtitle' => $validated['subtitle'] ?? null,
            'slug' => $slug,
            'creator' => !empty($validated['creator']) ? $validated['creator'] : 'Autor Clássico',
            'media_type' => $validated['media_type'] ?? 'BOOK',
            'release_year' => $validated['release_year'] ?? 1950,
            'category_id' => $validated['category_id'],
            'cover_url' => !empty($validated['cover_url'])
                ? $validated['cover_url']
                : 'https://images.unsplash.com/photo-1544716278-ca5e3f4abd8c?auto=format&fit=crop&w=900&q=85',
            'synopsis' => $validated['synopsis'],
            'notable_quotes' => $validated['notable_quotes'] ?? [],
            'affiliate_url' => $canSetAffiliate && !empty($validated['affiliate_url']) ? trim($validated['affiliate_url']) : '',
            'affiliate_cta_label' => $canSetAffiliate && !empty($validated['affiliate_cta_label']) ? $validated['affiliate_cta_label'] : 'Adquirir na Amazon',
            'affiliate_price_hint' => $canSetAffiliate && !empty($validated['affiliate_price_hint']) ? $validated['affiliate_price_hint'] : 'Edição Recomendada',
            'publisher_or_studio' => $validated['publisher_or_studio'] ?? 'Edição Recomendada',
            'is_featured' => $validated['is_featured'] ?? true,
            'editorial_rating' => 4.9,
            'status' => 'PENDING',
            'submitted_by_id' => $user->id,
        ]);

        $flag = ModerationFlag::create([
            'target_type' => 'WORK',
            'target_id' => $work->id,
            'target_title' => 'Revisão de Nova Obra/Resenha: ' . $work->title,
            'target_excerpt' => Str::limit($work->synopsis, 220),
            'target_author_id' => $user->id,
            'target_author_name' => $user->name,
            'reporter_id' => $user->id,
            'reporter_name' => 'Fila Obrigatória de Revisão Editorial',
            'reason' => 'BROKEN_AFFILIATE_LINK',
            'details' => 'Todo texto publicado aguarda revisão obrigatória da moderação antes de ir ao ar.',
            'status' => 'PENDING',
        ]);

        $work->load(['category', 'submittedBy', 'comments.author', 'comments.replies.author']);

        $log = ModerationController::recordAuditLog(
            $user,
            'CREATE_WORK',
            'WORK',
            $work->id,
            $work->title . ' (' . $work->creator . ')',
            $user->name,
            'Nova obra/resenha submetida para revisão editorial.',
            $request
        );

        return response()->json([
            'work' => $work,
            'flag' => $flag,
            'log' => $log,
        ], 201);
    }

    /**
     * POST /api/works/{id}/affiliate-click
     */
    public function trackAffiliateClick(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        $work = Work::findOrFail($id);
        $work->increment('affiliate_clicks_count');

        $log = ModerationController::recordAuditLog(
            $user,
            'CLICK_AFFILIATE',
            'WORK',
            $work->id,
            $work->title . ' (' . $work->creator . ')',
            $work->creator,
            'Clique no botão de afiliado Amazon (total acumulado: ' . $work->affiliate_clicks_count . ').',
            $request,
            'Leitor / Visitante',
            'VISITOR'
        );

        return response()->json([
            'clicks_count' => $work->affiliate_clicks_count,
            'log' => $log,
        ]);
    }

    /**
     * POST /api/topics (RBAC: MEMBER, MODERATOR, ADMIN)
     */
    public function createTopic(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user || !$user->hasRole('MEMBER', 'COLUMNIST', 'MODERATOR', 'ADMIN')) {
            return response()->json([
                'message' => 'Autenticação necessária: faça login para publicar um ensaio.',
            ], 401);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'work_id' => 'nullable|exists:works,id',
            'body' => 'required|string',
            'tags' => 'nullable|array',
        ]);

        $slug = Str::slug($validated['title']) . '-' . random_int(100, 999);
        $excerpt = Str::limit(preg_replace('/^>\s*/m', '', $validated['body']), 160);

        $topic = Topic::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'excerpt' => $excerpt,
            'body' => $validated['body'],
            'category_id' => $validated['category_id'],
            'work_id' => $validated['work_id'] ?? null,
            'author_id' => $user->id,
            'tags' => $validated['tags'] ?? ['Diálogo Clássico'],
            'status' => 'PENDING',
        ]);

        $flag = ModerationFlag::create([
            'target_type' => 'TOPIC',
            'target_id' => $topic->id,
            'target_title' => 'Revisão de Novo Ensaio: ' . $topic->title,
            'target_excerpt' => Str::limit($topic->body, 220),
            'target_author_id' => $user->id,
            'target_author_name' => $user->name,
            'reporter_id' => $user->id,
            'reporter_name' => 'Fila Obrigatória de Revisão Editorial',
            'reason' => 'GUIDELINE_VIOLATION',
            'details' => 'Todo ensaio publicado aguarda revisão obrigatória da moderação antes de ir ao ar.',
            'status' => 'PENDING',
        ]);

        $topic->load(['category', 'work', 'author', 'comments.author', 'comments.replies.author']);

        $log = ModerationController::recordAuditLog(
            $user,
            'CREATE_TOPIC',
            'TOPIC',
            $topic->id,
            $topic->title,
            $user->name,
            'Novo ensaio submetido para revisão editorial.',
            $request
        );

        return response()->json([
            'topic' => $topic,
            'flag' => $flag,
            'log' => $log,
        ], 201);
    }

    /**
     * POST /api/comments (RBAC: MEMBER, MODERATOR, ADMIN)
     */
    public function createComment(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user || !$user->hasRole('MEMBER', 'COLUMNIST', 'MODERATOR', 'ADMIN')) {
            return response()->json([
                'message' => 'Faça login na sua conta para publicar comentários.',
            ], 401);
        }

        $validated = $request->validate([
            'body' => 'required|string',
            'rating' => 'nullable|integer|min:1|max:5',
            'work_id' => 'nullable|exists:works,id',
            'topic_id' => 'nullable|exists:topics,id',
            'series_entry_id' => 'nullable|exists:series_entries,id',
            'parent_id' => 'nullable|exists:comments,id',
        ]);

        $comment = Comment::create([
            'body' => $validated['body'],
            'rating' => $validated['rating'] ?? null,
            'work_id' => $validated['work_id'] ?? null,
            'topic_id' => $validated['topic_id'] ?? null,
            'series_entry_id' => $validated['series_entry_id'] ?? null,
            'parent_id' => $validated['parent_id'] ?? null,
            'author_id' => $user->id,
            'upvotes_count' => 1,
            'status' => 'APPROVED',
        ]);

        $contextLabel = 'Discussão Geral';
        $actionUrl = '/';
        $targetOwnerId = null;

        if (!empty($validated['work_id'])) {
            $w = Work::find($validated['work_id']);
            if ($w) {
                $w->increment('reviews_count');
                $contextLabel = 'Obra: ' . $w->title;
                $actionUrl = '/obras/' . $w->slug . '#comentarios';
                $targetOwnerId = $w->submitted_by_id;
            }
        }

        if (!empty($validated['topic_id'])) {
            $t = Topic::find($validated['topic_id']);
            if ($t) {
                $t->increment('comments_count');
                $contextLabel = 'Ensaio: ' . $t->title;
                $actionUrl = '/topicos/' . $t->slug . '#comentarios-topico';
                $targetOwnerId = $t->author_id;
            }
        }

        if (!empty($validated['series_entry_id'])) {
            $se = SeriesEntry::with('series')->find($validated['series_entry_id']);
            if ($se) {
                $se->increment('comments_count');
                $contextLabel = 'Nota de Série #' . $se->entry_number . ': ' . $se->title;
                if ($se->series) {
                    $actionUrl = '/series/' . $se->series->slug . '/' . $se->slug;
                }
                $targetOwnerId = $se->author_id;
            }
        }

        $notifiedIds = [(int) $user->id];
        if (!empty($validated['parent_id'])) {
            $parentComment = Comment::find($validated['parent_id']);
            if ($parentComment && $parentComment->author_id && !in_array((int) $parentComment->author_id, $notifiedIds, true)) {
                UserNotification::notify(
                    $parentComment->author_id,
                    $user,
                    'REPLY_TO_COMMENT',
                    $user->name . ' respondeu ao seu comentário',
                    '“' . Str::limit($comment->body, 140) . '”',
                    'COMMENT',
                    $comment->id,
                    $actionUrl
                );
                $notifiedIds[] = (int) $parentComment->author_id;
            }
        }

        if ($targetOwnerId && !in_array((int) $targetOwnerId, $notifiedIds, true)) {
            UserNotification::notify(
                $targetOwnerId,
                $user,
                'COMMENT_ON_POST',
                $user->name . ' comentou em ' . $contextLabel,
                '“' . Str::limit($comment->body, 140) . '”',
                'COMMENT',
                $comment->id,
                $actionUrl
            );
        }

        $comment->load(['author', 'replies.author']);

        $log = ModerationController::recordAuditLog(
            $user,
            !empty($validated['parent_id']) ? 'REPLY_COMMENT' : 'CREATE_COMMENT',
            'COMMENT',
            $comment->id,
            'Comentário #' . $comment->id . ' em ' . $contextLabel,
            $user->name,
            '“' . Str::limit($comment->body, 200) . '”',
            $request
        );

        return response()->json([
            'comment' => $comment,
            'log' => $log,
        ], 201);
    }

    /**
     * POST /api/upvotes (RBAC: MEMBER, COLUMNIST, MODERATOR, ADMIN — 1 upvote per user per item)
     */
    public function toggleUpvote(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user || !$user->hasRole('MEMBER', 'COLUMNIST', 'MODERATOR', 'ADMIN')) {
            return response()->json([
                'message' => 'Para curtir e comentar é necessário ter uma conta e estar logado.',
            ], 401);
        }

        $validated = $request->validate([
            'target_type' => 'required|in:WORK,TOPIC,COMMENT,SERIES_ENTRY',
            'target_id' => 'required|integer',
        ]);

        $alreadyLiked = Upvote::where('user_id', $user->id)
            ->where('target_type', $validated['target_type'])
            ->where('target_id', $validated['target_id'])
            ->first();

        if ($alreadyLiked) {
            $alreadyLiked->delete();
            $upvotesCount = 0;
            $targetTitle = $validated['target_type'] . ' #' . $validated['target_id'];

            if ($validated['target_type'] === 'TOPIC') {
                $topic = Topic::find($validated['target_id']);
                if ($topic) {
                    if ($topic->upvotes_count > 0) {
                        $topic->decrement('upvotes_count');
                    }
                    $upvotesCount = $topic->upvotes_count;
                    $targetTitle = 'Ensaio: ' . $topic->title;
                }
            } elseif ($validated['target_type'] === 'SERIES_ENTRY') {
                $entry = SeriesEntry::find($validated['target_id']);
                if ($entry) {
                    if ($entry->upvotes_count > 0) {
                        $entry->decrement('upvotes_count');
                    }
                    $upvotesCount = $entry->upvotes_count;
                    $targetTitle = 'Nota #' . $entry->entry_number . ': ' . $entry->title;
                }
            } elseif ($validated['target_type'] === 'COMMENT') {
                $comment = Comment::find($validated['target_id']);
                if ($comment) {
                    if ($comment->upvotes_count > 0) {
                        $comment->decrement('upvotes_count');
                    }
                    $upvotesCount = $comment->upvotes_count;
                    $targetTitle = 'Comentário #' . $comment->id . ': ' . Str::limit($comment->body, 80);
                }
            } elseif ($validated['target_type'] === 'WORK') {
                $work = Work::find($validated['target_id']);
                if ($work) {
                    $targetTitle = 'Obra: ' . $work->title;
                }
            }

            $log = ModerationController::recordAuditLog(
                $user,
                'UPVOTE_REMOVE',
                $validated['target_type'],
                (int) $validated['target_id'],
                $targetTitle,
                null,
                'Curtida removida por ' . $user->name . '.',
                $request
            );

            return response()->json([
                'upvotes_count' => $upvotesCount,
                'already_upvoted' => false,
                'unliked' => true,
                'log' => $log,
            ]);
        }

        Upvote::create([
            'user_id' => $user->id,
            'target_type' => $validated['target_type'],
            'target_id' => $validated['target_id'],
        ]);

        $upvotesCount = 1;
        $targetTitle = $validated['target_type'] . ' #' . $validated['target_id'];
        $targetOwnerId = null;
        $targetUrl = '/';

        if ($validated['target_type'] === 'TOPIC') {
            $topic = Topic::findOrFail($validated['target_id']);
            $topic->increment('upvotes_count');
            $upvotesCount = $topic->upvotes_count;
            $targetTitle = 'Ensaio: ' . $topic->title;
            $targetOwnerId = $topic->author_id;
            $targetUrl = '/topicos/' . $topic->slug;
        } elseif ($validated['target_type'] === 'SERIES_ENTRY') {
            $entry = SeriesEntry::with('series')->findOrFail($validated['target_id']);
            $entry->increment('upvotes_count');
            $upvotesCount = $entry->upvotes_count;
            $targetTitle = 'Nota #' . $entry->entry_number . ': ' . $entry->title;
            $targetOwnerId = $entry->author_id;
            if ($entry->series) {
                $targetUrl = '/series/' . $entry->series->slug . '/' . $entry->slug;
            }
        } elseif ($validated['target_type'] === 'WORK') {
            $work = Work::findOrFail($validated['target_id']);
            $targetTitle = 'Obra: ' . $work->title;
            $targetOwnerId = $work->submitted_by_id;
            $targetUrl = '/obras/' . $work->slug;
        } else {
            $comment = Comment::findOrFail($validated['target_id']);
            $comment->increment('upvotes_count');
            $upvotesCount = $comment->upvotes_count;
            $targetTitle = 'Comentário #' . $comment->id . ': ' . Str::limit($comment->body, 80);
            $targetOwnerId = $comment->author_id;
            if ($comment->work_id && ($w = Work::find($comment->work_id))) {
                $targetUrl = '/obras/' . $w->slug . '#comentarios';
            } elseif ($comment->topic_id && ($t = Topic::find($comment->topic_id))) {
                $targetUrl = '/topicos/' . $t->slug . '#comentarios-topico';
            } elseif ($comment->series_entry_id && ($se = SeriesEntry::with('series')->find($comment->series_entry_id)) && $se->series) {
                $targetUrl = '/series/' . $se->series->slug . '/' . $se->slug;
            }
        }

        if ($targetOwnerId) {
            UserNotification::notify(
                $targetOwnerId,
                $user,
                'UPVOTE',
                $user->name . ' apreciou seu conteúdo',
                $targetTitle,
                $validated['target_type'],
                (int) $validated['target_id'],
                $targetUrl
            );
        }

        $log = ModerationController::recordAuditLog(
            $user,
            'UPVOTE_ADD',
            $validated['target_type'],
            (int) $validated['target_id'],
            $targetTitle,
            null,
            'Curtida registrada por ' . $user->name . '.',
            $request
        );

        return response()->json([
            'upvotes_count' => $upvotesCount,
            'already_upvoted' => true,
            'log' => $log,
        ]);
    }

    /**
     * PUT/PATCH /api/works/{id}
     * Allows the author (or MODERATOR/ADMIN) to edit a work/review and resubmit it for moderation.
     */
    public function updateWork(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) {
            return response()->json(['message' => 'Faça login para editar sua publicação.'], 401);
        }

        $work = Work::findOrFail($id);
        $isModerator = $user->hasRole('ADMIN', 'MODERATOR');
        if ($work->submitted_by_id !== $user->id && !$isModerator) {
            return response()->json(['message' => 'Você não tem permissão para editar esta publicação.'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'creator' => 'nullable|string|max:255',
            'media_type' => 'nullable|string|max:32',
            'release_year' => 'nullable|integer',
            'category_id' => 'required|exists:categories,id',
            'cover_url' => 'nullable|string|max:1024',
            'synopsis' => 'required|string',
            'notable_quotes' => 'nullable|array',
            'publisher_or_studio' => 'nullable|string|max:160',
        ]);

        $newStatus = ($isModerator && $work->status === 'APPROVED') ? 'APPROVED' : 'PENDING';

        $work->update([
            'title' => $validated['title'],
            'subtitle' => $validated['subtitle'] ?? null,
            'creator' => !empty($validated['creator']) ? $validated['creator'] : $work->creator,
            'media_type' => $validated['media_type'] ?? $work->media_type,
            'release_year' => $validated['release_year'] ?? $work->release_year,
            'category_id' => $validated['category_id'],
            'cover_url' => !empty($validated['cover_url']) ? $validated['cover_url'] : $work->cover_url,
            'synopsis' => $validated['synopsis'],
            'notable_quotes' => $validated['notable_quotes'] ?? $work->notable_quotes,
            'publisher_or_studio' => $validated['publisher_or_studio'] ?? $work->publisher_or_studio,
            'status' => $newStatus,
        ]);

        if ($newStatus === 'PENDING') {
            ModerationFlag::firstOrCreate(
                [
                    'target_type' => 'WORK',
                    'target_id' => $work->id,
                    'status' => 'PENDING',
                ],
                [
                    'target_title' => 'Reenvio após ajustes: ' . $work->title,
                    'target_excerpt' => Str::limit($work->synopsis, 220),
                    'target_author_id' => $work->submitted_by_id,
                    'target_author_name' => $user->name,
                    'reporter_id' => $user->id,
                    'reporter_name' => 'Reenvio do Autor após Feedback',
                    'reason' => 'GUIDELINE_VIOLATION',
                    'details' => 'O autor revisou o texto e reenviou para nova análise da moderação.',
                ]
            );
        }

        $work->load(['category', 'submittedBy', 'comments']);

        $log = ModerationController::recordAuditLog(
            $user,
            'UPDATE_WORK',
            'WORK',
            $work->id,
            $work->title,
            $work->submittedBy?->name ?? $user->name,
            $newStatus === 'PENDING'
                ? 'Obra/resenha editada pelo autor e reenviada para revisão da moderação.'
                : 'Obra/resenha editada e atualizada.',
            $request
        );

        return response()->json([
            'work' => $work,
            'log' => $log,
            'message' => $newStatus === 'PENDING'
                ? 'Texto atualizado e reenviado para revisão da moderação!'
                : 'Texto atualizado com sucesso!',
        ]);
    }

    /**
     * PUT/PATCH /api/topics/{id}
     * Allows the author (or MODERATOR/ADMIN) to edit a topic/essay and resubmit it for moderation.
     */
    public function updateTopic(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) {
            return response()->json(['message' => 'Faça login para editar seu ensaio.'], 401);
        }

        $topic = Topic::findOrFail($id);
        $isModerator = $user->hasRole('ADMIN', 'MODERATOR');
        if ($topic->author_id !== $user->id && !$isModerator) {
            return response()->json(['message' => 'Você não tem permissão para editar este ensaio.'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'excerpt' => 'nullable|string|max:500',
            'body' => 'required|string',
            'category_id' => 'required|exists:categories,id',
            'work_id' => 'nullable|exists:works,id',
            'tags' => 'nullable|array',
        ]);

        $newStatus = ($isModerator && $topic->status === 'APPROVED') ? 'APPROVED' : 'PENDING';

        $topic->update([
            'title' => $validated['title'],
            'excerpt' => !empty($validated['excerpt']) ? $validated['excerpt'] : Str::limit(strip_tags($validated['body']), 180),
            'body' => $validated['body'],
            'category_id' => $validated['category_id'],
            'work_id' => $validated['work_id'] ?? null,
            'tags' => $validated['tags'] ?? $topic->tags,
            'status' => $newStatus,
        ]);

        if ($newStatus === 'PENDING') {
            ModerationFlag::firstOrCreate(
                [
                    'target_type' => 'TOPIC',
                    'target_id' => $topic->id,
                    'status' => 'PENDING',
                ],
                [
                    'target_title' => 'Reenvio após ajustes: ' . $topic->title,
                    'target_excerpt' => Str::limit($topic->body, 220),
                    'target_author_id' => $topic->author_id,
                    'target_author_name' => $user->name,
                    'reporter_id' => $user->id,
                    'reporter_name' => 'Reenvio do Autor após Feedback',
                    'reason' => 'GUIDELINE_VIOLATION',
                    'details' => 'O autor revisou o ensaio e reenviou para nova análise da moderação.',
                ]
            );
        }

        $topic->load(['category', 'work', 'author', 'comments']);

        $log = ModerationController::recordAuditLog(
            $user,
            'UPDATE_TOPIC',
            'TOPIC',
            $topic->id,
            $topic->title,
            $topic->author?->name ?? $user->name,
            $newStatus === 'PENDING'
                ? 'Ensaio editado pelo autor e reenviado para revisão da moderação.'
                : 'Ensaio editado e atualizado.',
            $request
        );

        return response()->json([
            'topic' => $topic,
            'log' => $log,
            'message' => $newStatus === 'PENDING'
                ? 'Ensaio atualizado e reenviado para revisão da moderação!'
                : 'Ensaio atualizado com sucesso!',
        ]);
    }
}
