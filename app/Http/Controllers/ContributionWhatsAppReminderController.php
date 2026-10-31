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
 * Lets Admin / Financial Secretary manually fire the approved
 * `contribution_reminder` WhatsApp template for a single contribution.
 */
class ContributionWhatsAppReminderController extends Controller
{
    /**
     * Send a manual WhatsApp reminder for the given contribution.
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

        if ($member === null || $member->whatsapp_verified_at === null || $member->whatsapp_phone === null) {
            return back()->with('error', 'Member has not verified a WhatsApp number.');
        }

        if ($contribution->balance <= 0) {
            return back()->with('error', 'This contribution is already fully paid.');
        }

        $type = CarbonImmutable::now()->day >= 28 ? 'follow_up' : 'reminder';

        $notification = (new ContributionReminderNotification($contribution, $type))->onlyChannels(['whatsapp']);
        if ($notification->via($member) === []) {
            return back()->with('error', 'This member has disabled WhatsApp contribution reminders.');
        }
        app(QueueContributionReminder::class)->queue($member, $notification);

        return back()->with('success', "WhatsApp reminder queued for {$member->name}.");
    }
}
