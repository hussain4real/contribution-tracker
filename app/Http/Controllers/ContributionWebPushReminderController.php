<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contribution;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use App\Services\QueueContributionReminder;
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

        if ($user->membershipForFamilyId($contribution->family_id) === null) {
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

        $notification = (new ContributionReminderNotification($contribution, $type))->onlyChannels(['webpush']);
        if ($notification->via($member) === []) {
            return back()->with('error', 'This member has disabled browser contribution reminders.');
        }
        app(QueueContributionReminder::class)->queue($member, $notification);

        return back()->with('success', "Browser reminder queued for {$member->name}.");
    }
}
