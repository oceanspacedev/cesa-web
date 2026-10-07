<?php

namespace Cesa\Waste\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WasteAccessToken extends Model
{
    public const PURPOSE_PROGRESS = 'progress';

    public const PURPOSE_APPROVAL = 'approval';

    protected $table = 'waste_access_tokens';

    protected $fillable = [
        'purpose',
        'tokenable_type',
        'tokenable_id',
        'token_hash',
    ];

    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }
}
