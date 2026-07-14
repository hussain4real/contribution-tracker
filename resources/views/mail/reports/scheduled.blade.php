<x-mail::message>
# {{ $artifact->type->label() }}

Your scheduled report for **{{ $artifact->family->name }}** is attached.

The authenticated link below expires in seven days.

<x-mail::button :url="$downloadUrl">
Download report
</x-mail::button>

Regards,<br>
{{ config('app.name') }}
</x-mail::message>
