<x-mail::message>
# Two-Factor Recovery Codes

Hello {{ $user->name }},

Your two-factor authentication has been set up. Store these recovery codes in a secure location. Each code can only be used once.

<x-mail::table>
| # | Recovery Code |
|---|---------------|
@foreach ($recoveryCodes as $index => $code)
| {{ $index + 1 }} | `{{ $code }}` |
@endforeach
</x-mail::table>

<x-mail::panel>
**Important:** If you lose access to your authenticator app, you can use one of these codes to sign in. Keep them safe!
</x-mail::panel>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
