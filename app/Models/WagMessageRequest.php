<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WagMessageRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array'];
    }
}
