{{-- resources/views/emails/document/submitted.blade.php --}}
<x-mail::message>
# Document Submitted for Review

**{{ $submittedBy->name }}** has submitted a document that requires your attention.

| Field  | Value |
|--------|-------|
| Title  | {{ $document->title }} |
| Author | {{ $submittedBy->name }} |
| Submitted | {{ now()->format('d M Y, H:i') }} |

<x-mail::button :url="$actionUrl" color="blue">
Review Document
</x-mail::button>

Please review and take action at your earliest convenience.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
