<?php

namespace App\Mail;

use App\Models\EmailCode;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A six-digit code: the sign-in step, or confirming the address after sign-up.
 *
 * One template for both, built the way OwnerUpdate is (tables, inline styles,
 * an HTML and a plain-text part), because the two messages differ by a heading
 * and a sentence. Sent inline by EmailCodes::send(), never queued — see there.
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
            subject: $this->isLogin() ? 'Your BizTrack sign-in code' : 'Confirm your email address for BizTrack',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.one-time-code',
            text: 'mail.one-time-code-text',
            with: [
                'heading' => $this->isLogin() ? 'Your sign-in code' : 'Confirm your email address',
                'lead' => $this->isLogin()
                    ? 'Someone, hopefully you, entered your password on BizTrack. Type this code on the sign-in page to finish signing in.'
                    : 'Type this code in BizTrack to confirm this address. You need a confirmed address before you can file an application.',
                'warning' => $this->isLogin()
                    ? 'If you did not just sign in, do not share this code with anyone, and change your password in Settings.'
                    : 'If you did not create a BizTrack account, ignore this message. Nothing happens without the code.',
            ],
        );
    }
}
