<?php

namespace App\Mail;

use App\Models\Document;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Document $document,
        public readonly User $rejectedBy,
        public readonly ?string $comment = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "❌ Changes Requested: \"{$this->document->title}\"",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.document.rejected',
            with: [
                'document' => $this->document,
                'rejectedBy' => $this->rejectedBy,
                'comment' => $this->comment,
                'actionUrl' => url("/documents/{$this->document->id}"),
            ],
        );
    }
}
