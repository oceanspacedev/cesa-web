<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WagIntegration extends Model
{
    protected $guarded = [];

    protected $hidden = ['token', 'webhook_secret'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'webhook_secret' => 'encrypted', 'snapshot' => 'array', 'verified_at' => 'datetime', 'default_selection_required' => 'boolean'];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }
}
