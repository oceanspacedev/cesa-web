<?php

namespace Cesa\Rekrutmen\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class WhatsAppSetting extends Model
{
    protected $table = 'rekrutmen_whatsapp_settings';

    protected $fillable = [
        'enabled',
        'endpoint',
        'api_key',
        'timeout',
    ];

    protected $hidden = [
        'api_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled'    => 'boolean',
            'timeout'    => 'integer',
            'api_key'    => 'encrypted',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::fromEnv();
    }

    public static function fromEnv(): self
    {
        $setting = new static;
        $setting->fill(static::envDefaults());

        return $setting;
    }

    /**
     * @return array<string, mixed>
     */
    public static function envDefaults(): array
    {
        $config = config('rekrutmen.notifications.whatsapp', []);

        return [
            'enabled'  => (bool) Arr::get($config, 'enabled', true),
            'endpoint' => Arr::get($config, 'engine_url', 'http://127.0.0.1:3318'),
            'timeout'  => (int) (Arr::get($config, 'timeout') ?: 10),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'enabled'     => (bool) $this->enabled,
            'is_database' => $this->exists,
            'updated_at'  => $this->updated_at?->toDateTimeString(),
        ];
    }
}
