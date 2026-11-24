<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReadingSeries extends Model
{
    use HasFactory;

    protected $table = 'reading_series';

    protected $fillable = [
        'title',
        'slug',
        'subtitle',
        'description',
        'book_title',
        'book_author',
        'cover_url',
        'cadence_label',
        'category_id',
        'work_id',
        'author_id',
        'status',
        'entries_count',
    ];

    protected function casts(): array
    {
        return [
            'entries_count' => 'integer',
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

    public function entries()
    {
        return $this->hasMany(SeriesEntry::class, 'series_id')->orderBy('entry_number')->orderBy('id');
    }
}
