<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;

class WhatsappOtp extends Model
{
    use MassPrunable;

    public const PURPOSE_LOGIN = 'login';

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'    => 'datetime',
            'verified_at'   => 'datetime',
            'attempt_count' => 'integer',
        ];
    }

    /**
     * Baris yang sudah mati (kedaluwarsa atau terverifikasi) lebih dari sehari dibuang
     * agar tabel tidak tumbuh tanpa batas.
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where(function (Builder $query): void {
                $query->where('expires_at', '<', now()->subDay())
                    ->orWhere('verified_at', '<', now()->subDay());
            });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
