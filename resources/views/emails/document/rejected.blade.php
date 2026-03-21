{{-- resources/views/emails/document/rejected.blade.php --}}
<x-mail::message>
# ❌ Changes Requested on Your Document

**"{{ $document->title }}"** requires revisions before it can be approved.

**Reviewer:** {{ $rejectedBy->name }}

@if($comment)
**Feedback:**
> {{ $comment }}
@endif

Please revise the document and resubmit when ready.

<x-mail::button :url="$actionUrl" color="red">
Revise Document
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
