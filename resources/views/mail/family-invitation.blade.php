<x-mail::message>
# You've Been Invited

You've been invited to join **{{ $invitation->family->name }}** as a **{{ $invitation->role->label() }}**.

Click the button below to accept the invitation and create your account.

<x-mail::button :url="$acceptUrl">
Accept Invitation
</x-mail::button>

This invitation will expire on {{ $invitation->expires_at->toFormattedDateString() }}.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
