<?php

namespace Cesa\Rekrutmen\Services;

use Illuminate\Support\Facades\Crypt;
use Spatie\LaravelSettings\Models\SettingsProperty;
use Throwable;

class AiSettingsService
{
    /**
     * @return array{provider: string, automatic: bool, base_url: string, model: string, api_key: string, is_database: bool, has_env: bool, updated_at: ?string}
     */
    public function current(): array
    {
        $properties = collect();

        try {
            $properties = SettingsProperty::query()
                ->where('group', 'rekrutmen')
                ->whereIn('name', ['openai_compatible_settings', 'openai_compatible_api_key'])
                ->get()->keyBy('name');
        } catch (Throwable) {
        }

        $setting = $properties->get('openai_compatible_settings');
        $stored = json_decode($setting?->payload ?? 'null', true);
        $stored = is_array($stored) ? $stored : [];
        $environmentKey = trim((string) config('services.openai_compatible.api_key'));
        $apiKey = $environmentKey;

        if (array_key_exists('api_key', $stored)) {
            $apiKey = '';
            if (is_string($stored['api_key']) && $stored['api_key'] !== '') {
                try {
                    $apiKey = Crypt::decryptString($stored['api_key']);
                } catch (Throwable) {
                }
            }
        } else {
            $legacyKey = json_decode($properties->get('openai_compatible_api_key')?->payload ?? 'null', true);
            if (is_string($legacyKey) && trim($legacyKey) !== '') {
                $apiKey = trim($legacyKey);
            }
        }

        return [
            'provider'    => 'openai_compatible',
            'automatic'   => (bool) ($stored['automatic'] ?? config('services.openai_compatible.automatic', true)),
            'base_url'    => rtrim((string) ($stored['base_url'] ?? config('services.openai_compatible.base_url')), '/'),
            'model'       => (string) ($stored['model'] ?? config('services.openai_compatible.model')),
            'api_key'     => $apiKey,
            'is_database' => $properties->isNotEmpty(),
            'has_env'     => $environmentKey !== '',
            'updated_at'  => $setting?->updated_at?->toDateTimeString(),
        ];
    }

    public function configured(): bool
    {
        $settings = $this->current();

        return filled($settings['api_key']) && filled($settings['base_url']) && filled($settings['model']);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicSettings(): array
    {
        $settings = $this->current();
        $settings['has_api_key'] = $settings['api_key'] !== '';
        unset($settings['api_key']);

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): void
    {
        $current = $this->current();
        $apiKey = trim((string) ($data['api_key'] ?? '')) ?: $current['api_key'];
        if ($data['clear_api_key'] ?? false) {
            $apiKey = '';
        }

        SettingsProperty::query()->updateOrCreate(
            ['group' => 'rekrutmen', 'name' => 'openai_compatible_settings'],
            [
                'payload' => json_encode([
                    'automatic' => (bool) ($data['automatic'] ?? $current['automatic']),
                    'base_url'  => rtrim((string) ($data['base_url'] ?? $current['base_url']), '/'),
                    'model'     => trim((string) ($data['model'] ?? $current['model'])),
                    'api_key'   => $apiKey !== '' ? Crypt::encryptString($apiKey) : null,
                ], JSON_THROW_ON_ERROR),
                'locked' => false,
            ],
        );

        SettingsProperty::query()->where('group', 'rekrutmen')->where('name', 'openai_compatible_api_key')->delete();
    }
}
