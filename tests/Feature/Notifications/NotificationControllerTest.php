<?php

use App\Models\Family;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

describe('NotificationController', function () {
    it('displays the notifications page', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $response = $this->actingAs($user)->get(route('notifications.index'));

        $response->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->has('notificationFeed.data')
                ->where('notificationSummary.total', 0)
                ->where('notificationFilters.status', 'all')
                ->where('notificationFilters.type', 'all')
            );
    });

    it('displays notification feed data without colliding with shared notification badges', function () {
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

        $response = $this->actingAs($user)->get(route('notifications.index'));

        $response->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->has('notificationFeed.data', 1)
                ->where('notificationFeed.data.0.data.period_label', 'March 2026')
                ->where('notificationSummary.total', 1)
                ->where('notificationSummary.unread', 1)
                ->where('notifications.unread_count', 1)
            );
    });

    it('filters notification feed by read status and notification type', function () {
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
            'read_at' => now(),
        ]);

        DatabaseNotification::create([
            'id' => Str::uuid()->toString(),
            'type' => ContributionReminderNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'contribution_id' => 2,
                'family_name' => $family->name,
                'period_label' => 'April 2026',
                'amount_owed' => 4000,
                'due_date' => '2026-04-28',
                'type' => 'follow_up',
            ],
        ]);

        $response = $this->actingAs($user)->get(route('notifications.index', [
            'status' => 'unread',
            'type' => 'follow_up',
        ]));

        $response->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->has('notificationFeed.data', 1)
                ->where('notificationFeed.data.0.data.type', 'follow_up')
                ->where('notificationFilters.status', 'unread')
                ->where('notificationFilters.type', 'follow_up')
            );
    });

    it('filters notification types when notification data is stored as text', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        DB::table('notifications')->insert([
            [
                'id' => Str::uuid()->toString(),
                'type' => ContributionReminderNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'period_label' => 'May 2026',
                    'amount_owed' => 5000,
                    'type' => 'reminder',
                ]),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid()->toString(),
                'type' => ContributionReminderNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'period_label' => 'April 2026',
                    'amount_owed' => 5000,
                    'type' => 'follow_up',
                ]),
                'read_at' => null,
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
        ]);

        $response = $this->actingAs($user)->get(route('notifications.index', [
            'type' => 'follow_up',
        ]));

        $response->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->has('notificationFeed.data', 1)
                ->where('notificationFeed.data.0.data.type', 'follow_up')
                ->where('notificationSummary.reminders', 1)
                ->where('notificationSummary.follow_ups', 1)
            );
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
