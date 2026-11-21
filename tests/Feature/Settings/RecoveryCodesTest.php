<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Fortify\Fortify;

test('unreadable recovery codes return recovery instructions without changing two factor enrollment', function (bool $foreignKey) {
    $encrypter = new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC');
    $ciphertext = $foreignKey ? $encrypter->encrypt('["old-code"]') : 'damaged-ciphertext';
    $user = User::factory()->create(['two_factor_recovery_codes' => $ciphertext]);
    $secret = $user->two_factor_secret;
    $confirmedAt = $user->two_factor_confirmed_at;
    Exceptions::fake();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('two-factor.recovery-codes'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recovery_codes')
        ->assertJsonPath('errors.recovery_codes.0', 'Your recovery codes could not be read. Contact support to restore access, or disable and re-enable two-factor authentication here to create a new set. Keep this session open until setup is complete.');

    $user->refresh();
    expect($user->two_factor_recovery_codes)->toBe($ciphertext)
        ->and($user->two_factor_secret)->toBe($secret)
        ->and($user->two_factor_confirmed_at)->toEqual($confirmedAt);
    Exceptions::assertNotReported(DecryptException::class);
})->with([true, false]);

test('valid recovery codes remain available', function () {
    $user = User::factory()->create();
    $codes = $user->recoveryCodes();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('two-factor.recovery-codes'))
        ->assertOk()
        ->assertExactJson($codes);
});

test('recovery codes encrypted with a configured previous key remain available', function () {
    $previousEncrypter = new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC');
    $user = User::factory()->create([
        'two_factor_recovery_codes' => $previousEncrypter->encrypt('["previous-code"]'),
    ]);
    $encrypter = app(Encrypter::class);
    $encrypter->previousKeys([$previousEncrypter->getKey()]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('two-factor.recovery-codes'))
        ->assertOk()
        ->assertExactJson(['previous-code']);
});

test('users without two factor enrollment receive no recovery codes', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('two-factor.recovery-codes'))
        ->assertOk()
        ->assertExactJson([]);
});

test('recovery code access still requires authentication', function () {
    $this->getJson(route('two-factor.recovery-codes'))->assertUnauthorized();
});

test('recovery code access still requires recent password confirmation', function () {
    $user = User::factory()->create(['two_factor_recovery_codes' => 'damaged-ciphertext']);

    $this->actingAs($user)
        ->getJson(route('two-factor.recovery-codes'))
        ->assertStatus(423);
});

test('authenticated users can explicitly replace unreadable enrollment through two factor setup', function () {
    $user = User::factory()->create([
        'two_factor_secret' => 'unreadable-secret',
        'two_factor_recovery_codes' => 'unreadable-codes',
    ]);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    $this->deleteJson(route('two-factor.disable'))->assertSuccessful();
    $this->postJson(route('two-factor.enable'))->assertSuccessful();
    $this->getJson(route('two-factor.recovery-codes'))
        ->assertOk()
        ->assertJsonCount(8);

    $user->refresh();
    expect(Fortify::currentEncrypter()->decrypt(stringValue($user->getAttributes(), 'two_factor_secret')))->toBeString()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

test('explicit recovery code regeneration still works', function () {
    $user = User::factory()->create();
    $originalCodes = $user->recoveryCodes();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    $this->postJson(route('two-factor.regenerate-recovery-codes'))->assertSuccessful();
    $this->getJson(route('two-factor.recovery-codes'))
        ->assertOk()
        ->assertJsonCount(8);

    expect($user->refresh()->recoveryCodes())->not->toBe($originalCodes);
});
