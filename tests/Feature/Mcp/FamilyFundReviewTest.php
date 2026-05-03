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
use Illuminate\Support\Facades\Notification;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

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

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
});

it('requires sanctum authentication for the web mcp route', function () {
    $this->postJson('/mcp/family-fund', familyFundInitializePayload(), [
        'Accept' => 'application/json, text/event-stream',
    ])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"')
        ->assertHeader('Content-Type', 'application/json');
});

it('accepts sanctum bearer tokens for the web mcp route', function () {
    $token = $this->admin->createToken('mcp-inspector')->plainTextToken;

    $this->withToken($token)
        ->postJson('/mcp/family-fund', familyFundInitializePayload(), [
            'Accept' => 'application/json, text/event-stream',
        ])
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('result.serverInfo.name', 'Family Fund Server');
});

it('accepts sanctum authenticated requests for the web mcp route', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson('/mcp/family-fund', familyFundInitializePayload(), [
        'Accept' => 'application/json, text/event-stream',
    ])
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('result.serverInfo.name', 'Family Fund Server');
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
