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
        'api_token',
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
        'api_token',
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

    /**
     * Check if user has any of the given RBAC roles.
     */
    public function hasRole(string ...$roles): bool
    {
        return in_array(strtoupper($this->role), array_map('strtoupper', $roles), true);
    }

    /**
     * Return explicit RBAC permissions matrix for the user's role.
     */
    public function permissions(): array
    {
        $role = strtoupper($this->role);

        return [
            'can_create_work' => in_array($role, ['MEMBER', 'MODERATOR', 'ADMIN'], true) && !$this->is_banned,
            'can_create_topic' => in_array($role, ['MEMBER', 'MODERATOR', 'ADMIN'], true) && !$this->is_banned,
            'can_comment' => in_array($role, ['MEMBER', 'MODERATOR', 'ADMIN'], true) && !$this->is_banned,
            'can_publish_directly' => in_array($role, ['MODERATOR', 'ADMIN'], true) && !$this->is_banned,
            'can_moderate_queue' => in_array($role, ['MODERATOR', 'ADMIN'], true) && !$this->is_banned,
            'can_ban_users' => $role === 'ADMIN' && !$this->is_banned,
            'can_manage_affiliate_settings' => $role === 'ADMIN' && !$this->is_banned,
            'can_manage_roles' => $role === 'ADMIN' && !$this->is_banned,
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
