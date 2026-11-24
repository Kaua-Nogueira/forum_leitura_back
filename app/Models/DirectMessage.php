<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DirectMessage extends Model
{
    use HasFactory;

    protected $table = 'direct_messages';

    protected $fillable = [
        'sender_id',
        'recipient_id',
        'body',
        'shared_type',
        'shared_slug',
        'shared_title',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'sender_id' => 'integer',
            'recipient_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
