<?php

declare(strict_types=1);

use App\Mail\TwoFactorRecoveryCodesMail;
use App\Models\User;

/**
 * @return list<string>
 */
function recoveryCodesFor(User $user): array
{
    return array_values(array_filter($user->recoveryCodes(), is_string(...)));
}

test('recovery codes mail contains all recovery codes', function () {
    $user = User::factory()->create([
        'name' => "Johnson O'Reilly",
    ]);
    $recoveryCodes = recoveryCodesFor($user);

    $mailable = new TwoFactorRecoveryCodesMail($user, $recoveryCodes);

    $mailable->assertSeeInHtml($user->name, false);

    foreach ($recoveryCodes as $code) {
        $mailable->assertSeeInHtml($code);
    }
});

test('recovery codes mail has correct subject', function () {
    $user = User::factory()->create();

    $mailable = new TwoFactorRecoveryCodesMail($user, recoveryCodesFor($user));

    $mailable->assertHasSubject('Your Two-Factor Recovery Codes');
});

test('recovery codes mail is sent to admin during seeding', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'test-admin@family.test',
    ]);
    $recoveryCodes = recoveryCodesFor($user);

    Mail::to($user)->send(new TwoFactorRecoveryCodesMail($user, $recoveryCodes));

    Mail::assertSent(TwoFactorRecoveryCodesMail::class, function (TwoFactorRecoveryCodesMail $mail) use ($user): bool {
        return $mail->hasTo($user->email);
    });
});
