<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Family;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

describe('Platform Password Reset', function () {
    it('allows super admin to send a password reset email', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create([
            'family_id' => $family->id,
            'email' => 'member@example.com',
        ]);

        $this->actingAs($superAdmin);

        Livewire::test(ViewUser::class, ['record' => $member->getRouteKey()])
            ->callAction('sendPasswordReset')
            ->assertNotified('Password reset email sent to member@example.com.');

        Notification::assertSentTo($member, ResetPassword::class);
    });

    it('denies non-super-admin from accessing the user action surface', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get(UserResource::getUrl('view', ['record' => $member]))
            ->assertForbidden();

        Notification::assertNothingSent();
    });

    it('sends reset to the correct user email', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member1 = User::factory()->member()->create(['family_id' => $family->id]);
        $member2 = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin);

        Livewire::test(ViewUser::class, ['record' => $member1->getRouteKey()])
            ->callAction('sendPasswordReset');

        Notification::assertSentTo($member1, ResetPassword::class);
        Notification::assertNotSentTo($member2, ResetPassword::class);
    });
});
