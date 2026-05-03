<?php

use App\Mcp\Resources\FamilyFundReviewApp;
use App\Mcp\Servers\FamilyFundServer;
use App\Mcp\Tools\GetFamilyFundReviewData;
use App\Mcp\Tools\OpenFamilyFundReview;
use App\Mcp\Tools\SendFamilyFundReviewReminders;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Notification;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;
use League\OAuth2\Server\ResourceServer;

function familyFundInitializePayload(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => [
                'name' => 'pest',
                'version' => '1.0.0',
            ],
        ],
    ];
}

function familyFundMcpJson(TestResponse $response): array
{
    $reflection = new ReflectionClass($response);
    $method = $reflection->getMethod('content');
    $method->setAccessible(true);

    return json_decode($method->invoke($response)[0] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
}

function familyFundConfigurePassportKeys(): void
{
    static $keys;

    if ($keys === null) {
        $privateKey = '';
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $privateKey);

        $details = openssl_pkey_get_details($resource);

        $keys = [
            'private' => $privateKey,
            'public' => $details['key'],
        ];
    }

    config([
        'passport.private_key' => $keys['private'],
        'passport.public_key' => $keys['public'],
    ]);

    app()->forgetInstance(ResourceServer::class);
}

beforeEach(function () {
    familyFundConfigurePassportKeys();

    $this->family = Family::factory()->create();
    $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
});

it('advertises oauth metadata for mcp clients', function () {
    $this->getJson('/.well-known/oauth-protected-resource/mcp/family-fund')
        ->assertSuccessful()
        ->assertJsonPath('resource', url('/mcp/family-fund'))
        ->assertJsonPath('authorization_servers.0', url('/'))
        ->assertJsonPath('scopes_supported.0', 'mcp:use');

    $this->getJson('/.well-known/oauth-authorization-server/mcp/family-fund')
        ->assertSuccessful()
        ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
        ->assertJsonPath('token_endpoint', route('passport.token'))
        ->assertJsonPath('registration_endpoint', url('/oauth/register'))
        ->assertJsonPath('scopes_supported.0', 'mcp:use');
});

it('requires oauth authentication for the web mcp route', function () {
    $response = $this->postJson('/mcp/family-fund', familyFundInitializePayload(), [
        'Accept' => 'application/json, text/event-stream',
    ]);

    $response
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/json')
        ->assertDontSee('<!DOCTYPE html');

    expect($response->headers->get('WWW-Authenticate'))
        ->toContain('Bearer realm="mcp"')
        ->toContain('resource_metadata=')
        ->toContain('/.well-known/oauth-protected-resource/mcp/family-fund');
});

it('accepts passport authenticated requests for the web mcp route', function () {
    Passport::actingAs($this->admin, ['mcp:use']);

    $this->postJson('/mcp/family-fund', familyFundInitializePayload(), [
        'Accept' => 'application/json, text/event-stream',
    ])
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('result.serverInfo.name', 'Family Fund Server');
});

it('forbids unverified users from the web mcp route and mcp tools', function () {
    $unverifiedAdmin = User::factory()
        ->admin()
        ->unverified()
        ->create(['family_id' => $this->family->id]);

    Passport::actingAs($unverifiedAdmin, ['mcp:use']);

    $this->postJson('/mcp/family-fund', familyFundInitializePayload(), [
        'Accept' => 'application/json, text/event-stream',
    ])
        ->assertForbidden()
        ->assertJsonPath('message', 'Your email address is not verified.');

    FamilyFundServer::actingAs($unverifiedAdmin)
        ->tool(OpenFamilyFundReview::class)
        ->assertHasErrors(['Your email address is not verified.']);
});

it('opens the review app with ui metadata', function () {
    FamilyFundServer::actingAs($this->admin)
        ->tool(OpenFamilyFundReview::class)
        ->assertOk()
        ->assertSee('Family fund review loaded.');

    $tool = new OpenFamilyFundReview;
    $metadata = $tool->toArray()['_meta']['ui'] ?? [];

    expect($tool->name())->toBe('open-family-fund-review')
        ->and($metadata['resourceUri'] ?? null)->toBe((new FamilyFundReviewApp)->uri())
        ->and($metadata['visibility'] ?? [])->toContain('model', 'app')
        ->and($tool->toArray()['_meta']['openai/outputTemplate'] ?? null)->toBe((new FamilyFundReviewApp)->uri());
});

it('returns the app resource html', function () {
    FamilyFundServer::actingAs($this->admin)
        ->resource(FamilyFundReviewApp::class)
        ->assertOk()
        ->assertSee(['Family Fund Review', 'family-fund-review']);
});

it('preserves oauth state in the authorization form', function () {
    $this->withoutVite();

    $client = new Client([
        'name' => 'MCP Client',
    ]);
    $client->id = 'client-id';

    $html = (string) $this->view('mcp.authorize', [
        'client' => $client,
        'user' => $this->admin,
        'scopes' => [new Scope('mcp:use', 'Use available MCP functionality.')],
        'request' => HttpRequest::create('/oauth/authorize', 'GET', [
            'state' => 'client-state-token',
        ]),
        'authToken' => 'auth-token',
    ]);

    expect($html)
        ->toContain('name="state" value="client-state-token"')
        ->and(substr_count($html, 'name="state" value="client-state-token"'))->toBe(2);
});

