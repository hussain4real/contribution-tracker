<?php

declare(strict_types=1);

use App\Ai\Tools\SendInvitation;
use App\Enums\InvitationDeliveryMethod;
use App\Mail\FamilyInvitationMail;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Mail;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->create(['family_id' => $this->family->id]);
});

test('admin can preview invitation sending', function () {
    $tool = new SendInvitation($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'email' => 'newmember@example.com',
        'role' => 'member',
    ])));

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['message'])->toContain('newmember@example.com')
        ->and($result['message'])->toContain('member');

    $this->assertDatabaseMissing('family_invitations', ['email' => 'newmember@example.com']);
});

test('admin can execute invitation sending', function () {
    Mail::fake();

    $tool = new SendInvitation($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'email' => 'newmember@example.com',
        'role' => 'member',
        'confirmed' => true,
    ])));

    expect($result['status'])->toBe('success')
        ->and($result['message'])->toContain('newmember@example.com');

    $this->assertDatabaseHas('family_invitations', [
        'family_id' => $this->family->id,
        'email' => 'newmember@example.com',
        'invited_by' => $this->admin->id,
    ]);

    Mail::assertQueued(FamilyInvitationMail::class);
});

test('admin can execute invitation sending over whatsapp', function () {
    $whatsapp = typedMock(WhatsAppService::class);
    $whatsapp->shouldReceive('sendInvitation')
        ->once()
        ->with(
            '+2348012345678',
            $this->family->name,
            'Member',
            Mockery::on(fn (string $url): bool => str_contains($url, '/invitations/')),
        )
        ->andReturn([
            'success' => true,
            'wa_message_id' => 'wamid.invitation',
            'error' => null,
        ]);

    $tool = new SendInvitation($this->admin, $whatsapp);

    $result = decodeToolResult($tool->handle(new Request([
        'delivery_method' => InvitationDeliveryMethod::WhatsApp->value,
        'whatsapp_phone' => '+2348012345678',
        'role' => 'member',
        'confirmed' => true,
    ])));

    expect($result['status'])->toBe('success')
        ->and($result['message'])->toContain('+2348012345678');

    $this->assertDatabaseHas('family_invitations', [
        'family_id' => $this->family->id,
        'delivery_method' => InvitationDeliveryMethod::WhatsApp->value,
        'whatsapp_phone' => '+2348012345678',
        'invited_by' => $this->admin->id,
    ]);
});

test('financial secretary can execute member invitation sending', function () {
    Mail::fake();

    $tool = new SendInvitation($this->financialSecretary);

    $result = decodeToolResult($tool->handle(new Request([
        'email' => 'newmember@example.com',
        'role' => 'member',
        'confirmed' => true,
    ])));

    expect($result['status'])->toBe('success')
        ->and($result['message'])->toContain('newmember@example.com');

    $this->assertDatabaseHas('family_invitations', [
        'family_id' => $this->family->id,
        'email' => 'newmember@example.com',
        'invited_by' => $this->financialSecretary->id,
    ]);

    Mail::assertQueued(FamilyInvitationMail::class);
});

test('financial secretary cannot invite privileged roles', function () {
    $tool = new SendInvitation($this->financialSecretary);

    $result = decodeToolResult($tool->handle(new Request([
        'email' => 'newadmin@example.com',
        'role' => 'admin',
        'confirmed' => true,
    ])));

    expect($result['error'])->toContain('Only family admins can invite admin or financial secretary roles');
});

test('member cannot send invitations', function () {
    $tool = new SendInvitation($this->member);

    $result = decodeToolResult($tool->handle(new Request([
        'email' => 'newmember@example.com',
        'role' => 'member',
    ])));

    expect($result['error'])->toContain('Only family admins and financial secretaries');
});

test('invitation rejects invalid email', function () {
    $tool = new SendInvitation($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'email' => 'not-an-email',
        'role' => 'member',
    ])));

    expect($result['error'])->toContain('valid email');
});

