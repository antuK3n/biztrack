<?php

namespace App\Mail;

use App\Models\EmailCode;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A six-digit code: the sign-in step, confirming the address after sign-up, or
 * changing the password from Settings.
 *
 * One template for all three, built the way OwnerUpdate is (tables, inline
 * styles, an HTML and a plain-text part), because the messages differ by a
 * heading and a sentence. Sent inline by EmailCodes::send(), never queued — see there.
 *
 * The code is NOT in the subject line. Putting it there is convenient on a
 * phone and puts it on the lock screen for anyone holding the phone; a City
 * Hall sign-in is worth the extra tap.
 */
class OneTimeCode extends Mailable
{
    public function __construct(
        public ?string $recipientName,
        public string $code,
        public string $purpose,
        public int $minutes,
    ) {}

    public function isLogin(): bool
    {
        return $this->purpose === EmailCode::LOGIN;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: match ($this->purpose) {
                EmailCode::LOGIN => 'Your BizTrack sign-in code',
                EmailCode::PASSWORD => 'Your code to change your BizTrack password',
                default => 'Confirm your email address for BizTrack',
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.one-time-code',
            text: 'mail.one-time-code-text',
            with: match ($this->purpose) {
                EmailCode::LOGIN => [
                    'heading' => 'Your sign-in code',
                    'lead' => 'Someone, hopefully you, entered your password on BizTrack. Type this code on the sign-in page to finish signing in.',
                    'warning' => 'If you did not just sign in, do not share this code with anyone, and change your password in Settings.',
                ],
                /*
                 * Whoever asked for this already had a signed-in session AND the
                 * current password, so the warning cannot say "ignore it":
                 * someone else holding both is the case this code exists for.
                 */
                EmailCode::PASSWORD => [
                    'heading' => 'Your code to change your password',
                    'lead' => 'Someone, hopefully you, asked to change your BizTrack password. Type this code in Settings to finish.',
                    'warning' => 'If this was not you, do not share this code. Someone else knows your password: contact the City BPLO.',
                ],
                default => [
                    'heading' => 'Confirm your email address',
                    'lead' => 'Type this code in BizTrack to confirm this address. You need a confirmed address before you can file an application.',
                    'warning' => 'If you did not create a BizTrack account, ignore this message. Nothing happens without the code.',
                ],
            },
        );
    }
}
