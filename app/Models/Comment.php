<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'body',
        'rating',
        'work_id',
        'topic_id',
        'parent_id',
        'author_id',
        'upvotes_count',
        'status',
        'moderation_note',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'upvotes_count' => 'integer',
        ];
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function work()
    {
        return $this->belongsTo(Work::class);
    }

    public function topic()
    {
        return $this->belongsTo(Topic::class);
    }

    public function parent()
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_id')
            ->with(['author', 'replies'])
            ->oldest();
    }
}