it('returns contribution review data for the authenticated family only', function () {
    $member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $contribution = Contribution::factory()
        ->forUser($member)
        ->currentMonth()
        ->create(['expected_amount' => 5000]);
    Payment::factory()
        ->forContribution($contribution)
        ->recordedBy($this->admin)
        ->create(['amount' => 2000]);

    $otherFamily = Family::factory()->create();
    $otherMember = User::factory()->member()->employed()->create(['family_id' => $otherFamily->id]);
    Contribution::factory()
        ->forUser($otherMember)
        ->currentMonth()
        ->create(['expected_amount' => 9000]);

    $data = familyFundMcpJson(
        FamilyFundServer::actingAs($this->admin)->tool(GetFamilyFundReviewData::class)
    );

    expect($data['summary'])
        ->toHaveKey('total_expected', 5000)
        ->toHaveKey('total_collected', 2000)
        ->toHaveKey('total_outstanding', 3000)
        ->and($data['members'])->toHaveCount(1)
        ->and($data['members'][0]['name'])->toBe($member->name);
});

it('excludes archived members from review data', function () {
    $archived = User::factory()
        ->member()
        ->employed()
        ->archived()
        ->create(['family_id' => $this->family->id]);
    Contribution::factory()
        ->forUser($archived)
        ->currentMonth()
        ->create();

    $data = familyFundMcpJson(
        FamilyFundServer::actingAs($this->admin)->tool(GetFamilyFundReviewData::class)
    );

    expect($data['members'])->toBeEmpty()
        ->and($data['summary']['member_count'])->toBe(0);
});

it('marks fully paid contributions as not eligible for reminders', function () {
    $member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $contribution = Contribution::factory()
        ->forUser($member)
        ->currentMonth()
        ->create(['expected_amount' => 4000]);
    Payment::factory()
        ->forContribution($contribution)
        ->recordedBy($this->admin)
        ->create(['amount' => 4000]);

    $data = familyFundMcpJson(
        FamilyFundServer::actingAs($this->admin)->tool(GetFamilyFundReviewData::class)
    );

    expect($data['members'][0])
        ->toHaveKey('status', 'paid')
        ->toHaveKey('reminder_eligible', false)
        ->toHaveKey('reminder_ineligible_reason', 'This contribution is fully paid.');
});

it('forbids regular members from opening and reading review data', function () {
    $member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);

    FamilyFundServer::actingAs($member)
        ->tool(OpenFamilyFundReview::class)
        ->assertHasErrors(['Permission denied']);

    FamilyFundServer::actingAs($member)
        ->tool(GetFamilyFundReviewData::class)
        ->assertHasErrors(['Permission denied']);
});

it('previews valid and invalid reminder selections without sending', function () {
    Notification::fake();

    $member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $validContribution = Contribution::factory()
        ->forUser($member)
        ->currentMonth()
        ->create(['expected_amount' => 5000]);

    $paidMember = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $paidContribution = Contribution::factory()
        ->forUser($paidMember)
        ->currentMonth()
        ->create(['expected_amount' => 4000]);
    Payment::factory()
        ->forContribution($paidContribution)
        ->recordedBy($this->admin)
        ->create(['amount' => 4000]);

    $preview = familyFundMcpJson(
        FamilyFundServer::actingAs($this->admin)->tool(SendFamilyFundReviewReminders::class, [
            'contribution_ids' => [$validContribution->id, $paidContribution->id],
            'channels' => ['mail'],
            'confirmed' => false,
        ])
    );

    expect($preview)
        ->toHaveKey('status', 'confirmation_required')
        ->toHaveKey('valid_count', 1)
        ->toHaveKey('invalid_count', 1)
        ->and($preview['valid'][0]['contribution_id'])->toBe($validContribution->id)
        ->and($preview['invalid'][0]['contribution_id'])->toBe($paidContribution->id);

    Notification::assertNothingSent();
});

it('sends confirmed reminders only to valid selected contributions', function () {
    Notification::fake();

    $member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $validContribution = Contribution::factory()
        ->forUser($member)
        ->currentMonth()
        ->create(['expected_amount' => 5000]);

    $paidMember = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $paidContribution = Contribution::factory()
        ->forUser($paidMember)
        ->currentMonth()
        ->create(['expected_amount' => 4000]);
    Payment::factory()
        ->forContribution($paidContribution)
        ->recordedBy($this->admin)
        ->create(['amount' => 4000]);

    $result = familyFundMcpJson(
        FamilyFundServer::actingAs($this->admin)->tool(SendFamilyFundReviewReminders::class, [
            'contribution_ids' => [$validContribution->id, $paidContribution->id],
            'channels' => ['mail'],
            'confirmed' => true,
        ])
    );

    expect($result)
        ->toHaveKey('status', 'success')
        ->toHaveKey('sent_count', 1)
        ->toHaveKey('invalid_count', 1);

    Notification::assertSentTo(
        $member,
        ContributionReminderNotification::class,
        fn (ContributionReminderNotification $notification): bool => $notification->via($member) === ['mail'],
    );
    Notification::assertNotSentTo($paidMember, ContributionReminderNotification::class);
});
