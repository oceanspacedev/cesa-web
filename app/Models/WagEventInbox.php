<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WagEventInbox extends Model
{
    protected $table = 'wag_event_inbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
