<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'role'              => UserRole::class,
    ];

    // -------------------------------------------------------------------------
    // Role helpers
    // -------------------------------------------------------------------------

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    public function canPerformTransition(\App\Enums\DocumentStatus $status): bool
    {
        return in_array($status, $this->role->allowedTransitions(), strict: true);
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function documents()
    {
        return $this->hasMany(Document::class, 'author_id');
    }

    public function performedTransitions()
    {
        return $this->hasMany(DocumentTransition::class, 'actor_id');
    }
}
