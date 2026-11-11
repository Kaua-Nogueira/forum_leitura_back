<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
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
     */
    public function bootstrap(): JsonResponse
    {
        $categories = Category::withCount(['works', 'topics'])->orderBy('id')->get();

        $works = Work::with(['category', 'submittedBy', 'comments.author', 'comments.replies.author'])
            ->latest()
            ->get();

        $topics = Topic::with(['category', 'work', 'author', 'comments.author', 'comments.replies.author'])
            ->orderByDesc('is_pinned')
            ->latest()
            ->get();

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

        $flags = ModerationFlag::latest()->get();

        $settings = PlatformSetting::first() ?? PlatformSetting::create([
            'amazon_associate_tag' => 'veritaslitterae-20',
            'default_affiliate_disclaimer' => 'Comprando por este link, você apoia a curadoria e manutenção editorial deste projeto sem custo adicional.',
            'require_moderation_for_new_works' => true,
            'editorial_edition_label' => 'Edição de Outono • Vol. IV — MMXXVI',
        ]);

        $users = User::orderBy('id')->get();

        $moderationLogs = ModerationLog::latest()->limit(250)->get();
        $upvotes = Upvote::select(['user_id', 'target_type', 'target_id'])->get();

        return response()->json([
            'categories' => $categories,
            'works' => $works,
            'topics' => $topics,
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
        if (!$user || !$user->hasRole('MEMBER', 'MODERATOR', 'ADMIN')) {
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
        $defaultAffiliateUrl = 'https://www.amazon.com.br/s?k=' . urlencode($validated['title']);

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
            'affiliate_url' => !empty($validated['affiliate_url']) ? $validated['affiliate_url'] : $defaultAffiliateUrl,
            'affiliate_cta_label' => $validated['affiliate_cta_label'] ?? 'Adquirir na Amazon',
            'affiliate_price_hint' => $validated['affiliate_price_hint'] ?? 'Edição Recomendada',
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
        if (!$user || !$user->hasRole('MEMBER', 'MODERATOR', 'ADMIN')) {
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
        if (!$user || !$user->hasRole('MEMBER', 'MODERATOR', 'ADMIN')) {
            return response()->json([
                'message' => 'Faça login na sua conta para publicar comentários.',
            ], 401);
        }

        $validated = $request->validate([
            'body' => 'required|string',
            'rating' => 'nullable|integer|min:1|max:5',
            'work_id' => 'nullable|exists:works,id',
            'topic_id' => 'nullable|exists:topics,id',
            'parent_id' => 'nullable|exists:comments,id',
        ]);

        $comment = Comment::create([
            'body' => $validated['body'],
            'rating' => $validated['rating'] ?? null,
            'work_id' => $validated['work_id'] ?? null,
            'topic_id' => $validated['topic_id'] ?? null,
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

        $comment->load(['author', 'replies.author']);

        return response()->json(['comment' => $comment], 201);
    }

    /**
     * POST /api/upvotes (RBAC: MEMBER, MODERATOR, ADMIN — 1 upvote per user per item)
     */
    public function toggleUpvote(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user || !$user->hasRole('MEMBER', 'MODERATOR', 'ADMIN')) {
            return response()->json([
                'message' => 'Para curtir e comentar é necessário ter uma conta e estar logado.',
            ], 401);
        }

        $validated = $request->validate([
            'target_type' => 'required|in:WORK,TOPIC,COMMENT',
            'target_id' => 'required|integer',
        ]);

        $alreadyLiked = Upvote::where('user_id', $user->id)
            ->where('target_type', $validated['target_type'])
            ->where('target_id', $validated['target_id'])
            ->exists();

        if ($alreadyLiked) {
            return response()->json([
                'message' => 'Você já deu curtida neste item. Cada usuário só pode curtir uma única vez.',
                'already_upvoted' => true,
            ], 409);
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
}
