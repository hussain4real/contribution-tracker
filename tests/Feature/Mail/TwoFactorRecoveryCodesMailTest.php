<?php

use App\Mail\TwoFactorRecoveryCodesMail;
use App\Models\User;

test('recovery codes mail contains all recovery codes', function () {
    $user = User::factory()->create();
    $recoveryCodes = $user->recoveryCodes();

    $mailable = new TwoFactorRecoveryCodesMail($user, $recoveryCodes);

    $mailable->assertSeeInHtml(e($user->name), escape: false);

    foreach ($recoveryCodes as $code) {
        $mailable->assertSeeInHtml($code);
    }
});

test('recovery codes mail has correct subject', function () {
    $user = User::factory()->create();

    $mailable = new TwoFactorRecoveryCodesMail($user, $user->recoveryCodes());

    $mailable->assertHasSubject('Your Two-Factor Recovery Codes');
});

test('recovery codes mail is sent to admin during seeding', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'test-admin@family.test',
    ]);
    $recoveryCodes = $user->recoveryCodes();

    Mail::to($user)->send(new TwoFactorRecoveryCodesMail($user, $recoveryCodes));

    Mail::assertSent(TwoFactorRecoveryCodesMail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});
