<?php

namespace Cesa\Rekrutmen\Concerns;

use Cesa\Rekrutmen\Services\RekrutmenMailer;
use Illuminate\Notifications\Messages\MailMessage;

trait ConfiguresRekrutmenMail
{
    protected function configureRekrutmenMail(MailMessage $message): MailMessage
    {
        return app(RekrutmenMailer::class)->configureMailMessage($message);
    }
}