test('invitation rejects invalid delivery method', function () {
    $tool = new SendInvitation($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'delivery_method' => 'sms',
        'email' => 'test@example.com',
        'role' => 'member',
    ])));

    expect($result['error'])->toContain('valid delivery method');
});

test('invitation rejects invalid whatsapp phone', function () {
    $tool = new SendInvitation($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'delivery_method' => InvitationDeliveryMethod::WhatsApp->value,
        'whatsapp_phone' => '08012345678',
        'role' => 'member',
    ])));

    expect($result['error'])->toContain('valid WhatsApp number');
});

test('invitation execution requires whatsapp phone for whatsapp delivery', function () {
    $tool = new SendInvitation($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'delivery_method' => InvitationDeliveryMethod::WhatsApp->value,
        'role' => 'member',
        'confirmed' => true,
    ])));

    expect($result['error'])->toContain('valid WhatsApp number');
});

test('invitation requires a role', function () {
    $tool = new SendInvitation($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'email' => 'test@example.com',
    ])));

    expect($result['error'])->toContain('A role is required');
});

test('invitation rejects invalid role', function () {
    $tool = new SendInvitation($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'email' => 'test@example.com',
        'role' => 'superadmin',
    ])));

    expect($result['error'])->toContain('Invalid role');
});

test('invitation rejects existing family member email', function () {
    $tool = new SendInvitation($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'email' => $this->member->email,
        'role' => 'member',
    ])));

    expect($result['error'])->toContain('already belongs to a member');
});

test('invitation rejects existing family member whatsapp phone', function () {
    User::factory()->member()->withUnverifiedWhatsApp('+2348012345678')->create([
        'family_id' => $this->family->id,
    ]);

    $tool = new SendInvitation($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'delivery_method' => InvitationDeliveryMethod::WhatsApp->value,
        'whatsapp_phone' => '+2348012345678',
        'role' => 'member',
    ])));

    expect($result['error'])->toContain('WhatsApp number already belongs to a member');
});

test('invitation rejects duplicate pending invitation', function () {
    FamilyInvitation::factory()->create([
        'family_id' => $this->family->id,
        'email' => 'pending@example.com',
    ]);

    $tool = new SendInvitation($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'email' => 'pending@example.com',
        'role' => 'member',
    ])));

    expect($result['error'])->toContain('already pending');
});

test('invitation rejects duplicate pending whatsapp invitation', function () {
    FamilyInvitation::factory()->viaWhatsApp('+2348012345678')->create([
        'family_id' => $this->family->id,
    ]);

    $tool = new SendInvitation($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'delivery_method' => InvitationDeliveryMethod::WhatsApp->value,
        'whatsapp_phone' => '+2348012345678',
        'role' => 'member',
    ])));

    expect($result['error'])->toContain('already pending for this WhatsApp number');
});

test('invitation deletes whatsapp invite when sending fails', function () {
    $whatsapp = typedMock(WhatsAppService::class);
    $whatsapp->shouldReceive('sendInvitation')
        ->once()
        ->andReturn([
            'success' => false,
            'wa_message_id' => null,
            'error' => 'Invalid recipient',
        ]);

    $tool = new SendInvitation($this->admin, $whatsapp);

    $result = decodeToolResult($tool->handle(new Request([
        'delivery_method' => InvitationDeliveryMethod::WhatsApp->value,
        'whatsapp_phone' => '+2348012345678',
        'role' => 'member',
        'confirmed' => true,
    ])));

    expect($result['error'])->toContain('Could not send the WhatsApp invitation');
    expect(FamilyInvitation::where('whatsapp_phone', '+2348012345678')->exists())->toBeFalse();
});

test('invitation can be sent to email with expired invitation', function () {
    FamilyInvitation::factory()->expired()->create([
        'family_id' => $this->family->id,
        'email' => 'expired@example.com',
    ]);

    $tool = new SendInvitation($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'email' => 'expired@example.com',
        'role' => 'member',
    ])));

    expect($result['status'])->toBe('confirmation_required');
});
