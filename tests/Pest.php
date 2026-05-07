<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Passkeys\Passkey;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a Laravel passkey record suitable for feature tests.
 *
 * @param  array<string, mixed>  $attributes
 */
function createTestPasskeyFor(User $user, array $attributes = []): Passkey
{
    $credentialId = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

    return $user->passkeys()->create([
        'name' => 'MacBook Pro',
        'credential_id' => $credentialId,
        'credential' => [
            'publicKeyCredentialId' => $credentialId,
            'type' => 'public-key',
            'transports' => [],
            'attestationType' => 'none',
            'trustPath' => [],
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'credentialPublicKey' => rtrim(strtr(base64_encode(random_bytes(77)), '+/', '-_'), '='),
            'userHandle' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            'counter' => 0,
        ],
        ...$attributes,
    ]);
}

/**
 * Build a syntactically valid passkey assertion payload for mocked verification tests.
 *
 * @return array<string, mixed>
 */
function fakePasskeyAssertionPayload(): array
{
    $rawId = random_bytes(16);
    $credentialId = rtrim(strtr(base64_encode($rawId), '+/', '-_'), '=');
    $clientData = json_encode([
        'type' => 'webauthn.get',
        'challenge' => 'test',
        'origin' => config('app.url'),
    ], JSON_THROW_ON_ERROR);

    return [
        'id' => $credentialId,
        'rawId' => $credentialId,
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
            'authenticatorData' => rtrim(strtr(base64_encode(str_repeat("\0", 37)), '+/', '-_'), '='),
            'signature' => rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '='),
        ],
    ];
}
