<?php

namespace Cesa\Waste\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WasteEvidence extends Model
{
    use HasFactory;

    protected $table = 'waste_evidences';

    protected $fillable = ['event_id', 'path', 'original_name', 'mime_type', 'size', 'sha256'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(WasteEvent::class, 'event_id');
    }
}
