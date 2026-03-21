<?php

namespace App\Mail;

use App\Models\Document;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to reviewers when an author submits a document.
 */
class DocumentSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Document $document,
        public readonly User $submittedBy,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Action Required] Document submitted for review: \"{$this->document->title}\"",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.document.submitted',
            with: [
                'document' => $this->document,
                'submittedBy' => $this->submittedBy,
                'actionUrl' => url("/documents/{$this->document->id}"),
            ],
        );
    }
}
