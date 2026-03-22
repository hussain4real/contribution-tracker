<?php

use App\Models\Family;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

describe('NotificationController', function () {
    it('displays the notifications page', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $response = $this->actingAs($user)->get(route('notifications.index'));

        $response->assertSuccessful();
    });

    it('marks a notification as read', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $notification = DatabaseNotification::create([
            'id' => Str::uuid()->toString(),
            'type' => ContributionReminderNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'contribution_id' => 1,
                'family_name' => $family->name,
                'period_label' => 'March 2026',
                'amount_owed' => 4000,
                'due_date' => '2026-03-28',
                'type' => 'reminder',
            ],
        ]);

        $response = $this->actingAs($user)->patch(route('notifications.read', $notification));

        $response->assertRedirect();
        expect($notification->fresh()->read_at)->not->toBeNull();
    });

    it('marks all notifications as read', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        DatabaseNotification::create([
            'id' => Str::uuid()->toString(),
            'type' => ContributionReminderNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'contribution_id' => 1,
                'family_name' => $family->name,
                'period_label' => 'March 2026',
                'amount_owed' => 4000,
                'due_date' => '2026-03-28',
                'type' => 'reminder',
            ],
        ]);

        DatabaseNotification::create([
            'id' => Str::uuid()->toString(),
            'type' => ContributionReminderNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'contribution_id' => 2,
                'family_name' => $family->name,
                'period_label' => 'February 2026',
                'amount_owed' => 4000,
                'due_date' => '2026-02-28',
                'type' => 'follow_up',
            ],
        ]);

        $response = $this->actingAs($user)->post(route('notifications.mark-all-read'));

        $response->assertRedirect();
        expect($user->unreadNotifications()->count())->toBe(0);
    });

    it('prevents marking another users notification as read', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $otherUser = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $notification = DatabaseNotification::create([
            'id' => Str::uuid()->toString(),
            'type' => ContributionReminderNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $otherUser->id,
            'data' => [
                'contribution_id' => 1,
                'family_name' => $family->name,
                'period_label' => 'March 2026',
                'amount_owed' => 4000,
                'due_date' => '2026-03-28',
                'type' => 'reminder',
            ],
        ]);

        $response = $this->actingAs($user)->patch(route('notifications.read', $notification));

        $response->assertForbidden();
    });

    it('requires authentication to view notifications', function () {
        $response = $this->get(route('notifications.index'));

        $response->assertRedirect();
    });
});
