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
 * Lets Admin / Financial Secretary manually fire a browser push reminder
 * for a single contribution.
 */
class ContributionWebPushReminderController extends Controller
{
    /**
     * Send a manual web push reminder for the given contribution.
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

        if ($member === null || ! $member->pushSubscriptions()->exists()) {
            return back()->with('error', 'Member has not enabled browser notifications.');
        }

        if ($contribution->balance <= 0) {
            return back()->with('error', 'This contribution is already fully paid.');
        }

        $type = CarbonImmutable::now()->day >= 28 ? 'follow_up' : 'reminder';

        $member->notify(
            (new ContributionReminderNotification($contribution, $type))->onlyChannels(['webpush'])
        );

        return back()->with('success', "Browser reminder sent to {$member->name}.");
    }
}
