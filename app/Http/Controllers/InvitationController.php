<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Mail\FamilyInvitationMail;
use App\Models\FamilyInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function index(): Response
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->isAdmin()) {
            abort(403);
        }

        $invitations = FamilyInvitation::query()
            ->where('family_id', $user->family_id)
            ->with('inviter')
            ->latest()
            ->get()
            ->map(fn (FamilyInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'role_label' => $invitation->role->label(),
                'invited_by' => $invitation->inviter?->name,
                'is_accepted' => $invitation->isAccepted(),
                'is_expired' => $invitation->isExpired(),
                'is_pending' => $invitation->isPending(),
                'expires_at' => $invitation->expires_at->toDateString(),
                'created_at' => $invitation->created_at->toDateString(),
            ]);

        return Inertia::render('Family/Invitations', [
            'invitations' => $invitations,
            'family_name' => $user->family->name,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->isAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', new Enum(Role::class)],
        ]);

        // Check if there's already a pending invitation for this email in this family
        $existingInvitation = FamilyInvitation::query()
            ->where('family_id', $user->family_id)
            ->where('email', $validated['email'])
            ->pending()
            ->first();

        if ($existingInvitation) {
            return redirect()->back()->with('error', 'An invitation is already pending for this email.');
        }

        $invitation = FamilyInvitation::create([
            'family_id' => $user->family_id,
            'email' => $validated['email'],
            'role' => Role::from($validated['role']),
            'token' => Str::random(64),
            'invited_by' => $user->id,
            'expires_at' => now()->addDays(7),
        ]);

        $acceptUrl = route('invitations.accept', $invitation->token);

        Mail::to($validated['email'])->send(new FamilyInvitationMail($invitation, $acceptUrl));

        return redirect()->back()->with('success', "Invitation sent to {$validated['email']}.");
    }

    public function destroy(FamilyInvitation $invitation): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

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
                'family_name' => $invitation->family->name,
                'role_label' => $invitation->role->label(),
            ],
        ]);
    }
}
