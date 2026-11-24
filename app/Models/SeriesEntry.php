<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeriesEntry extends Model
{
    use HasFactory;

    protected $table = 'series_entries';

    protected $fillable = [
        'series_id',
        'author_id',
        'entry_number',
        'reference_label',
        'title',
        'slug',
        'body',
        'upvotes_count',
        'comments_count',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_number' => 'integer',
            'upvotes_count' => 'integer',
            'comments_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function series()
    {
        return $this->belongsTo(ReadingSeries::class, 'series_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'series_entry_id')
            ->whereNull('parent_id')
            ->with(['author', 'replies.author'])
            ->latest();
    }
}
