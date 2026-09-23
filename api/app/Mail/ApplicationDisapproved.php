<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The e-mail an owner gets when their application is disapproved.
 *
 * Its own Mailable, not the one-line `Mail::raw` every other notice uses,
 * because this is the one message the owner has to act on without opening
 * BizTrack first: it carries the officer's reason word for word and says what
 * they can do next. Guardrail §9.5 still holds — the reason is about the
 * owner's own filing, and nothing else about them is in it.
 */
class ApplicationDisapproved extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Application $application, public ?string $reason) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "BizTrack: {$this->application->tracking_id} was disapproved",
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.application-disapproved',
        );
    }
}
