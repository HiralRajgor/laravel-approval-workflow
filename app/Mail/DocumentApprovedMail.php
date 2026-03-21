<?php

namespace App\Mail;

use App\Models\Document;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Document $document,
        public readonly User $approvedBy,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "✅ Approved: \"{$this->document->title}\"",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.document.approved',
            with: [
                'document' => $this->document,
                'approvedBy' => $this->approvedBy,
                'actionUrl' => url("/documents/{$this->document->id}"),
            ],
        );
    }
}
