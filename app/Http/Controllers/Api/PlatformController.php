<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Comment;
use App\Models\ModerationFlag;
use App\Models\ModerationLog;
use App\Models\PlatformSetting;
use App\Models\ReadingSeries;
use App\Models\SeriesEntry;
use App\Models\Topic;
use App\Models\Upvote;
use App\Models\User;
use App\Models\Work;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $moderationLogs = $isModerator ? ModerationLog::latest()->limit(250)->get() : collect([]);
        $upvotes = Upvote::select(['user_id', 'target_type', 'target_id'])->get();

        $series = ReadingSeries::with([
            'category',
            'work',
            'author',
            'entries.author',
            'entries.comments.author',
            'entries.comments.replies.author',
        ])->latest()->get();

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

        return response()->json([
            'work' => $work,
            'flag' => $flag,
        ], 201);
    }

    /**
     * POST /api/works/{id}/affiliate-click
     */
    public function trackAffiliateClick(int $id): JsonResponse
    {
        $work = Work::findOrFail($id);
        $work->increment('affiliate_clicks_count');

        return response()->json([
            'clicks_count' => $work->affiliate_clicks_count,
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

        return response()->json([
            'topic' => $topic,
            'flag' => $flag,
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

        if (!empty($validated['work_id'])) {
            Work::where('id', $validated['work_id'])->increment('reviews_count');
        }

        if (!empty($validated['topic_id'])) {
            Topic::where('id', $validated['topic_id'])->increment('comments_count');
        }

        if (!empty($validated['series_entry_id'])) {
            SeriesEntry::where('id', $validated['series_entry_id'])->increment('comments_count');
        }

        $comment->load(['author', 'replies.author']);

        return response()->json(['comment' => $comment], 201);
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

            if ($validated['target_type'] === 'TOPIC') {
                $topic = Topic::find($validated['target_id']);
                if ($topic && $topic->upvotes_count > 0) {
                    $topic->decrement('upvotes_count');
                }
                return response()->json([
                    'upvotes_count' => $topic ? $topic->upvotes_count : 0,
                    'already_upvoted' => false,
                    'unliked' => true,
                ]);
            }

            if ($validated['target_type'] === 'SERIES_ENTRY') {
                $entry = SeriesEntry::find($validated['target_id']);
                if ($entry && $entry->upvotes_count > 0) {
                    $entry->decrement('upvotes_count');
                }
                return response()->json([
                    'upvotes_count' => $entry ? $entry->upvotes_count : 0,
                    'already_upvoted' => false,
                    'unliked' => true,
                ]);
            }

            if ($validated['target_type'] === 'COMMENT') {
                $comment = Comment::find($validated['target_id']);
                if ($comment && $comment->upvotes_count > 0) {
                    $comment->decrement('upvotes_count');
                }
                return response()->json([
                    'upvotes_count' => $comment ? $comment->upvotes_count : 0,
                    'already_upvoted' => false,
                    'unliked' => true,
                ]);
            }

            return response()->json([
                'upvotes_count' => 0,
                'already_upvoted' => false,
                'unliked' => true,
            ]);
        }

        Upvote::create([
            'user_id' => $user->id,
            'target_type' => $validated['target_type'],
            'target_id' => $validated['target_id'],
        ]);

        if ($validated['target_type'] === 'TOPIC') {
            $topic = Topic::findOrFail($validated['target_id']);
            $topic->increment('upvotes_count');
            return response()->json([
                'upvotes_count' => $topic->upvotes_count,
                'already_upvoted' => true,
            ]);
        }

        if ($validated['target_type'] === 'SERIES_ENTRY') {
            $entry = SeriesEntry::findOrFail($validated['target_id']);
            $entry->increment('upvotes_count');
            return response()->json([
                'upvotes_count' => $entry->upvotes_count,
                'already_upvoted' => true,
            ]);
        }

        if ($validated['target_type'] === 'WORK') {
            $work = Work::findOrFail($validated['target_id']);
            return response()->json([
                'upvotes_count' => 1,
                'already_upvoted' => true,
            ]);
        }

        $comment = Comment::findOrFail($validated['target_id']);
        $comment->increment('upvotes_count');
        return response()->json([
            'upvotes_count' => $comment->upvotes_count,
            'already_upvoted' => true,
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

        return response()->json([
            'work' => $work,
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

        return response()->json([
            'topic' => $topic,
            'message' => $newStatus === 'PENDING'
                ? 'Ensaio atualizado e reenviado para revisão da moderação!'
                : 'Ensaio atualizado com sucesso!',
        ]);
    }
}
