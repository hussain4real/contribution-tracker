<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\InvitationDeliveryMethod;
use App\Enums\Role;
use App\Mail\FamilyInvitationMail;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SendInvitation implements Tool
{
    public function __construct(
        private User $user,
        private ?WhatsAppService $whatsapp = null,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Sends an invitation to join the family group via email or WhatsApp. Requires delivery_method=email with email, or delivery_method=whatsapp with whatsapp_phone, plus role (admin, financial_secretary, or member). Admins can invite any role; financial secretaries can invite members. Always call without confirmed=true first to preview.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        if (! $this->user->canAddMembers()) {
            return json_encode(['error' => 'Only family admins and financial secretaries can send invitations.'], JSON_THROW_ON_ERROR);
        }

        $family = $this->user->currentFamily ?? $this->user->family;

        if (! $family instanceof Family) {
            return json_encode(['error' => 'User is not associated with a family.'], JSON_THROW_ON_ERROR);
        }

        $deliveryMethodValue = $this->stringFromRequest($request['delivery_method'] ?? null, InvitationDeliveryMethod::Email->value);
        $deliveryMethod = InvitationDeliveryMethod::tryFrom($deliveryMethodValue);
        $email = $this->nullableStringFromRequest($request['email'] ?? null);
        $whatsappPhone = $this->nullableStringFromRequest($request['whatsapp_phone'] ?? null);
        $roleValue = $this->nullableStringFromRequest($request['role'] ?? null);
        $confirmed = ($request['confirmed'] ?? false) === true;

        if (! $deliveryMethod) {
            return json_encode(['error' => 'Choose a valid delivery method: email or whatsapp.'], JSON_THROW_ON_ERROR);
        }

        if ($deliveryMethod === InvitationDeliveryMethod::WhatsApp) {
            $whatsappPhone = $this->validWhatsAppPhone($whatsappPhone);

            if ($whatsappPhone === null) {
                return json_encode(['error' => 'A valid WhatsApp number in international format is required.'], JSON_THROW_ON_ERROR);
            }

            $contact = $whatsappPhone;
            $email = null;
        } else {
            if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return json_encode(['error' => 'A valid email address is required.'], JSON_THROW_ON_ERROR);
            }

            $contact = $email;
            $whatsappPhone = null;
        }

        if (! $roleValue) {
            return json_encode(['error' => 'A role is required. Choose from: admin, financial_secretary, or member.'], JSON_THROW_ON_ERROR);
        }

        $role = Role::tryFrom($roleValue);

        if (! $role) {
            return json_encode(['error' => "Invalid role \"{$roleValue}\". Choose from: admin, financial_secretary, or member."], JSON_THROW_ON_ERROR);
        }

        if (! $this->user->canManageRoles() && $role !== Role::Member) {
            return json_encode(['error' => 'Only family admins can invite admin or financial secretary roles.'], JSON_THROW_ON_ERROR);
        }

        $existingMemberQuery = $family->members();

        if ($deliveryMethod === InvitationDeliveryMethod::Email) {
            $existingMemberQuery->where('email', $contact);
        } else {
            $existingMemberQuery->where('whatsapp_phone', $contact);
        }

        $existingMember = $existingMemberQuery->first();

        if ($existingMember) {
            $message = $deliveryMethod === InvitationDeliveryMethod::Email
                ? 'This email address already belongs to a member of your family.'
                : 'This WhatsApp number already belongs to a member of your family.';

            return json_encode(['error' => $message], JSON_THROW_ON_ERROR);
        }

        $existingInvitationQuery = FamilyInvitation::query()
            ->where('family_id', $family->id)
            ->where('delivery_method', $deliveryMethod)
            ->pending()
            ->when(
                $deliveryMethod === InvitationDeliveryMethod::Email,
                fn ($query) => $query->where('email', $contact),
                fn ($query) => $query->where('whatsapp_phone', $contact),
            );

        $existingInvitation = $existingInvitationQuery->first();

        if ($existingInvitation) {
            $message = $deliveryMethod === InvitationDeliveryMethod::Email
                ? 'An invitation is already pending for this email address.'
                : 'An invitation is already pending for this WhatsApp number.';

            return json_encode(['error' => $message], JSON_THROW_ON_ERROR);
        }

        $familyName = $family->name;

        if (! $confirmed) {
            return json_encode([
                'status' => 'confirmation_required',
                'message' => "I'll send an invitation to {$contact} via {$deliveryMethod->label()} to join {$familyName} as a {$role->value}. The invitation will expire in 7 days. Please confirm to proceed.",
                'details' => [
                    'email' => $deliveryMethod === InvitationDeliveryMethod::Email ? $contact : null,
                    'delivery_method' => $deliveryMethod->value,
                    'whatsapp_phone' => $deliveryMethod === InvitationDeliveryMethod::WhatsApp ? $contact : null,
                    'role' => $role->value,
                    'family' => $familyName,
                ],
            ], JSON_THROW_ON_ERROR);
        }

        $invitation = FamilyInvitation::create([
            'family_id' => $family->id,
            'email' => $deliveryMethod === InvitationDeliveryMethod::Email ? $contact : null,
            'delivery_method' => $deliveryMethod,
            'whatsapp_phone' => $deliveryMethod === InvitationDeliveryMethod::WhatsApp ? $contact : null,
            'role' => $role,
            'token' => Str::random(64),
            'invited_by' => $this->user->id,
            'expires_at' => now()->addDays(7),
        ]);

        $acceptUrl = route('invitations.accept', $invitation->token);

        if ($deliveryMethod === InvitationDeliveryMethod::Email) {
            Mail::to($contact)->queue(new FamilyInvitationMail($invitation, $acceptUrl));
        } else {
            $whatsapp = $this->whatsapp ?? app(WhatsAppService::class);
            $result = $whatsapp->sendInvitation(
                $contact,
                $familyName,
                $role->label(),
                $acceptUrl,
            );

            if (! $result['success']) {
                $invitation->delete();

                return json_encode(['error' => 'Could not send the WhatsApp invitation. Please check the number and try again.'], JSON_THROW_ON_ERROR);
            }
        }

        return json_encode([
            'status' => 'success',
            'message' => "Invitation sent to {$contact} via {$deliveryMethod->label()} to join {$familyName} as a {$role->value}.",
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'delivery_method' => $schema->string(),
            'email' => $schema->string(),
            'whatsapp_phone' => $schema->string(),
            'role' => $schema->string()->required(),
            'confirmed' => $schema->boolean(),
        ];
    }

    private function nullableStringFromRequest(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function validWhatsAppPhone(?string $value): ?string
    {
        if (! is_string($value) || preg_match('/^\+[1-9]\d{6,14}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function stringFromRequest(mixed $value, string $default): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }
}
