<?php

namespace App\Ai\Tools;

use App\Enums\Role;
use App\Mail\FamilyInvitationMail;
use App\Models\FamilyInvitation;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SendInvitation implements Tool
{
    public function __construct(private User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Sends an invitation to join the family group via email. Requires the email address and role (admin, financial_secretary, or member). Only admins can send invitations. Always call without confirmed=true first to preview.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        if (! $this->user->isAdmin()) {
            return json_encode(['error' => 'Only family admins can send invitations.'], JSON_THROW_ON_ERROR);
        }

        $email = $request['email'] ?? null;
        $roleValue = $request['role'] ?? null;
        $confirmed = $request['confirmed'] ?? false;

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json_encode(['error' => 'A valid email address is required.'], JSON_THROW_ON_ERROR);
        }

        if (! $roleValue) {
            return json_encode(['error' => 'A role is required. Choose from: admin, financial_secretary, or member.'], JSON_THROW_ON_ERROR);
        }

        $role = Role::tryFrom($roleValue);

        if (! $role) {
            return json_encode(['error' => "Invalid role \"{$roleValue}\". Choose from: admin, financial_secretary, or member."], JSON_THROW_ON_ERROR);
        }

        // Check if already a family member
        $existingMember = User::query()
            ->where('family_id', $this->user->family_id)
            ->where('email', $email)
            ->first();

        if ($existingMember) {
            return json_encode(['error' => 'This email address already belongs to a member of your family.'], JSON_THROW_ON_ERROR);
        }

        // Check for pending invitation
        $existingInvitation = FamilyInvitation::query()
            ->where('family_id', $this->user->family_id)
            ->where('email', $email)
            ->pending()
            ->first();

        if ($existingInvitation) {
            return json_encode(['error' => 'An invitation is already pending for this email address.'], JSON_THROW_ON_ERROR);
        }

        $familyName = $this->user->family?->name ?? 'the family';

        if (! $confirmed) {
            return json_encode([
                'status' => 'confirmation_required',
                'message' => "I'll send an invitation to {$email} to join {$familyName} as a {$role->value}. The invitation will expire in 7 days. Please confirm to proceed.",
                'details' => [
                    'email' => $email,
                    'role' => $role->value,
                    'family' => $familyName,
                ],
            ], JSON_THROW_ON_ERROR);
        }

        $invitation = FamilyInvitation::create([
            'family_id' => $this->user->family_id,
            'email' => $email,
            'role' => $role,
            'token' => Str::random(64),
            'invited_by' => $this->user->id,
            'expires_at' => now()->addDays(7),
        ]);

        $acceptUrl = route('invitations.accept', $invitation->token);

        Mail::to($email)->send(new FamilyInvitationMail($invitation, $acceptUrl));

        return json_encode([
            'status' => 'success',
            'message' => "Invitation sent to {$email} to join {$familyName} as a {$role->value}.",
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()->required(),
            'role' => $schema->string()->required(),
            'confirmed' => $schema->boolean(),
        ];
    }
}
