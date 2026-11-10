<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Topic extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'body',
        'category_id',
        'work_id',
        'author_id',
        'is_pinned',
        'views_count',
        'upvotes_count',
        'comments_count',
        'tags',
        'status',
        'moderation_note',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'views_count' => 'integer',
            'upvotes_count' => 'integer',
            'comments_count' => 'integer',
            'tags' => 'array',
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function work()
    {
        return $this->belongsTo(Work::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class)
            ->whereNull('parent_id')
            ->with(['author', 'replies'])
            ->latest();
    }
}
