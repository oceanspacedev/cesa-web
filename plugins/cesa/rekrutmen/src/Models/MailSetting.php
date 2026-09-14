<?php

namespace Cesa\Rekrutmen\Models;

use Illuminate\Database\Eloquent\Model;

class MailSetting extends Model
{
    protected $table = 'rekrutmen_mail_settings';

    protected $fillable = [
        'enabled',
        'transport',
        'host',
        'port',
        'encryption',
        'username',
        'password',
        'timeout',
        'from_address',
        'from_name',
        'reply_to_address',
        'reply_to_name',
    ];

    protected $hidden = [
        'password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled'    => 'boolean',
            'port'       => 'integer',
            'timeout'    => 'integer',
            'password'   => 'encrypted',
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
        $encryption = config('mail.mailers.rekrutmen_job_application.encryption', config('mail.mailers.smtp.encryption'));

        return [
            'enabled'          => false,
            'transport'        => (string) (config('mail.mailers.rekrutmen_job_application.transport') ?: 'smtp'),
            'host'             => config('mail.mailers.rekrutmen_job_application.host', config('mail.mailers.smtp.host')),
            'port'             => (int) (config('mail.mailers.rekrutmen_job_application.port') ?: config('mail.mailers.smtp.port') ?: 587),
            'encryption'       => is_string($encryption) && $encryption !== '' ? $encryption : 'tls',
            'username'         => config('mail.mailers.rekrutmen_job_application.username', config('mail.mailers.smtp.username')),
            'password'         => config('mail.mailers.rekrutmen_job_application.password', config('mail.mailers.smtp.password')),
            'timeout'          => 30,
            'from_address'     => config('rekrutmen.mail.job_application.from.address', config('mail.from.address')),
            'from_name'        => config('rekrutmen.mail.job_application.from.name', config('mail.from.name')),
            'reply_to_address' => config('rekrutmen.mail.job_application.reply_to.address'),
            'reply_to_name'    => config('rekrutmen.mail.job_application.reply_to.name'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toMailerConfig(): array
    {
        $transport = filled($this->transport) ? (string) $this->transport : 'smtp';
        $encryption = $this->encryption;

        if ($encryption === 'none' || $encryption === '') {
            $encryption = null;
        }

        return [
            'transport'    => $transport,
            'host'         => $this->host,
            'port'         => $this->port ?: 587,
            'encryption'   => $encryption,
            'username'     => $this->username,
            'password'     => $this->password,
            'timeout'      => $this->timeout ?: null,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
            'path'         => config('mail.mailers.sendmail.path'),
            'channel'      => config('mail.mailers.log.channel'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'enabled'          => (bool) $this->enabled,
            'transport'        => $this->transport ?: 'smtp',
            'host'             => $this->host,
            'port'             => $this->port,
            'encryption'       => $this->encryption ?: 'tls',
            'username'         => $this->username,
            'has_password'     => filled($this->password),
            'timeout'          => $this->timeout,
            'from_address'     => $this->from_address,
            'from_name'        => $this->from_name,
            'reply_to_address' => $this->reply_to_address,
            'reply_to_name'    => $this->reply_to_name,
            'is_database'      => $this->exists,
            'updated_at'       => $this->updated_at?->toDateTimeString(),
        ];
    }
}
