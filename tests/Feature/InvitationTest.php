<?php

declare(strict_types=1);

use App\Enums\InvitationDeliveryMethod;
use App\Enums\Role;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

describe('Invitation Store', function () {
    it('prevents inviting a user who is already a family member', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $existingMember = User::factory()->member()->create([
            'family_id' => $family->id,
            'email' => 'member@example.com',
        ]);

        $this->actingAs($admin)
            ->post('/family/invitations', [
                'email' => 'member@example.com',
                'role' => Role::Member->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'This user is already a member of your family.');

        expect(FamilyInvitation::count())->toBe(0);
    });

    it('prevents inviting a whatsapp number that already belongs to a family member', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        User::factory()->member()->withUnverifiedWhatsApp('+2348012345678')->create([
            'family_id' => $family->id,
        ]);

        $this->actingAs($admin)
            ->post('/family/invitations', [
                'delivery_method' => InvitationDeliveryMethod::WhatsApp->value,
                'whatsapp_phone' => '+2348012345678',
                'role' => Role::Member->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'This WhatsApp number already belongs to a member of your family.');

        expect(FamilyInvitation::count())->toBe(0);
    });

    it('prevents duplicate pending invitations', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        FamilyInvitation::factory()->create([
            'family_id' => $family->id,
            'email' => 'new@example.com',
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($admin)
            ->post('/family/invitations', [
                'email' => 'new@example.com',
                'role' => Role::Member->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'An invitation is already pending for this email.');
    });

    it('prevents duplicate pending whatsapp invitations', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        FamilyInvitation::factory()->viaWhatsApp('+2348012345678')->create([
            'family_id' => $family->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($admin)
            ->post('/family/invitations', [
                'delivery_method' => InvitationDeliveryMethod::WhatsApp->value,
                'whatsapp_phone' => '+2348012345678',
                'role' => Role::Member->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'An invitation is already pending for this WhatsApp number.');
    });

    it('allows inviting a new email address', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->post('/family/invitations', [
                'email' => 'new@example.com',
                'role' => Role::Member->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        expect(FamilyInvitation::where('email', 'new@example.com')->count())->toBe(1);
    });

    it('allows financial secretaries to invite ordinary members', function () {
        $family = Family::factory()->create();
        $financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $family->id]);

        $this->actingAs($financialSecretary)
            ->post('/family/invitations', [
                'email' => 'new@example.com',
                'role' => Role::Member->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('family_invitations', [
            'family_id' => $family->id,
            'email' => 'new@example.com',
            'role' => Role::Member->value,
            'invited_by' => $financialSecretary->id,
        ]);
    });

    it('prevents financial secretaries from inviting privileged roles', function () {
        $family = Family::factory()->create();
        $financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $family->id]);

        $this->actingAs($financialSecretary)
            ->post('/family/invitations', [
                'email' => 'new-admin@example.com',
                'role' => Role::Admin->value,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('family_invitations', [
            'email' => 'new-admin@example.com',
        ]);
    });

    it('allows inviting a new whatsapp number', function () {
        config()->set('services.whatsapp', [
            'access_token' => 'test-token',
            'phone_number_id' => '1038448572690931',
            'business_account_id' => '965423126197935',
            'api_version' => 'v25.0',
            'base_url' => 'https://graph.facebook.com',
            'webhook_verify_token' => 'verify-token',
            'app_secret' => 'app-secret',
            'templates' => [
                'invitation' => [
                    'name' => 'family_invitation',
                    'language' => 'en_GB',
                ],
            ],
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.invitation']],
            ]),
        ]);

        $family = Family::factory()->create(['name' => 'Smith Family']);
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->post('/family/invitations', [
                'delivery_method' => InvitationDeliveryMethod::WhatsApp->value,
                'whatsapp_phone' => '+2348012345678',
                'role' => Role::Member->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Invitation sent to +2348012345678 via WhatsApp.');

        $invitation = FamilyInvitation::query()
            ->where('whatsapp_phone', '+2348012345678')
            ->firstOrFail();

        expect($invitation->email)->toBeNull()
            ->and($invitation->delivery_method)->toBe(InvitationDeliveryMethod::WhatsApp);

        Http::assertSent(function (Request $request) use ($invitation): bool {
            $body = $request->data();
            $template = resultArray($body, 'template');
            $components = resultArray($template, 'components');
            $bodyComponent = resultArray($components, 0);
            $parameters = resultArray($bodyComponent, 'parameters');
            $acceptUrlParameter = resultArray($parameters, 2);

            return str_contains($request->url(), '/messages')
                && ($body['to'] ?? null) === '2348012345678'
                && ($body['type'] ?? null) === 'template'
                && ($template['name'] ?? null) === 'family_invitation'
                && stringValue(resultArray($template, 'language'), 'code') === 'en_GB'
                && ($acceptUrlParameter['text'] ?? null) === route('invitations.accept', $invitation->token);
        });
    });

    it('does not keep a whatsapp invitation when the invitation template is not configured', function () {
        config()->set('services.whatsapp', [
            'access_token' => 'test-token',
            'phone_number_id' => '1038448572690931',
            'business_account_id' => '965423126197935',
            'api_version' => 'v25.0',
            'base_url' => 'https://graph.facebook.com',
            'webhook_verify_token' => 'verify-token',
            'app_secret' => 'app-secret',
            'templates' => [
                'invitation' => [
                    'name' => null,
                    'language' => 'en_GB',
                ],
            ],
        ]);

        Http::preventStrayRequests();

        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->post('/family/invitations', [
                'delivery_method' => InvitationDeliveryMethod::WhatsApp->value,
                'whatsapp_phone' => '+2348012345678',
                'role' => Role::Member->value,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('whatsapp_phone');

        expect(FamilyInvitation::where('whatsapp_phone', '+2348012345678')->exists())->toBeFalse();
    });

    it('does not keep a whatsapp invitation when delivery fails', function () {
        config()->set('services.whatsapp', [
            'access_token' => 'test-token',
            'phone_number_id' => '1038448572690931',
            'business_account_id' => '965423126197935',
            'api_version' => 'v25.0',
            'base_url' => 'https://graph.facebook.com',
            'webhook_verify_token' => 'verify-token',
            'app_secret' => 'app-secret',
            'templates' => [
                'invitation' => [
                    'name' => 'family_invitation',
                    'language' => 'en_GB',
                ],
            ],
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid recipient', 'code' => 131030],
            ], 400),
        ]);

        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->post('/family/invitations', [
                'delivery_method' => InvitationDeliveryMethod::WhatsApp->value,
                'whatsapp_phone' => '+2348012345678',
                'role' => Role::Member->value,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('whatsapp_phone');

        expect(FamilyInvitation::where('whatsapp_phone', '+2348012345678')->exists())->toBeFalse();
    });

    it('prevents members from sending invitations', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($member)
            ->post('/family/invitations', [
                'email' => 'new@example.com',
                'role' => Role::Member->value,
            ])
            ->assertForbidden();
    });
});

describe('Invitation Index', function () {
    it('lists family invitations for admins', function () {
        $family = Family::factory()->create(['name' => 'Smith Family']);
        $otherFamily = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $inviter = User::factory()->admin()->create(['family_id' => $family->id, 'name' => 'Ada Admin']);

        FamilyInvitation::factory()->create([
            'family_id' => $family->id,
            'email' => 'first@example.com',
            'delivery_method' => InvitationDeliveryMethod::Email,
            'role' => Role::FinancialSecretary,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);
        FamilyInvitation::factory()->create([
            'family_id' => $otherFamily->id,
            'email' => 'other@example.com',
        ]);

        $this->actingAs($admin)
            ->get(route('family.invitations'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Family/Invitations')
                ->where('family_name', 'Smith Family')
                ->has('invitations', 1)
                ->where('invitations.0.email', 'first@example.com')
                ->where('invitations.0.delivery_method', InvitationDeliveryMethod::Email->value)
                ->where('invitations.0.delivery_method_label', 'Email')
                ->where('invitations.0.contact', 'first@example.com')
                ->where('invitations.0.role', Role::FinancialSecretary->value)
                ->where('invitations.0.role_label', 'Financial Secretary')
                ->where('invitations.0.invited_by', 'Ada Admin')
                ->where('invitations.0.is_pending', true)
                ->has('roles', 3)
                ->where('roles.0.value', Role::Admin->value)
                ->where('roles.1.value', Role::FinancialSecretary->value)
                ->where('roles.2.value', Role::Member->value)
            );
    });

    it('lists family invitations for financial secretaries', function () {
        $family = Family::factory()->create(['name' => 'Smith Family']);
        $financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $family->id]);

        FamilyInvitation::factory()->create([
            'family_id' => $family->id,
            'email' => 'first@example.com',
            'role' => Role::Member,
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($financialSecretary)
            ->get(route('family.invitations'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Family/Invitations')
                ->where('family_name', 'Smith Family')
                ->has('invitations', 1)
                ->where('invitations.0.email', 'first@example.com')
                ->has('roles', 1)
                ->where('roles.0.value', Role::Member->value)
            );
    });

    it('prevents members from listing invitations', function () {
        $member = User::factory()->member()->create();

        $this->actingAs($member)
            ->get(route('family.invitations'))
            ->assertForbidden();
    });
});

describe('Invitation Destroy', function () {
    it('allows admins to cancel invitations in their family', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $invitation = FamilyInvitation::factory()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->delete(route('family.invitations.destroy', $invitation))
            ->assertRedirect()
            ->assertSessionHas('success', 'Invitation cancelled.');

        expect(FamilyInvitation::whereKey($invitation->id)->exists())->toBeFalse();
    });

    it('allows financial secretaries to cancel invitations in their family', function () {
        $family = Family::factory()->create();
        $financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
        $invitation = FamilyInvitation::factory()->create(['family_id' => $family->id]);

        $this->actingAs($financialSecretary)
            ->delete(route('family.invitations.destroy', $invitation))
            ->assertRedirect()
            ->assertSessionHas('success', 'Invitation cancelled.');

        expect(FamilyInvitation::whereKey($invitation->id)->exists())->toBeFalse();
    });

    it('prevents admins from cancelling invitations in another family', function () {
        $admin = User::factory()->admin()->create();
        $invitation = FamilyInvitation::factory()->create();

        $this->actingAs($admin)
            ->delete(route('family.invitations.destroy', $invitation))
            ->assertForbidden();
    });

    it('prevents members from cancelling invitations', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->create(['family_id' => $family->id]);
        $invitation = FamilyInvitation::factory()->create(['family_id' => $family->id]);

        $this->actingAs($member)
            ->delete(route('family.invitations.destroy', $invitation))
            ->assertForbidden();
    });
});

describe('Invitation Accept', function () {
    it('renders a valid invitation acceptance page', function () {
        $family = Family::factory()->create(['name' => 'Smith Family']);
        $invitation = FamilyInvitation::factory()->create([
            'family_id' => $family->id,
            'email' => 'guest@example.com',
            'delivery_method' => InvitationDeliveryMethod::Email,
            'role' => Role::Member,
            'token' => 'valid-token',
            'expires_at' => now()->addDay(),
        ]);

        $this->get(route('invitations.accept', $invitation->token))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/AcceptInvitation')
                ->where('invitation.token', 'valid-token')
                ->where('invitation.email', 'guest@example.com')
                ->where('invitation.delivery_method', InvitationDeliveryMethod::Email->value)
                ->where('invitation.family_name', 'Smith Family')
                ->where('invitation.role_label', 'Member')
            );
    });

    it('renders a whatsapp invitation acceptance page without an email address', function () {
        $family = Family::factory()->create(['name' => 'Smith Family']);
        $invitation = FamilyInvitation::factory()->viaWhatsApp('+2348012345678')->create([
            'family_id' => $family->id,
            'role' => Role::Member,
            'token' => 'valid-token',
            'expires_at' => now()->addDay(),
        ]);

        $this->get(route('invitations.accept', $invitation->token))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/AcceptInvitation')
                ->where('invitation.token', 'valid-token')
                ->where('invitation.email', null)
                ->where('invitation.delivery_method', InvitationDeliveryMethod::WhatsApp->value)
                ->where('invitation.whatsapp_phone', '+2348012345678')
                ->where('invitation.family_name', 'Smith Family')
                ->where('invitation.role_label', 'Member')
            );
    });

    it('redirects missing or accepted invitations', function (?FamilyInvitation $invitation, string $token) {
        $acceptToken = $invitation instanceof FamilyInvitation ? $invitation->token : $token;

        $this->get(route('invitations.accept', $acceptToken))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', 'This invitation is no longer valid.');
    })->with([
        'missing' => [null, 'missing-token'],
        'accepted' => fn () => [FamilyInvitation::factory()->accepted()->create(), 'unused'],
    ]);

    it('redirects expired invitations', function () {
        $invitation = FamilyInvitation::factory()->expired()->create();

        $this->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', 'This invitation has expired.');
    });
});
