<?php

namespace Cesa\Rekrutmen\Models;

use Cesa\Rekrutmen\Enums\WhatsAppAccountStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsAppAccount extends Model
{
    use SoftDeletes;

    protected $table = 'rekrutmen_whatsapp_accounts';

    protected $fillable = [
        'name',
        'connection_request_key',
        'phone_number',
        'route_key',
        'endpoint',
        'api_key',
        'is_default',
        'is_active',
        'status',
        'last_checked_at',
        'last_error',
    ];

    protected $hidden = [
        'connection_request_key',
        'api_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default'      => 'boolean',
            'is_active'       => 'boolean',
            'status'          => WhatsAppAccountStatus::class,
            'api_key'         => 'encrypted',
            'last_checked_at' => 'datetime',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
            'deleted_at'      => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            if ($account->status === null) {
                $account->status = WhatsAppAccountStatus::Unknown;
            }
        });

        static::saved(function (self $account): void {
            if ($account->route_key !== $account->sessionId()) {
                $account->forceFill(['route_key' => $account->sessionId()])->saveQuietly();
            }

            if (! $account->is_default) {
                return;
            }

            static::query()
                ->whereKeyNot($account->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });

        static::deleted(function (self $account): void {
            if (! $account->is_default) {
                return;
            }

            $replacement = static::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

            if ($replacement) {
                $replacement->forceFill(['is_default' => true])->save();
            }
        });
    }

    public function sessionId(): string
    {
        return 'rekrutmen-'.(string) $this->getKey();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('status', WhatsAppAccountStatus::Connected);
    }

    public static function resolveForSend(?int $id = null): ?self
    {
        if ($id !== null) {
            return static::query()->active()->whereKey($id)->first();
        }

        return static::query()->connected()->where('is_default', true)->first()
            ?? static::query()->connected()->orderBy('id')->first()
            ?? static::query()->active()->where('is_default', true)->first()
            ?? static::query()->active()->orderBy('id')->first();
    }

    public function markConnected(?string $phone = null): void
    {
        if (! $this->exists) {
            return;
        }

        $this->forceFill([
            'status'          => WhatsAppAccountStatus::Connected,
            'phone_number'    => $phone ?: $this->phone_number,
            'last_checked_at' => now(),
            'last_error'      => null,
        ])->save();
    }

    public function markDisconnected(string $error): void
    {
        if (! $this->exists) {
            return;
        }

        $this->forceFill([
            'status'          => WhatsAppAccountStatus::Disconnected,
            'last_checked_at' => now(),
            'last_error'      => $error,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'phone_number'    => $this->phone_number,
            'session_id'      => $this->exists ? $this->sessionId() : null,
            'is_default'      => (bool) $this->is_default,
            'is_active'       => (bool) $this->is_active,
            'status'          => $this->status instanceof WhatsAppAccountStatus
                ? $this->status->value
                : (string) $this->status,
            'last_checked_at' => $this->last_checked_at?->toDateTimeString(),
            'last_error'      => $this->last_error,
            'updated_at'      => $this->updated_at?->toDateTimeString(),
        ];
    }
}
