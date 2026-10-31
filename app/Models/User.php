<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = ['name', 'username', 'password', 'is_admin', 'is_active', 'permissions'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password'    => 'hashed',
            'is_admin'    => 'boolean',
            'is_active'   => 'boolean',
            'permissions' => 'array',
        ];
    }

    /** أيملك هذا المفتاحَ بعينه؟ */
    public function hasAbility(string $key): bool
    {
        return in_array($key, (array) ($this->permissions ?? []), true);
    }
}
