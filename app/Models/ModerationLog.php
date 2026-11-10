<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModerationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'actor_id',
        'actor_name',
        'actor_role',
        'action_type',
        'target_type',
        'target_id',
        'target_title',
        'target_author_name',
        'reason',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'target_id' => 'integer',
        ];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
