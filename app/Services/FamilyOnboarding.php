<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Family;
use App\Support\EffectiveLedger;

class FamilyOnboarding
{
    public function __construct(private readonly EffectiveLedger $ledger) {}

    /** @return array{completed: int, total: int, steps: list<array{key: string, title: string, description: string, completed: bool, optional: bool, url: string}>} */
    public function forFamily(Family $family): array
    {
        $settings = route('family.settings', ['current_family' => $family->slug]);
        $steps = [
            ['key' => 'workspace', 'title' => 'Family workspace created', 'description' => 'Review your family name, currency and contribution due day.', 'completed' => true, 'optional' => false, 'url' => $settings],
            ['key' => 'rules', 'title' => 'Set contribution rules', 'description' => 'Choose the categories and monthly amounts that fit your family.', 'completed' => $family->categories()->exists(), 'optional' => false, 'url' => $settings.'#contribution-categories'],
            ['key' => 'members', 'title' => 'Add or invite a member', 'description' => 'Invite a member to sign in, or add a managed member from Members.', 'completed' => $family->memberships()->active()->count() > 1 || $family->invitations()->pending()->exists(), 'optional' => false, 'url' => route('family.invitations', ['current_family' => $family->slug])],
            ['key' => 'payment', 'title' => 'Receive the first contribution', 'description' => 'Record a real payment or let a member pay online. Review the details before posting.', 'completed' => $this->ledger->paymentsTotal($family->id) > 0, 'optional' => false, 'url' => route('payments.index', ['current_family' => $family->slug])],
            ['key' => 'bank', 'title' => 'Add bank details', 'description' => 'Optional for cash collections. Add bank details when you want to set up online payments.', 'completed' => filled($family->bank_name) && filled($family->account_name) && filled($family->account_number), 'optional' => true, 'url' => $settings],
            ['key' => 'reminders', 'title' => 'Choose a reminder schedule', 'description' => 'Optional: choose reminder days, time and channels. Members retain their own preferences.', 'completed' => $family->reminder_settings !== null, 'optional' => true, 'url' => $settings.'#reminder-settings'],
        ];
        $required = array_filter($steps, fn (array $step): bool => ! $step['optional']);

        return ['completed' => count(array_filter($required, fn (array $step): bool => $step['completed'])), 'total' => count($required), 'steps' => $steps];
    }
}
