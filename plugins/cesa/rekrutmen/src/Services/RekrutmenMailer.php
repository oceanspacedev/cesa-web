<?php

namespace Cesa\Rekrutmen\Services;

use Cesa\Rekrutmen\Models\MailSetting;
use Closure;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;

class RekrutmenMailer
{
    public const MAILER = 'rekrutmen';

    public function apply(?MailSetting $settings = null): void
    {
        $settings ??= MailSetting::current();

        if (! $settings->enabled) {
            return;
        }

        config([
            'mail.mailers.'.self::MAILER                      => $settings->toMailerConfig(),
            'rekrutmen.mail.job_application.mailer'           => self::MAILER,
            'rekrutmen.mail.job_application.from.address'     => $settings->from_address,
            'rekrutmen.mail.job_application.from.name'        => $settings->from_name,
            'rekrutmen.mail.job_application.reply_to.address' => $settings->reply_to_address,
            'rekrutmen.mail.job_application.reply_to.name'    => $settings->reply_to_name,
        ]);

        if (app()->bound('mail.manager')) {
            app('mail.manager')->purge(self::MAILER);
        }
    }

    public function mailerName(?MailSetting $settings = null): string
    {
        $settings ??= MailSetting::current();

        if ($settings->enabled) {
            return self::MAILER;
        }

        $mailer = config('rekrutmen.mail.job_application.mailer');

        if (is_string($mailer) && $mailer !== '') {
            return $mailer;
        }

        return (string) config('mail.default', 'log');
    }

    /**
     * @return array{address: ?string, name: ?string}
     */
    public function from(?MailSetting $settings = null): array
    {
        $settings ??= MailSetting::current();

        if ($settings->enabled) {
            return [
                'address' => filled($settings->from_address) ? (string) $settings->from_address : null,
                'name'    => filled($settings->from_name) ? (string) $settings->from_name : null,
            ];
        }

        $address = config('rekrutmen.mail.job_application.from.address');
        $name = config('rekrutmen.mail.job_application.from.name');

        return [
            'address' => is_string($address) && $address !== '' ? $address : null,
            'name'    => is_string($name) && $name !== '' ? $name : null,
        ];
    }

    /**
     * @return array{address: ?string, name: ?string}
     */
    public function replyTo(?MailSetting $settings = null): array
    {
        $settings ??= MailSetting::current();

        if ($settings->enabled) {
            return [
                'address' => filled($settings->reply_to_address) ? (string) $settings->reply_to_address : null,
                'name'    => filled($settings->reply_to_name) ? (string) $settings->reply_to_name : null,
            ];
        }

        $address = config('rekrutmen.mail.job_application.reply_to.address');
        $name = config('rekrutmen.mail.job_application.reply_to.name');

        return [
            'address' => is_string($address) && $address !== '' ? $address : null,
            'name'    => is_string($name) && $name !== '' ? $name : null,
        ];
    }

    public function configureMailMessage(MailMessage $message, ?MailSetting $settings = null): MailMessage
    {
        $this->apply($settings);

        $mailer = $this->mailerName($settings);

        if ($mailer !== '') {
            $message->mailer($mailer);
        }

        $from = $this->from($settings);

        if (is_string($from['address']) && $from['address'] !== '') {
            $message->from($from['address'], $from['name']);
        }

        $replyTo = $this->replyTo($settings);

        if (is_string($replyTo['address']) && $replyTo['address'] !== '') {
            $message->replyTo($replyTo['address'], $replyTo['name']);
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function send(string $view, array $data, Closure $callback, ?MailSetting $settings = null): void
    {
        $this->apply($settings);

        Mail::mailer($this->mailerName($settings))->send($view, $data, function ($message) use ($callback, $settings): void {
            $this->applyIdentity($message, $settings);
            $callback($message);
        });
    }

    public function sendTest(string $recipient, ?MailSetting $settings = null): void
    {
        $this->apply($settings);

        Mail::mailer($this->mailerName($settings))->raw(
            'Ini adalah email uji dari gateway rekrutmen CESA. Konfigurasi SMTP berfungsi dan hanya berlaku untuk modul rekrutmen.',
            function ($message) use ($recipient, $settings): void {
                $this->applyIdentity($message, $settings);
                $message->to($recipient)->subject('Tes Email Gateway Rekrutmen CESA');
            }
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function save(array $payload): MailSetting
    {
        $setting = MailSetting::query()->first() ?? new MailSetting;

        if (! array_key_exists('password', $payload) || ! filled($payload['password'])) {
            unset($payload['password']);
        }

        $setting->fill($payload);
        $setting->save();

        return $setting->fresh() ?? $setting;
    }

    protected function applyIdentity(mixed $message, ?MailSetting $settings = null): void
    {
        $from = $this->from($settings);

        if (is_string($from['address']) && $from['address'] !== '') {
            $message->from($from['address'], $from['name']);
        }

        $replyTo = $this->replyTo($settings);

        if (is_string($replyTo['address']) && $replyTo['address'] !== '') {
            $message->replyTo($replyTo['address'], $replyTo['name']);
        }
    }
}
