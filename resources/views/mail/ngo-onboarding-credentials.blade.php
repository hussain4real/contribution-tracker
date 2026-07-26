<x-mail::message>
# Your Login Details

Hello {{ $user->name }},

Your account for **{{ $family->name }}** has been created with the **{{ $user->role->label() }}** role.

Use these temporary login details:

<x-mail::panel>
Login email: **{{ $user->email }}**<br>
Temporary password: **{{ $temporaryPassword }}**
</x-mail::panel>

You will be asked to change this temporary password before using the platform.

<x-mail::button :url="$loginUrl">
Log In
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
