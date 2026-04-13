<?php

use App\Ai\Tools\SendInvitation;
use App\Mail\FamilyInvitationMail;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
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

    $result = json_decode($tool->handle(new Request([
        'email' => 'newmember@example.com',
        'role' => 'member',
    ])), true);

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['message'])->toContain('newmember@example.com')
        ->and($result['message'])->toContain('member');

    $this->assertDatabaseMissing('family_invitations', ['email' => 'newmember@example.com']);
});

test('admin can execute invitation sending', function () {
    Mail::fake();

    $tool = new SendInvitation($this->admin);

    $result = json_decode($tool->handle(new Request([
        'email' => 'newmember@example.com',
        'role' => 'member',
        'confirmed' => true,
    ])), true);

    expect($result['status'])->toBe('success')
        ->and($result['message'])->toContain('newmember@example.com');

    $this->assertDatabaseHas('family_invitations', [
        'family_id' => $this->family->id,
        'email' => 'newmember@example.com',
        'invited_by' => $this->admin->id,
    ]);

    Mail::assertQueued(FamilyInvitationMail::class);
});

test('financial secretary cannot send invitations', function () {
    $tool = new SendInvitation($this->financialSecretary);

    $result = json_decode($tool->handle(new Request([
        'email' => 'newmember@example.com',
        'role' => 'member',
    ])), true);

    expect($result['error'])->toContain('Only family admins');
});

test('member cannot send invitations', function () {
    $tool = new SendInvitation($this->member);

    $result = json_decode($tool->handle(new Request([
        'email' => 'newmember@example.com',
        'role' => 'member',
    ])), true);

    expect($result['error'])->toContain('Only family admins');
});

test('invitation rejects invalid email', function () {
    $tool = new SendInvitation($this->admin);

    $result = json_decode($tool->handle(new Request([
        'email' => 'not-an-email',
        'role' => 'member',
    ])), true);

    expect($result['error'])->toContain('valid email');
});

test('invitation rejects invalid role', function () {
    $tool = new SendInvitation($this->admin);

    $result = json_decode($tool->handle(new Request([
        'email' => 'test@example.com',
        'role' => 'superadmin',
    ])), true);

    expect($result['error'])->toContain('Invalid role');
});

test('invitation rejects existing family member email', function () {
    $tool = new SendInvitation($this->admin);

    $result = json_decode($tool->handle(new Request([
        'email' => $this->member->email,
        'role' => 'member',
    ])), true);

    expect($result['error'])->toContain('already belongs to a member');
});

test('invitation rejects duplicate pending invitation', function () {
    FamilyInvitation::factory()->create([
        'family_id' => $this->family->id,
        'email' => 'pending@example.com',
    ]);

    $tool = new SendInvitation($this->admin);

    $result = json_decode($tool->handle(new Request([
        'email' => 'pending@example.com',
        'role' => 'member',
    ])), true);

    expect($result['error'])->toContain('already pending');
});

test('invitation can be sent to email with expired invitation', function () {
    FamilyInvitation::factory()->expired()->create([
        'family_id' => $this->family->id,
        'email' => 'expired@example.com',
    ]);

    $tool = new SendInvitation($this->admin);

    $result = json_decode($tool->handle(new Request([
        'email' => 'expired@example.com',
        'role' => 'member',
    ])), true);

    expect($result['status'])->toBe('confirmation_required');
});
