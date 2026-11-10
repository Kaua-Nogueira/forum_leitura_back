<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Work extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'subtitle',
        'slug',
        'creator',
        'media_type',
        'release_year',
        'category_id',
        'cover_url',
        'synopsis',
        'notable_quotes',
        'affiliate_url',
        'affiliate_cta_label',
        'affiliate_price_hint',
        'affiliate_clicks_count',
        'isbn_or_ref',
        'publisher_or_studio',
        'is_featured',
        'editorial_rating',
        'reviews_count',
        'status',
        'submitted_by_id',
        'moderation_note',
    ];

    protected function casts(): array
    {
        return [
            'release_year' => 'integer',
            'notable_quotes' => 'array',
            'affiliate_clicks_count' => 'integer',
            'is_featured' => 'boolean',
            'editorial_rating' => 'float',
            'reviews_count' => 'integer',
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class)
            ->whereNull('parent_id')
            ->with(['author', 'replies'])
            ->latest();
    }

    public function topics()
    {
        return $this->hasMany(Topic::class);
    }
}
