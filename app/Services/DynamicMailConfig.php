<?php

namespace App\Services;

use App\Models\MailSetting;
use Illuminate\Support\Facades\Mail;

class DynamicMailConfig
{
    public function apply(): ?MailSetting
    {
        $settings = MailSetting::query()
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $settings) {
            return null;
        }

        config([
            'mail.default' => 'smtp',

            'mail.mailers.smtp.transport' => 'smtp',

            'mail.mailers.smtp.host' => $settings->host,

            'mail.mailers.smtp.port' => (int) $settings->port,

            'mail.mailers.smtp.username' => $settings->username,

            'mail.mailers.smtp.password' => $settings->password,

            'mail.mailers.smtp.timeout' => null,

            'mail.mailers.smtp.local_domain' => (config('mail.mailers.smtp.local_domain')
                ?? parse_url(
                    (string) config('app.url'),
                    PHP_URL_HOST
                )),

            'mail.from.address' => $settings->from_address,

            'mail.from.name' => $settings->from_name,
        ]);

        /*
         * Laravel/Symfony SMTP scheme.
         *
         * Port 465 = implicit TLS / SMTPS
         * Port 587/2525 = SMTP + STARTTLS
         */
        if (
            $settings->encryption === 'ssl'
        ) {
            config([
                'mail.mailers.smtp.scheme' => 'smtps',
            ]);
        } else {
            /*
             * tls / none:
             *
             * SMTP transport can negotiate
             * STARTTLS automatically when
             * supported by the server.
             */
            config([
                'mail.mailers.smtp.scheme' => null,
            ]);
        }

        /*
         * Important:
         * Laravel may already have instantiated
         * the SMTP mailer using the old config.
         */
        Mail::purge('smtp');

        return $settings;
    }
}
