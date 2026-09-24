<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The one e-mail a business owner gets about their filing, whatever happened.
 *
 * One template for every event, on purpose: the in-app notice already carries
 * the words for each case, and a template per event would be thirty places for
 * the e-mail and the notification list to drift apart. What varies is only the
 * title, the body and where the button goes.
 *
 * HTML plus a plain-text part. The text part is not a courtesy: some mail
 * clients and most spam filters score an HTML-only message down, and it is what
 * a reader on a basic phone mail app actually sees.
 *
 * Not ShouldQueue itself — SendOwnerUpdateEmail is the queued unit, and sends
 * this synchronously inside the worker. Queuing both would put two retry
 * policies on one delivery.
 *
 * Guardrail §9.5 still holds: nothing here is beyond what the in-app notice
 * already shows the same person — their own filing's reference, their own
 * business's name, and the office's words about it.
 */
class OwnerUpdate extends Mailable
{
    public function __construct(
        public ?string $recipientName,
        public string $title,
        public string $body,
        public string $url,
        public ?string $reference = null,
        public ?string $businessName = null,
        public bool $isDisapproval = false,
    ) {}

    public function envelope(): Envelope
    {
        // The reference in the subject lets an owner with several filings tell
        // them apart from the inbox list without opening each one.
        return new Envelope(
            subject: 'BizTrack: '.$this->title.($this->reference ? " ({$this->reference})" : ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.owner-update',
            text: 'mail.owner-update-text',
        );
    }
}
