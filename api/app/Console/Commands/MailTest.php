<?php

namespace App\Console\Commands;

use App\Jobs\SendOwnerUpdateEmail;
use App\Mail\OwnerUpdate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Send one owner-update e-mail through the configured mailer, now, and say
 * whether it went.
 *
 * For the moment the Brevo credentials go into .env: it answers "is the SMTP
 * login right and is the sender verified" in one command, without filing an
 * application to provoke a notice. It sends directly rather than through the
 * queue on purpose — a test that also needs a running worker cannot tell a
 * wrong password from a worker nobody started. docs/email-setup.md covers
 * testing the queued path separately.
 *
 * The message is the real template with sample content, so what arrives is
 * what an owner will see.
 */
class MailTest extends Command
{
    protected $signature = 'biztrack:mail-test {to : The address to send the test e-mail to}';

    protected $description = 'Send one test e-mail through the configured mailer and report success or failure';

    public function handle(): int
    {
        $to = (string) $this->argument('to');
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("“{$to}” is not an e-mail address.");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $this->line("Mailer: {$mailer}"
            .($mailer === 'smtp' ? ' ('.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').')' : ''));
        $this->line('From:   '.config('mail.from.name').' <'.config('mail.from.address').'>');
        // Laravel's placeholder, i.e. MAIL_FROM_ADDRESS was never set. Brevo
        // refuses a sender it has not verified, so say it before the relay does.
        if (config('mail.from.address') === 'hello@example.com') {
            $this->warn('MAIL_FROM_ADDRESS is not set. Use the sender address you verified in Brevo.');
        }

        try {
            Mail::to($to)->send(new OwnerUpdate(
                recipientName: null,
                title: 'Test e-mail from BizTrack',
                body: 'If you can read this, BizTrack can send e-mail. Business owners will get a message '
                    .'like this one each time something changes on their application or permit.',
                url: SendOwnerUpdateEmail::siteUrl('/dashboard'),
                reference: 'BIZ-0000-00000',
                businessName: 'Sample Business',
            ));
        } catch (Throwable $e) {
            $this->error('Not sent: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sent to {$to}.");
        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("The {$mailer} mailer does not deliver anything. Set MAIL_MAILER=smtp to send for real.");
        }

        return self::SUCCESS;
    }
}
