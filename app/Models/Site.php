<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $table = 'zk_sites';

    protected $fillable = ['name', 'code', 'note', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function pins(): HasMany
    {
        return $this->hasMany(Pin::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(Punch::class);
    }
}
