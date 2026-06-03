<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\InvitationDeliveryMethod;
use App\Enums\Role;
use App\Mail\FamilyInvitationMail;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function __construct(
        private WhatsAppService $whatsapp,
    ) {}

    public function index(): Response
    {
        $user = $this->authUser();

        if (! $user->isAdmin()) {
            abort(403);
        }

        $family = $user->family;

        $invitations = FamilyInvitation::query()
            ->where('family_id', $user->family_id)
            ->with('inviter')
            ->latest()
            ->get()
            ->map(fn (FamilyInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'delivery_method' => $invitation->delivery_method->value,
                'delivery_method_label' => $invitation->delivery_method->label(),
                'whatsapp_phone' => $invitation->whatsapp_phone,
                'contact' => $invitation->contact(),
                'role' => $invitation->role->value,
                'role_label' => $invitation->role->label(),
                'invited_by' => $invitation->inviter?->name,
                'is_accepted' => $invitation->isAccepted(),
                'is_expired' => $invitation->isExpired(),
                'is_pending' => $invitation->isPending(),
                'expires_at' => $invitation->expires_at->toDateString(),
                'created_at' => $invitation->created_at?->toDateString(),
            ]);

        return Inertia::render('Family/Invitations', [
            'invitations' => $invitations,
            'family_name' => $family instanceof Family ? $family->name : 'the family',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->authUser();

        if (! $user->isAdmin()) {
            abort(403);
        }

        if (! $request->has('delivery_method')) {
            $request->merge(['delivery_method' => InvitationDeliveryMethod::Email->value]);
        }

        $validated = $request->validate([
            'delivery_method' => ['required', new Enum(InvitationDeliveryMethod::class)],
            'email' => ['required_if:delivery_method,email', 'nullable', 'string', 'email', 'max:255'],
            'whatsapp_phone' => ['required_if:delivery_method,whatsapp', 'nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'role' => ['required', new Enum(Role::class)],
        ], [
            'email.required_if' => 'Enter an email address for email invitations.',
            'whatsapp_phone.required_if' => 'Enter a WhatsApp number for WhatsApp invitations.',
            'whatsapp_phone.regex' => 'Enter a valid WhatsApp number in international format (e.g. +2348012345678).',
        ]);
        $attributes = $this->invitationAttributes($validated);

        $deliveryMethod = $attributes['delivery_method'];
        $email = $attributes['email'];
        $whatsappPhone = $attributes['whatsapp_phone'];

        $existingMemberQuery = User::query()->where('family_id', $user->family_id);

        if ($deliveryMethod === InvitationDeliveryMethod::Email) {
            $existingMemberQuery->where('email', $email);
        } else {
            $existingMemberQuery->where('whatsapp_phone', $whatsappPhone);
        }

        $existingMember = $existingMemberQuery->first();

        if ($existingMember) {
            $message = $deliveryMethod === InvitationDeliveryMethod::Email
                ? 'This user is already a member of your family.'
                : 'This WhatsApp number already belongs to a member of your family.';

            return redirect()->back()->with('error', $message);
        }

        $existingInvitationQuery = FamilyInvitation::query()
            ->where('family_id', $user->family_id)
            ->where('delivery_method', $deliveryMethod)
            ->pending()
            ->when(
                $deliveryMethod === InvitationDeliveryMethod::Email,
                fn ($query) => $query->where('email', $email),
                fn ($query) => $query->where('whatsapp_phone', $whatsappPhone),
            );

        $existingInvitation = $existingInvitationQuery->first();

        if ($existingInvitation) {
            $message = $deliveryMethod === InvitationDeliveryMethod::Email
                ? 'An invitation is already pending for this email.'
                : 'An invitation is already pending for this WhatsApp number.';

            return redirect()->back()->with('error', $message);
        }

        $invitation = FamilyInvitation::create([
            'family_id' => $user->family_id,
            'email' => $email,
            'delivery_method' => $deliveryMethod,
            'whatsapp_phone' => $whatsappPhone,
            'role' => $attributes['role'],
            'token' => Str::random(64),
            'invited_by' => $user->id,
            'expires_at' => now()->addDays(7),
        ]);

        $acceptUrl = route('invitations.accept', $invitation->token);

        if ($deliveryMethod === InvitationDeliveryMethod::Email) {
            Mail::to($email)->send(new FamilyInvitationMail($invitation, $acceptUrl));
        } else {
            $result = $this->sendWhatsAppInvitation($invitation, $acceptUrl);

            if (! $result['success']) {
                $invitation->delete();

                return redirect()->back()->withErrors([
                    'whatsapp_phone' => 'Could not send the WhatsApp invitation. Please check the number and try again.',
                ])->withInput();
            }
        }

        return redirect()->back()->with('success', "Invitation sent to {$invitation->contact()} via {$deliveryMethod->label()}.");
    }

    public function destroy(FamilyInvitation $invitation): RedirectResponse
    {
        $user = $this->authUser();

        if (! $user->isAdmin() || $invitation->family_id !== $user->family_id) {
            abort(403);
        }

        $invitation->delete();

        return redirect()->back()->with('success', 'Invitation cancelled.');
    }

    /**
     * Public route to show invitation acceptance page.
     */
    public function accept(string $token): Response|RedirectResponse
    {
        $invitation = FamilyInvitation::query()
            ->where('token', $token)
            ->with('family')
            ->first();

        if (! $invitation || $invitation->isAccepted()) {
            return redirect()->route('home')->with('error', 'This invitation is no longer valid.');
        }

        if ($invitation->isExpired()) {
            return redirect()->route('home')->with('error', 'This invitation has expired.');
        }

        return Inertia::render('auth/AcceptInvitation', [
            'invitation' => [
                'token' => $invitation->token,
                'email' => $invitation->email,
                'delivery_method' => $invitation->delivery_method->value,
                'whatsapp_phone' => $invitation->whatsapp_phone,
                'family_name' => $invitation->family instanceof Family ? $invitation->family->name : 'the family',
                'role_label' => $invitation->role->label(),
            ],
        ]);
    }

    /**
     * Send the invitation acceptance link over WhatsApp.
     *
     * @return array{success: bool, wa_message_id: ?string, error: ?string}
     */
    private function sendWhatsAppInvitation(FamilyInvitation $invitation, string $acceptUrl): array
    {
        return $this->whatsapp->sendInvitation(
            (string) $invitation->whatsapp_phone,
            $invitation->family instanceof Family ? $invitation->family->name : 'the family',
            $invitation->role->label(),
            $acceptUrl,
        );
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{delivery_method: InvitationDeliveryMethod, email: string|null, whatsapp_phone: string|null, role: Role}
     */
    private function invitationAttributes(mixed $validated): array
    {
        $validated = is_array($validated) ? $validated : [];

        $deliveryMethod = InvitationDeliveryMethod::from(
            $this->stringValue($validated['delivery_method'] ?? InvitationDeliveryMethod::Email->value),
        );

        return [
            'delivery_method' => $deliveryMethod,
            'email' => $deliveryMethod === InvitationDeliveryMethod::Email ? $this->nullableString($validated['email'] ?? null) : null,
            'whatsapp_phone' => $deliveryMethod === InvitationDeliveryMethod::WhatsApp ? $this->nullableString($validated['whatsapp_phone'] ?? null) : null,
            'role' => Role::from($this->stringValue($validated['role'] ?? Role::Member->value)),
        ];
    }
}
