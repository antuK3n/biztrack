<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * The email a new account actually receives (checklist item #61).
 *
 * Until this existed, `register()` mailed nothing and `resendVerification()`
 * answered `{"message": "Verification email sent."}` having sent nothing at
 * all. A tester pressing "resend" got a green success and an empty inbox —
 * worse than an unbuilt feature, because an unbuilt one does not lie.
 *
 * Extends Laravel's own VerifyEmail so the framework stays the source of truth
 * for the signature and the expiry; only the URL's shape and the copy are ours.
 */
class VerifyEmailAddress extends VerifyEmail
{
    /**
     * A signed, expiring link at the API — not at the SPA.
     *
     * The link has to be signed by the server that will honour it, so the
     * address in the email is `/api/v1/auth/email/verify/{id}/{hash}` and the
     * controller redirects the reader onward to the web app once the row is
     * marked. Pointing the email at the React page instead would mean the SPA
     * held the signature and re-posted it, which adds a hop, puts the signed
     * parameters in a browser history entry, and gains nothing.
     *
     * `id` and `hash` are Laravel's own convention: the id names the row and
     * the hash is sha1 of the address, so a link stops working the moment the
     * address it was issued for changes.
     */
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }

    /**
     * BizTrack's wording rather than Laravel's stock "Verify Email Address".
     *
     * Written for someone who has just signed up to file a business permit and
     * may never have heard the phrase "verify your email": it says what the
     * button does, how long the link lasts, and what to do if they did not ask
     * for it. No stacked restatement — the heading says it once.
     */
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = (int) config('auth.verification.expire', 60);
        $window = $minutes % 60 === 0
            ? ($minutes / 60).' hour'.($minutes === 60 ? '' : 's')
            : $minutes.' minutes';

        return (new MailMessage)
            ->subject('Confirm your email address for BizTrack')
            ->greeting('Welcome to BizTrack.')
            ->line('Confirm this address so the City of Malabon can reach you about your business permit applications.')
            ->action('Confirm email address', $url)
            ->line("This link works for {$window}. After that, sign in and ask for a new one from your profile.")
            ->line('If you did not create a BizTrack account, nothing happens — ignore this message and the account stays unconfirmed.')
            ->salutation('— City of Malabon Business Permits and Licensing Office');
    }
}
