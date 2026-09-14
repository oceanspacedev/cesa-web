<?php

namespace Cesa\Rekrutmen\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Webkul\Security\Traits\HasNullableCreator;
use Webkul\Support\Models\Company;

class Division extends Model
{
    use HasFactory, HasNullableCreator, SoftDeletes;

    protected $table = 'rekrutmen_divisions';

    protected $fillable = [
        'company_id',
        'name',
        'is_active',
        'creator_id',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'is_active'  => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id')->withTrashed();
    }

    public function nameWithCompany(): string
    {
        $companyName = trim((string) ($this->company?->name ?? ''));

        if ($companyName === '') {
            return (string) $this->name;
        }

        return "{$this->name} — {$companyName}";
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
