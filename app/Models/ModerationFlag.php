<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModerationFlag extends Model
{
    use HasFactory;

    protected $fillable = [
        'target_type',
        'target_id',
        'target_title',
        'target_excerpt',
        'target_author_id',
        'target_author_name',
        'reporter_id',
        'reporter_name',
        'reason',
        'details',
        'status',
        'moderator_note',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }
}
