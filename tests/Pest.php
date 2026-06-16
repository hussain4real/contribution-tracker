<?php

declare(strict_types=1);

use App\Ai\Agents\FamilySubAgent;
use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Passkeys\Passkey;
use Mockery\MockInterface;
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

pest()->browser()->timeout(15_000);

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
 * @param  array<model-property<Passkey>, mixed>  $attributes
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

function memberCategoryValue(User $user): string
{
    $category = $user->category;

    if ($category === null) {
        throw new RuntimeException('Expected user to have a member category.');
    }

    return $category->value;
}

/**
 * Create a family fixture for browser tests.
 *
 * @param  array<model-property<Family>, mixed>  $attributes
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
 * @param  array<model-property<User>, mixed>  $attributes
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
 * @param  array<model-property<User>, mixed>  $attributes
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
 * @param  array<model-property<User>, mixed>  $attributes
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
 * @param  array<model-property<User>, mixed>  $attributes
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

function loginBrowserAs(User $user, ?string $expectedPath = null): PendingAwaitablePage
{
    Auth::logout();
    test()->flushSession();

    $family = $user->currentFamily ?? $user->family;
    $expectedPath ??= $family instanceof Family
        ? "/{$family->slug}/dashboard"
        : '/dashboard';

    $page = visit(route('login'));

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('@login-button')
        ->wait(0.5)
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

/**
 * Decode a JSON string returned by an AI tool.
 *
 * @return array<string, mixed>
 */
function decodeToolResult(string $json): array
{
    $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($result)) {
        throw new RuntimeException('AI tool result was not a JSON object.');
    }

    $items = [];

    foreach ($result as $key => $value) {
        if (is_string($key)) {
            $items[$key] = $value;
        }
    }

    return $items;
}

/**
 * @param  array<string, mixed>  $payload
 */
function encodeJsonPayload(array $payload): string
{
    return json_encode($payload, JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, mixed>
 */
function decodeJsonObject(string $json): array
{
    $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($result)) {
        throw new RuntimeException('Expected decoded JSON to be an object.');
    }

    $items = [];

    foreach ($result as $key => $value) {
        if (is_string($key)) {
            $items[$key] = $value;
        }
    }

    return $items;
}

function responseContent(Symfony\Component\HttpFoundation\Response $response): string
{
    $content = $response->getContent();

    if (! is_string($content)) {
        throw new RuntimeException('Expected response content to be a string.');
    }

    return $content;
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function inertiaPage(TestResponse $response): array
{
    return AssertableInertia::fromTestResponse($response)->toArray();
}

/**
 * @param  array<int|string, mixed>  $items
 */
function stringValue(array $items, int|string $key): string
{
    $value = $items[$key] ?? null;

    if (! is_string($value)) {
        throw new RuntimeException("Expected array key [{$key}] to contain a string.");
    }

    return $value;
}

/**
 * @param  array<int|string, mixed>  $items
 */
function intValue(array $items, int|string $key): int
{
    $value = $items[$key] ?? null;

    if (! is_int($value)) {
        throw new RuntimeException("Expected array key [{$key}] to contain an integer.");
    }

    return $value;
}

/**
 * @param  array<int|string, mixed>  $result
 * @return array<int|string, mixed>
 */
function resultArray(array $result, int|string $key): array
{
    $value = $result[$key] ?? null;

    if (! is_array($value)) {
        throw new RuntimeException("Expected result key [{$key}] to contain an array.");
    }

    return $value;
}

function arrayLikeHasKey(mixed $items, int|string $key): bool
{
    if ($items instanceof Collection) {
        return $items->has($key);
    }

    if (is_array($items)) {
        return array_key_exists($key, $items);
    }

    return false;
}

/**
 * @return array<int|string, mixed>
 */
function arrayLikeItems(mixed $items): array
{
    if ($items instanceof Collection) {
        return $items->all();
    }

    if (is_array($items)) {
        return $items;
    }

    return [];
}

/**
 * @param  array<int|string, mixed>  $items
 * @return array<int|string, mixed>
 */
function firstArrayWhere(array $items, string $key, mixed $value): array
{
    foreach ($items as $item) {
        if (! is_array($item)) {
            continue;
        }

        if (($item[$key] ?? null) === $value) {
            return $item;
        }
    }

    throw new RuntimeException("Expected to find an array item where [{$key}] matches.");
}

/**
 * @param  array<int|string, mixed>  $result
 * @return array<int|string, mixed>
 */
function firstResultArray(array $result, int|string $key): array
{
    $items = resultArray($result, $key);
    $first = $items[0] ?? null;

    if (! is_array($first)) {
        throw new RuntimeException("Expected result key [{$key}] to contain a first array item.");
    }

    return $first;
}

/**
 * @template T of object
 *
 * @param  class-string<T>  $class
 * @return T&MockInterface
 */
function typedMock(string $class): object
{
    $mock = Mockery::mock($class);

    if (! $mock instanceof $class) {
        throw new RuntimeException("Mock for [{$class}] was not an instance of the requested class.");
    }

    return $mock;
}

/**
 * @template T of object
 *
 * @param  class-string<T>  $parent
 * @return class-string<T>
 */
function classStringOf(string $class, string $parent): string
{
    if (! is_a($class, $parent, true)) {
        throw new RuntimeException("[{$class}] is not a [{$parent}].");
    }

    return $class;
}

/**
 * @param  class-string<FamilySubAgent>  $class
 */
function makeFamilySubAgent(string $class, User $user): FamilySubAgent
{
    return new $class($user);
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

    if (! is_array($result)) {
        throw new RuntimeException('Browser script did not return an array.');
    }

    $fields = $result['fields'] ?? [];
    $fieldDescriptions = [];

    if (is_array($fields)) {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $tag = is_scalar($field['tag'] ?? null) ? (string) $field['tag'] : '';
            $id = is_scalar($field['id'] ?? null) ? (string) $field['id'] : '';
            $name = is_scalar($field['name'] ?? null) ? (string) $field['name'] : '';

            $fieldDescriptions[] = "{$tag}#{$id}[name={$name}]";
        }
    }

    $availableFields = implode(', ', $fieldDescriptions);
    $filled = ($result['filled'] ?? false) === true;

    expect($filled)->toBeTrue("Expected browser field [{$selector}] to exist. Available fields: {$availableFields}");

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
