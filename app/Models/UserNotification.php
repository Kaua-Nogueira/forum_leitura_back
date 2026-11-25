<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UserNotification extends Model
{
    use HasFactory;

    protected $table = 'user_notifications';

    protected $fillable = [
        'user_id',
        'actor_id',
        'actor_name',
        'actor_avatar_url',
        'type',
        'title',
        'body',
        'target_type',
        'target_id',
        'action_url',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'actor_id' => 'integer',
            'target_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function notify(
        ?int $recipientId,
        ?User $actor,
        string $type,
        string $title,
        ?string $body = null,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $actionUrl = null
    ): ?self {
        if (! $recipientId) {
            return null;
        }
        if ($actor && (int) $actor->id === (int) $recipientId) {
            return null;
        }
        if (! Schema::hasTable('user_notifications')) {
            return null;
        }

        return self::create([
            'user_id' => $recipientId,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name ?? 'Sistema Editorial',
            'actor_avatar_url' => $actor?->avatar_url,
            'type' => Str::limit($type, 40, ''),
            'title' => Str::limit($title, 250),
            'body' => $body ? Str::limit($body, 500) : null,
            'target_type' => $targetType ? Str::limit($targetType, 24, '') : null,
            'target_id' => $targetId,
            'action_url' => $actionUrl ? Str::limit($actionUrl, 255, '') : null,
            'read_at' => null,
        ]);
    }
}
