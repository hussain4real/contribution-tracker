<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contribution;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Lets Admin / Financial Secretary manually fire an email reminder
 * for a single contribution.
 */
class ContributionEmailReminderController extends Controller
{
    /**
     * Send a manual email reminder for the given contribution.
     */
    public function send(Contribution $contribution): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->canRecordPayments()) {
            abort(403);
        }

        $contribution->loadMissing(['user', 'family']);

        if ($contribution->family_id !== $user->family_id) {
            abort(403);
        }

        $member = $contribution->user;

        if ($member === null || blank($member->email)) {
            return back()->with('error', 'Member does not have an email address.');
        }

        if ($contribution->balance <= 0) {
            return back()->with('error', 'This contribution is already fully paid.');
        }

        $type = CarbonImmutable::now()->day >= 28 ? 'follow_up' : 'reminder';

        $member->notify(
            (new ContributionReminderNotification($contribution, $type))->onlyChannels(['mail'])
        );

        return back()->with('success', "Email reminder sent to {$member->name}.");
    }
}
