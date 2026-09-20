<?php

namespace Cesa\Rekrutmen\Services;

use App\Services\WhatsApp\WagHubEngineClient;

class WhatsAppEngineClient extends WagHubEngineClient
{
    public function baseUrl(): string
    {
        return $this->storedIntegration()?->url ? rtrim($this->storedIntegration()->url, '/').'/api/v2' : rtrim(trim((string) config('rekrutmen.notifications.whatsapp.engine_url')), '/');
    }

    protected function token(): string
    {
        return trim($this->storedIntegration()?->token ?? (string) config('rekrutmen.notifications.whatsapp.engine_token'));
    }

    protected function httpTimeout(): int
    {
        return (int) config('rekrutmen.notifications.whatsapp.http_timeout', 20);
    }

    protected function requiresToken(): bool
    {
        return ! $this->isLocalEngine();
    }

    public function isLocalEngine(): bool
    {
        $url = parse_url($this->baseUrl());

        return config('rekrutmen.notifications.whatsapp.engine_driver') === 'local'
            && is_array($url)
            && ($url['scheme'] ?? null) === 'http'
            && in_array($url['host'] ?? null, ['127.0.0.1', 'localhost', '[::1]'], true)
            && empty($url['path'])
            && ! isset($url['user'])
            && ! isset($url['pass'])
            && ! isset($url['query'])
            && ! isset($url['fragment']);
    }

    public function unavailableMessage(): string
    {
        return $this->isLocalEngine()
            ? 'Engine WhatsApp belum siap. Pastikan Node.js terpasang, lalu jalankan php artisan rekrutmen:whatsapp-engine.'
            : 'WAG Hub belum siap. Periksa WAG_URL, WAG_TOKEN, dan layanan WhatsApp di WAG Hub.';
    }
}
