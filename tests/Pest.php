<?php

declare(strict_types=1);

use App\Actions\InstallPaymentRiskModel;
use App\Ai\Agents\FamilySubAgent;
use App\Enums\MemberCategory;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentRiskArtifactValidator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
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

pest()
    ->tia()
    ->locally()
    ->baselined();

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
    $family = Family::factory()->create([
        'name' => 'Browser Family',
        ...$attributes,
    ]);

    foreach (MemberCategory::cases() as $sortOrder => $category) {
        FamilyCategory::query()->firstOrCreate([
            'family_id' => $family->id,
            'slug' => $category->value,
        ], [
            'name' => $category->label(),
            'monthly_amount' => $category->monthlyAmount(),
            'sort_order' => $sortOrder,
        ]);
    }

    return $family;
}

function browserFamilyCategoryId(Family $family, string $slug): int
{
    return FamilyCategory::query()
        ->where('family_id', $family->id)
        ->where('slug', $slug)
        ->firstOrFail()
        ->id;
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
    Session::flush();

    $family = $user->currentFamily ?? $user->family;
    $expectedPath ??= match (true) {
        $user->isSuperAdmin() => route('filament.platform.pages.dashboard', absolute: false),
        $family instanceof Family => "/{$family->slug}/dashboard",
        default => '/dashboard',
    };
    $redirectWait = $user->isSuperAdmin() ? 2 : 0.5;

    $page = visit(route('login'));

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('@login-button')
        ->wait($redirectWait)
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

/**
 * Build a valid, activation-eligible payment-risk artifact for PHP integration tests.
 *
 * @return array<string, mixed>
 */
function validPaymentRiskArtifact(): array
{
    $featureNames = PaymentRiskArtifactValidator::FEATURE_NAMES;
    $threshold = 0.55;
    $trainingStart = now()->subMonths(18)->startOfMonth();
    $trainingEnd = now()->subMonths(9)->startOfMonth();
    $validationStart = now()->subMonths(8)->startOfMonth();
    $validationEnd = now()->subMonths(6)->startOfMonth();
    $heldOutStart = now()->subMonths(5)->startOfMonth();
    $heldOutEnd = now()->subMonth()->startOfMonth();
    $datasetId = 'test-consented-anonymized-v1';

    return [
        'artifact_schema_version' => PaymentRiskArtifactValidator::SCHEMA_VERSION,
        'model_version' => 'test-model-v1',
        'generated_at' => now()->toIso8601String(),
        'training' => [
            'dataset_id' => $datasetId,
            'source_type' => 'consented_anonymized',
            'feature_contract_version' => PaymentRiskArtifactValidator::FEATURE_CONTRACT_VERSION,
            'feature_names' => $featureNames,
            'window' => [
                'start' => $trainingStart->toDateString(),
                'end' => $trainingEnd->toDateString(),
            ],
            'seed' => PaymentRiskArtifactValidator::RANDOM_SEED,
            'overdue_prevalence' => 0.4,
            'split' => [
                'policy' => PaymentRiskArtifactValidator::SPLIT_POLICY,
                'training' => [
                    'period_count' => 10,
                    'row_count' => 400,
                    'start' => $trainingStart->toDateString(),
                    'end' => $trainingEnd->toDateString(),
                ],
                'validation' => [
                    'period_count' => 3,
                    'row_count' => 100,
                    'start' => $validationStart->toDateString(),
                    'end' => $validationEnd->toDateString(),
                ],
                'held_out' => [
                    'period_count' => 5,
                    'row_count' => 100,
                    'start' => $heldOutStart->toDateString(),
                    'end' => $heldOutEnd->toDateString(),
                ],
            ],
        ],
        'data_quality' => [
            'dataset_id' => $datasetId,
            'source_type' => 'consented_anonymized',
            'valid_row_count' => 600,
            'distinct_member_count' => 60,
            'distinct_period_count' => 18,
            'on_time_count' => 360,
            'overdue_count' => 240,
            'gate_passed' => true,
            'gates' => [
                'minimum_rows' => true,
                'minimum_distinct_members' => true,
                'minimum_complete_periods' => true,
                'minimum_on_time_class' => true,
                'minimum_overdue_class' => true,
            ],
        ],
        'preprocessing' => [
            'type' => 'z_score_standardization',
            'formula' => 'standardized=(value-mean)/scale',
            'mean' => array_fill(0, count($featureNames), 0.0),
            'scale' => array_fill(0, count($featureNames), 1.0),
        ],
        'model' => [
            'type' => 'l2_logistic_regression',
            'penalty' => 'l2',
            'class_weight' => 'balanced',
            'solver' => 'liblinear',
            'positive_class' => 'overdue',
            'intercept' => -0.25,
            'coefficients' => [0.000001, -0.03, 0.01, 0.01, 0.03, -0.2, -0.25, -0.3, 0.2, 0.04, 0.04, 0.08, 0.000001, 0.12],
        ],
        'decision' => [
            'threshold' => $threshold,
            'positive_class' => 'overdue',
            'negative_class' => 'on_time',
            'risk_bands' => [
                [
                    'label' => 'routine_review',
                    'minimum_inclusive' => 0.0,
                    'maximum_exclusive' => $threshold,
                ],
                [
                    'label' => 'priority_review',
                    'minimum_inclusive' => $threshold,
                    'maximum_inclusive' => 1.0,
                ],
            ],
        ],
        'evaluation' => [
            'selection' => [
                'objective' => 'max_overdue_f1_subject_to_recall_at_least_0_70_tie_precision',
                'validation_threshold' => $threshold,
                'minimum_overdue_recall' => PaymentRiskArtifactValidator::MINIMUM_VALIDATION_RECALL,
            ],
            'validation' => [
                'recall_overdue' => 0.75,
            ],
            'activation_eligible' => true,
            'activation_policy_version' => PaymentRiskArtifactValidator::ACTIVATION_POLICY_VERSION,
            'activation_checks' => [
                'consented_anonymized_provenance' => true,
                'data_quality_gate_passed' => true,
                'validation_recall_at_least_0_70' => true,
                'held_out_f1_beats_both_baselines' => true,
                'held_out_balanced_accuracy_above_0_5' => true,
                'held_out_brier_beats_training_prevalence' => true,
            ],
            'held_out' => [
                'accuracy' => 0.72,
                'balanced_accuracy' => 0.7,
                'overdue_precision' => 0.7,
                'overdue_recall' => 0.75,
                'overdue_f1' => 0.72,
                'pr_auc' => 0.74,
                'roc_auc' => 0.77,
                'brier_score' => 0.17,
                'support' => 120,
                'prevalence' => 0.4,
            ],
            'baselines' => [
                'training_prevalence' => ['overdue_f1' => 0.5, 'brier_score' => 0.24],
                'previous_period_late' => ['overdue_f1' => 0.58, 'brier_score' => 0.22],
            ],
        ],
        'explanation' => [
            'coefficient_space' => 'standardized_feature_space',
            'factors' => collect($featureNames)->map(function (string $feature, int $index): array {
                $coefficient = validPaymentRiskArtifactCoefficient($index);

                return [
                    'feature' => $feature,
                    'label' => str($feature)->replace('_minor', '')->replace('_', ' ')->headline()->toString(),
                    'coefficient' => $coefficient,
                    'sign' => match (true) {
                        $coefficient > 0 => 'increases_overdue_risk',
                        $coefficient < 0 => 'reduces_overdue_risk',
                        default => 'neutral',
                    },
                ];
            })->values()->all(),
        ],
    ];
}

function validPaymentRiskArtifactCoefficient(int $index): float
{
    $coefficients = [0.000001, -0.03, 0.01, 0.01, 0.03, -0.2, -0.25, -0.3, 0.2, 0.04, 0.04, 0.08, 0.000001, 0.12];

    return $coefficients[$index];
}

/**
 * @param  array<string, mixed>  $artifact
 * @return array<string, mixed>
 */
function paymentRiskArtifactWith(array $artifact, string $path, mixed $value): array
{
    Arr::set($artifact, $path, $value);

    $updatedArtifact = [];

    foreach ($artifact as $key => $item) {
        if (! is_string($key)) {
            throw new RuntimeException('Expected the payment-risk artifact to use string keys.');
        }

        $updatedArtifact[$key] = $item;
    }

    return $updatedArtifact;
}

/**
 * @param  array<string, mixed>  $artifact
 */
function paymentRiskArtifactFile(
    array $artifact,
    ?string $manifestContents = null,
    string $filename = 'model.json',
): UploadedFile {
    $directory = storage_path('framework/testing/payment-risk/'.Str::uuid()->toString());
    File::ensureDirectoryExists($directory);
    $json = json_encode($artifact, JSON_THROW_ON_ERROR);
    $path = "{$directory}/{$filename}";
    File::put($path, $json);
    File::put(
        "{$directory}/checksums.sha256",
        $manifestContents ?? hash('sha256', $json).'  model.json'.PHP_EOL,
    );

    return new UploadedFile($path, $filename, 'application/json', null, true);
}

function installActiveTestPaymentRiskModel(): void
{
    $file = paymentRiskArtifactFile(validPaymentRiskArtifact());

    app(InstallPaymentRiskModel::class)->handle($file->getPathname(), activate: true);
}

function scoreTestContribution(
    Family $family,
    User $member,
    CarbonImmutable $period,
    ?CarbonImmutable $createdAt = null,
): Contribution {
    $createdAt ??= $period->startOfMonth()->setTime(9, 0);

    return Contribution::factory()->create([
        'family_id' => $family->id,
        'user_id' => $member->id,
        'year' => $period->year,
        'month' => $period->month,
        'expected_amount' => 100,
        'due_date' => $period->day(28),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

function createMatureRiskHistory(Family $family, User $member, int $periods): void
{
    $targetPeriod = now()->toImmutable()->startOfMonth();

    foreach (range($periods, 1) as $monthsBack) {
        $period = $targetPeriod->subMonths($monthsBack);
        $contribution = scoreTestContribution($family, $member, $period);

        if ($monthsBack % 2 === 0) {
            Payment::factory()->create([
                'contribution_id' => $contribution->id,
                'amount' => $contribution->expected_amount,
                'paid_at' => $period->day(20),
                'created_at' => $period->day(20)->setTime(10, 0),
                'updated_at' => $period->day(20)->setTime(10, 0),
            ]);
        }
    }
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
