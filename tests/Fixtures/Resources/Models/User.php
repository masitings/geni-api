<?php

namespace Tests\Fixtures\Resources\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Tests\Fixtures\Resources\Enums\UserStatus;

class User extends Model
{
    protected $hidden = ['email'];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'settings' => 'array',
            'email_verified_at' => 'datetime',
            'status' => UserStatus::class,
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function latestPost(): HasOne
    {
        return $this->hasOne(Post::class)->latestOfMany();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
