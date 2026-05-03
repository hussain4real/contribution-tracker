<?php

namespace App\Mcp\Tools;

use App\Mcp\Resources\FamilyFundReviewApp;
use App\Mcp\Tools\Concerns\AuthorizesFamilyFundReview;
use App\Models\Contribution;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Ui\Enums\Visibility;

#[Description('Previews or sends selected contribution reminders from the Family Fund Review app.')]
#[RendersApp(resource: FamilyFundReviewApp::class, visibility: [Visibility::App])]
class SendFamilyFundReviewReminders extends Tool
{
    use AuthorizesFamilyFundReview;

    /**
     * @var array<int, string>
     */
    private const CHANNELS = ['mail', 'whatsapp', 'webpush'];

    public function handle(Request $request): Response
    {
        $user = $this->authorizedUser($request);

        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'contribution_ids' => ['required', 'array', 'min:1'],
            'contribution_ids.*' => ['integer'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', 'in:mail,whatsapp,webpush'],
            'confirmed' => ['nullable', 'boolean'],
        ]);

        $contributionIds = collect($validated['contribution_ids'])
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $channels = collect($validated['channels'])
            ->intersect(self::CHANNELS)
            ->unique()
            ->values();

        $preview = $this->preview($user, $contributionIds, $channels);

        if (! (bool) ($validated['confirmed'] ?? false)) {
            return Response::json([
                'status' => 'confirmation_required',
                'message' => $this->previewMessage($preview['valid'], $preview['invalid']),
                'valid_count' => count($preview['valid']),
                'invalid_count' => count($preview['invalid']),
                'valid' => $this->publicEntries($preview['valid']),
                'invalid' => $preview['invalid'],
            ]);
        }

        foreach ($preview['valid'] as $entry) {
            $entry['member']->notify(
                (new ContributionReminderNotification($entry['contribution'], $entry['type']))
                    ->onlyChannels($entry['channels'])
            );
        }

        $sentCount = count($preview['valid']);
        $channelDeliveryCount = collect($preview['valid'])->sum(fn (array $entry): int => count($entry['channels']));

        return Response::json([
            'status' => $sentCount > 0 ? 'success' : 'no_valid_reminders',
            'message' => $sentCount > 0
                ? "{$sentCount} reminder(s) queued across {$channelDeliveryCount} channel delivery attempt(s)."
                : 'No valid reminders were available to send.',
            'sent_count' => $sentCount,
            'channel_delivery_count' => $channelDeliveryCount,
            'invalid_count' => count($preview['invalid']),
            'channel_counts' => $this->channelCounts($preview['valid']),
            'sent' => $this->publicEntries($preview['valid']),
            'invalid' => $preview['invalid'],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'contribution_ids' => $schema->array()
                ->items($schema->integer())
                ->min(1)
                ->unique()
                ->required()
                ->description('Contribution IDs selected for reminders.'),
            'channels' => $schema->array()
                ->items($schema->string()->enum(self::CHANNELS))
                ->min(1)
                ->unique()
                ->required()
                ->description('Reminder channels to use: mail, whatsapp, and/or webpush.'),
            'confirmed' => $schema->boolean()
                ->description('Must be false or omitted for preview, then true after explicit user confirmation.'),
        ];
    }

    /**
     * @param  Collection<int, int>  $contributionIds
     * @param  Collection<int, string>  $channels
     * @return array{valid: array<int, array<string, mixed>>, invalid: array<int, array<string, mixed>>}
     */
    private function preview(User $user, Collection $contributionIds, Collection $channels): array
    {
        $contributions = Contribution::query()
            ->where('family_id', $user->family_id)
            ->whereIn('id', $contributionIds)
            ->with(['user' => fn ($query) => $query->withExists('pushSubscriptions'), 'family', 'payments'])
            ->get()
            ->keyBy('id');

        $valid = [];
        $invalid = [];
        $type = CarbonImmutable::now()->day >= 28 ? 'follow_up' : 'reminder';

        foreach ($contributionIds as $contributionId) {
            /** @var Contribution|null $contribution */
            $contribution = $contributions->get($contributionId);

            if ($contribution === null || $contribution->user === null || $contribution->user->isArchived()) {
                $invalid[] = [
                    'contribution_id' => $contributionId,
                    'reason' => 'Contribution was not found for this family.',
                ];

                continue;
            }

            if ($contribution->balance <= 0) {
                $invalid[] = [
                    'contribution_id' => $contributionId,
                    'member' => $contribution->user->name,
                    'reason' => 'Contribution is fully paid.',
                ];

                continue;
            }

            $eligibleChannels = $this->eligibleChannels($contribution->user);
            $selectedChannels = $channels->intersect($eligibleChannels)->values()->all();

            if ($selectedChannels === []) {
                $invalid[] = [
                    'contribution_id' => $contributionId,
                    'member' => $contribution->user->name,
                    'reason' => 'No selected reminder channel is available for this member.',
                    'eligible_channels' => $eligibleChannels,
                ];

                continue;
            }

            $valid[] = [
                'contribution' => $contribution,
                'member' => $contribution->user,
                'type' => $type,
                'channels' => $selectedChannels,
                'rejected_channels' => $channels->diff($selectedChannels)->values()->all(),
                'contribution_id' => $contribution->id,
                'member_name' => $contribution->user->name,
                'period_label' => $contribution->period_label,
                'balance' => $contribution->balance,
            ];
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function eligibleChannels(User $member): array
    {
        $channels = [];

        if (filled($member->email)) {
            $channels[] = 'mail';
        }

        if ($member->hasVerifiedWhatsApp()) {
            $channels[] = 'whatsapp';
        }

        if ((bool) ($member->push_subscriptions_exists ?? false)) {
            $channels[] = 'webpush';
        }

        return $channels;
    }

    /**
     * @param  array<int, array<string, mixed>>  $valid
     * @param  array<int, array<string, mixed>>  $invalid
     */
    private function previewMessage(array $valid, array $invalid): string
    {
        return count($valid).' reminder(s) can be sent. '.count($invalid).' selection(s) need attention.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $valid
     * @return array<string, int>
     */
    private function channelCounts(array $valid): array
    {
        $counts = [
            'mail' => 0,
            'whatsapp' => 0,
            'webpush' => 0,
        ];

        foreach ($valid as $entry) {
            foreach ($entry['channels'] as $channel) {
                $counts[$channel]++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function publicEntries(array $entries): array
    {
        return collect($entries)
            ->map(fn (array $entry): array => [
                'contribution_id' => $entry['contribution_id'],
                'member' => $entry['member_name'],
                'period_label' => $entry['period_label'],
                'balance' => $entry['balance'],
                'channels' => $entry['channels'],
                'rejected_channels' => $entry['rejected_channels'],
            ])
            ->values()
            ->all();
    }
}
