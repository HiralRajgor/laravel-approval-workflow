{{-- resources/views/emails/document/published.blade.php --}}
<x-mail::message>
# 🚀 Your Document Is Now Live

**"{{ $document->title }}"** has been published by **{{ $publishedBy->name }}**.

<x-mail::button :url="$actionUrl" color="green">
View Published Document
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
