<?php

use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Passkeys\Passkey;
use Pest\Browser\Api\PendingAwaitablePage;
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
 * Create a family fixture for browser tests.
 *
 * @param  array<string, mixed>  $attributes
 */
function createBrowserFamily(array $attributes = []): Family
{
    return Family::factory()->create([
        'name' => 'Browser Family',
        ...$attributes,
    ]);
}

/**
 * Create a two-factor-free family admin for browser tests.
 *
 * @param  array<string, mixed>  $attributes
 */
function createBrowserAdmin(?Family $family = null, array $attributes = []): User
{
    $family ??= createBrowserFamily();

    return User::factory()
        ->withoutTwoFactor()
        ->admin()
        ->create([
            'family_id' => $family->id,
            'password' => bcrypt('password'),
            ...$attributes,
        ]);
}

/**
 * Create a two-factor-free financial secretary for browser tests.
 *
 * @param  array<string, mixed>  $attributes
 */
function createBrowserFinancialSecretary(?Family $family = null, array $attributes = []): User
{
    $family ??= createBrowserFamily();

    return User::factory()
        ->withoutTwoFactor()
        ->financialSecretary()
        ->create([
            'family_id' => $family->id,
            'password' => bcrypt('password'),
            ...$attributes,
        ]);
}

/**
 * Create a two-factor-free family member for browser tests.
 *
 * @param  array<string, mixed>  $attributes
 */
function createBrowserMember(?Family $family = null, array $attributes = []): User
{
    $family ??= createBrowserFamily();

    return User::factory()
        ->withoutTwoFactor()
        ->member()
        ->employed()
        ->create([
            'family_id' => $family->id,
            'password' => bcrypt('password'),
            ...$attributes,
        ]);
}

/**
 * Create a two-factor-free platform super admin for browser tests.
 *
 * @param  array<string, mixed>  $attributes
 */
function createBrowserSuperAdmin(?Family $family = null, array $attributes = []): User
{
    $family ??= createBrowserFamily();

    return User::factory()
        ->withoutTwoFactor()
        ->admin()
        ->superAdmin()
        ->create([
            'family_id' => $family->id,
            'password' => bcrypt('password'),
            ...$attributes,
        ]);
}

function loginBrowserAs(User $user, string $expectedPath = '/dashboard'): PendingAwaitablePage
{
    $page = visit(route('login'));

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('@login-button')
        ->assertPathIs($expectedPath)
        ->assertNoJavaScriptErrors();

    return $page;
}

function assertBrowserSmoke(PendingAwaitablePage $page, string $text): PendingAwaitablePage
{
    $page->assertSee($text)
        ->assertNoSmoke();

    return $page;
}

function navigateAndAssertBrowserSmoke(PendingAwaitablePage $page, string $url, string $text): PendingAwaitablePage
{
    $page->navigate($url);

    return assertBrowserSmoke($page, $text);
}

function fillBrowserFieldWithoutChange(PendingAwaitablePage $page, string $selector, string $value): PendingAwaitablePage
{
    $encodedSelector = json_encode($selector, JSON_THROW_ON_ERROR);
    $encodedValue = json_encode($value, JSON_THROW_ON_ERROR);

    $result = $page->script(<<<JS
        () => {
            const field = document.querySelector({$encodedSelector});
            const fields = Array.from(document.querySelectorAll('input, textarea, select')).map((element) => ({
                id: element.id,
                name: element.getAttribute('name'),
                tag: element.tagName.toLowerCase(),
            }));

            if (!field || !('value' in field)) {
                return { filled: false, fields };
            }

            field.value = {$encodedValue};
            field.dispatchEvent(new Event('input', { bubbles: true }));

            return { filled: true, fields };
        }
    JS);

    $availableFields = collect($result['fields'])
        ->map(fn (array $field): string => "{$field['tag']}#{$field['id']}[name={$field['name']}]")
        ->implode(', ');

    expect($result['filled'])->toBeTrue("Expected browser field [{$selector}] to exist. Available fields: {$availableFields}");

    return $page;
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
