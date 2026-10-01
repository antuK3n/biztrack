<?php

namespace App\Notifications;

use App\Models\EmailCode;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The six digits that confirm a password change.
 *
 * ── Why it reads as a warning and not as a receipt ──────────────────────────
 *
 * The person most likely to receive this mail without having asked for it is
 * somebody whose session has been taken at a shared counter — which is the
 * attack the code exists to stop. For them, the useful content is not the
 * digits at all: it is the sentence telling them somebody is trying, and what
 * to do about it. So that sentence is in the mail, above the fold, and the
 * subject says what is being attempted rather than "Your verification code".
 *
 * The code is never written to the notification table or to SMS. It is a
 * credential, and the whole point is that only the mailbox holds it.
 */
class PasswordChangeCode extends Notification
{
    use Queueable;

    /**
     * `public readonly` so a test can read what was sent.
     *
     * The alternative is asserting on the rendered mail body, which pins the
     * wording of a sentence that is meant to be rewritten — a test that fails
     * when the copy improves teaches people to stop improving the copy.
     */
    public function __construct(public readonly string $code) {}

    /** Mail only. See the class note. */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = EmailCode::TTL_MINUTES;

        return (new MailMessage)
            ->subject('Confirm your BizTrack password change')
            ->greeting('Someone is changing your BizTrack password')
            ->line("If that was you, enter this code to finish. It expires in {$minutes} minutes.")
            ->line("**{$this->code}**")
            /*
             * The instruction for the other reader — the one who did not do
             * this. "Ignore this email" is the usual line and it is wrong
             * here: whoever asked already holds a signed-in session, so doing
             * nothing leaves them holding it.
             */
            ->line(
                'If it was not you, do not enter the code. Someone else is signed in to your '
                .'account: sign in, change your password from a device you trust, and message '
                .'the City BPLO.'
            )
            ->salutation('— BizTrack, City of Malabon');
    }
}
