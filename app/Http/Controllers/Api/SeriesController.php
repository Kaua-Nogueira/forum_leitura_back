<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReadingSeries;
use App\Models\SeriesEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SeriesController extends Controller
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
     * GET /api/series
     */
    public function index(): JsonResponse
    {
        $series = ReadingSeries::with([
            'category',
            'work',
            'author',
            'entries.author',
            'entries.comments.author',
            'entries.comments.replies.author',
        ])->latest()->get();

        return response()->json([
            'series' => $series,
        ]);
    }

    /**
     * POST /api/series (RBAC: COLUMNIST, MODERATOR, ADMIN)
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user || !$user->hasRole('COLUMNIST', 'MODERATOR', 'ADMIN')) {
            return response()->json([
                'message' => 'Acesso restrito: apenas Colunistas, Moderadores e Administradores podem criar Séries & Cadernos.',
            ], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'description' => 'required|string',
            'book_title' => 'nullable|string|max:255',
            'book_author' => 'nullable|string|max:255',
            'cover_url' => 'nullable|string|max:1024',
            'cadence_label' => 'nullable|string|max:80',
            'category_id' => 'required|exists:categories,id',
            'work_id' => 'nullable|exists:works,id',
        ]);

        $slug = Str::slug($validated['title']) . '-' . random_int(100, 999);

        $series = ReadingSeries::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'subtitle' => $validated['subtitle'] ?? null,
            'description' => $validated['description'],
            'book_title' => $validated['book_title'] ?? null,
            'book_author' => $validated['book_author'] ?? null,
            'cover_url' => !empty($validated['cover_url'])
                ? $validated['cover_url']
                : 'https://images.unsplash.com/photo-1457369804613-52c61a468e7d?auto=format&fit=crop&w=900&q=85',
            'cadence_label' => !empty($validated['cadence_label']) ? $validated['cadence_label'] : 'Publicação Diária',
            'category_id' => $validated['category_id'],
            'work_id' => $validated['work_id'] ?? null,
            'author_id' => $user->id,
            'status' => 'ACTIVE',
            'entries_count' => 0,
        ]);

        $series->load(['category', 'work', 'author', 'entries']);

        return response()->json([
            'series' => $series,
            'message' => 'Série / Caderno criado com sucesso!',
        ], 201);
    }

    /**
     * PUT/PATCH /api/series/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) {
            return response()->json(['message' => 'Faça login para editar a série.'], 401);
        }

        $series = ReadingSeries::findOrFail($id);
        $isOwner = $series->author_id === $user->id && $user->hasRole('COLUMNIST', 'MODERATOR', 'ADMIN');
        if (!$isOwner && !$user->hasRole('MODERATOR', 'ADMIN')) {
            return response()->json(['message' => 'Você não tem permissão para editar esta série.'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'description' => 'sometimes|required|string',
            'book_title' => 'nullable|string|max:255',
            'book_author' => 'nullable|string|max:255',
            'cover_url' => 'nullable|string|max:1024',
            'cadence_label' => 'nullable|string|max:80',
            'category_id' => 'sometimes|required|exists:categories,id',
            'work_id' => 'nullable|exists:works,id',
            'status' => 'nullable|in:ACTIVE,COMPLETED,ARCHIVED',
        ]);

        $series->update($validated);
        $series->load(['category', 'work', 'author', 'entries.author', 'entries.comments.author', 'entries.comments.replies.author']);

        return response()->json([
            'series' => $series,
            'message' => 'Série atualizada com sucesso!',
        ]);
    }

    /**
     * DELETE /api/series/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) {
            return response()->json(['message' => 'Faça login para excluir a série.'], 401);
        }

        $series = ReadingSeries::findOrFail($id);
        if ($series->author_id !== $user->id && !$user->hasRole('MODERATOR', 'ADMIN')) {
            return response()->json(['message' => 'Você não tem permissão para excluir esta série.'], 403);
        }

        $series->delete();

        return response()->json([
            'deleted' => true,
            'series_id' => $id,
            'message' => 'Série excluída com sucesso.',
        ]);
    }

    /**
     * POST /api/series/{id}/entries (RBAC: Series Owner with COLUMNIST/MODERATOR/ADMIN or MODERATOR/ADMIN)
     */
    public function storeEntry(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user || !$user->hasRole('COLUMNIST', 'MODERATOR', 'ADMIN')) {
            return response()->json([
                'message' => 'Acesso restrito: apenas Colunistas, Moderadores e Administradores podem publicar notas em séries.',
            ], 403);
        }

        $series = ReadingSeries::findOrFail($id);
        if ($series->author_id !== $user->id && !$user->hasRole('MODERATOR', 'ADMIN')) {
            return response()->json([
                'message' => 'Apenas o autor desta série ou a moderação podem publicar novas entradas aqui.',
            ], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'reference_label' => 'nullable|string|max:120',
            'entry_number' => 'nullable|integer|min:1',
            'body' => 'required|string',
        ]);

        $nextNumber = $validated['entry_number']
            ?? ((int) SeriesEntry::where('series_id', $series->id)->max('entry_number') + 1);

        $slug = Str::slug($series->slug . '-' . $nextNumber . '-' . $validated['title']) . '-' . random_int(100, 999);

        $entry = SeriesEntry::create([
            'series_id' => $series->id,
            'author_id' => $user->id,
            'entry_number' => $nextNumber,
            'reference_label' => $validated['reference_label'] ?? ('Nota #' . str_pad((string) $nextNumber, 2, '0', STR_PAD_LEFT)),
            'title' => $validated['title'],
            'slug' => $slug,
            'body' => $validated['body'],
            'upvotes_count' => 0,
            'comments_count' => 0,
            'published_at' => now(),
        ]);

        $series->increment('entries_count');
        $series->touch();

        $entry->load(['author', 'comments']);

        return response()->json([
            'entry' => $entry,
            'series_id' => $series->id,
            'message' => 'Nova nota publicada na série!',
        ], 201);
    }

    /**
     * PUT/PATCH /api/series/{seriesId}/entries/{entryId}
     */
    public function updateEntry(Request $request, int $seriesId, int $entryId): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) {
            return response()->json(['message' => 'Faça login para editar a nota.'], 401);
        }

        $entry = SeriesEntry::where('series_id', $seriesId)->findOrFail($entryId);
        if ($entry->author_id !== $user->id && !$user->hasRole('MODERATOR', 'ADMIN')) {
            return response()->json(['message' => 'Você não tem permissão para editar esta nota.'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'reference_label' => 'nullable|string|max:120',
            'entry_number' => 'nullable|integer|min:1',
            'body' => 'sometimes|required|string',
        ]);

        $entry->update($validated);
        $entry->load(['author', 'comments.author', 'comments.replies.author']);

        return response()->json([
            'entry' => $entry,
            'message' => 'Nota atualizada com sucesso!',
        ]);
    }

    /**
     * DELETE /api/series/{seriesId}/entries/{entryId}
     */
    public function destroyEntry(Request $request, int $seriesId, int $entryId): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) {
            return response()->json(['message' => 'Faça login para excluir a nota.'], 401);
        }

        $entry = SeriesEntry::where('series_id', $seriesId)->findOrFail($entryId);
        if ($entry->author_id !== $user->id && !$user->hasRole('MODERATOR', 'ADMIN')) {
            return response()->json(['message' => 'Você não tem permissão para excluir esta nota.'], 403);
        }

        $series = ReadingSeries::find($seriesId);
        $entry->delete();
        if ($series && $series->entries_count > 0) {
            $series->decrement('entries_count');
        }

        return response()->json([
            'deleted' => true,
            'series_id' => $seriesId,
            'entry_id' => $entryId,
            'message' => 'Nota excluída com sucesso.',
        ]);
    }
}
