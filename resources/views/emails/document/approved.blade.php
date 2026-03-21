{{-- resources/views/emails/document/approved.blade.php --}}
<x-mail::message>
# ✅ Your Document Has Been Approved

Great news! **"{{ $document->title }}"** has been approved by **{{ $approvedBy->name }}** and is now awaiting publication.

<x-mail::button :url="$actionUrl" color="green">
View Document
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
