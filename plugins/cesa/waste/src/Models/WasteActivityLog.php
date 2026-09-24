<?php

namespace Cesa\Waste\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WasteActivityLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'waste_activity_logs';

    protected $fillable = ['report_id', 'version_id', 'event', 'actor_type', 'actor_id', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(WasteReport::class, 'report_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WasteReportVersion::class, 'version_id');
    }
}
