<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'title_badge',
        'bio',
        'avatar_url',
        'is_banned',
        'reputation_score',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_banned' => 'boolean',
            'reputation_score' => 'integer',
        ];
    }

    public function works()
    {
        return $this->hasMany(Work::class, 'submitted_by_id');
    }

    public function topics()
    {
        return $this->hasMany(Topic::class, 'author_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'author_id');
    }
}
