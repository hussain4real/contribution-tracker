<?php

/**
 * T059 [US3] Browser test for member management flow
 *
 * Run with: ./vendor/bin/pest tests/Browser/ --headed
 */

use App\Models\User;

describe('Member Management Flow (Browser)', function () {
    beforeEach(function () {
        $this->family = createBrowserFamily();
        $this->admin = createBrowserAdmin($this->family, [
            'email' => 'admin@test.com',
        ]);
    });

    it('can navigate to members page from dashboard', function () {
        $page = loginBrowserAs($this->admin);

        $page->click('Members')
            ->assertSee('Family Members')
            ->assertNoJavaScriptErrors();
    });

    it('can access create member form', function () {
        $page = loginBrowserAs($this->admin);

        $page->click('Members')
            ->assertSee('Family Members')
            ->click('Add Member')
            ->assertSee('Add New Member')
            ->assertNoJavaScriptErrors();
    });

    it('can create a new member with student category', function () {
        $page = loginBrowserAs($this->admin);

        $page->click('Members')
            ->click('Add Member')
            ->fill('name', 'Test Student')
            ->fill('email', 'student@test.com')
            ->fill('password', 'password123')
            ->fill('password_confirmation', 'password123')
            ->select('category', 'student')
            ->select('role', 'member')
            ->click('Create Member')
            ->assertSee('Family Members')
            ->assertSee('Test Student')
            ->assertNoJavaScriptErrors();

        expect(User::where('email', 'student@test.com')
            ->where('family_id', $this->family->id)
            ->exists())->toBeTrue();
    });

    it('displays correct monthly amount for student category', function () {
        User::factory()
            ->withoutTwoFactor()
            ->member()
            ->student()
            ->create([
                'family_id' => $this->family->id,
                'name' => 'Student User',
            ]);

        $page = loginBrowserAs($this->admin);

        $page->click('Members')
            ->assertSee('Student User')
            ->assertSee('₦1,000') // Student monthly amount
            ->assertNoJavaScriptErrors();
    });

    it('can edit member category from student to employed', function () {
        $member = User::factory()
            ->withoutTwoFactor()
            ->member()
            ->student()
            ->create([
                'family_id' => $this->family->id,
                'name' => 'Changing Category',
                'email' => 'change@test.com',
            ]);

        $page = loginBrowserAs($this->admin);

        $page->navigate("/members/{$member->id}/edit")
            ->assertSee('Edit Member')
            ->select('category', 'employed')
            ->click('Save Changes')
            ->assertNoJavaScriptErrors();

        $member->refresh();

        expect($member->category->value)->toBe('employed');
    });

    it('can archive a member', function () {
        $member = createBrowserMember($this->family, [
            'name' => 'Archive Me',
        ]);

        $page = loginBrowserAs($this->admin);

        $page->navigate("/members/{$member->id}")
            ->assertSee('Archive Me')
            ->assertNoJavaScriptErrors();

        $page->script('() => { window.confirm = () => true; }');

        $page->click('@archive-member-button')
            ->wait(0.5)
            ->assertPathIs('/members')
            ->assertSee('Family Members')
            ->assertNoJavaScriptErrors();

        $member->refresh();

        expect($member->isArchived())->toBeTrue();
    });

    it('can view archived members and restore them', function () {
        $member = createBrowserMember($this->family, [
            'name' => 'Archived User',
            'archived_at' => now(),
        ]);

        $page = loginBrowserAs($this->admin);

        $page->click('Members')
            ->click('Show Archived')
            ->assertSee('Archived User')
            ->navigate("/members/{$member->id}")
            ->assertSee('This member is archived');

        $page->script('() => { window.confirm = () => true; }');

        $page->click('@restore-member-button')
            ->wait(0.5)
            ->assertPathIs("/members/{$member->id}")
            ->assertSee('Archived User')
            ->assertNoJavaScriptErrors();

        $member->refresh();

        expect($member->isArchived())->toBeFalse();
    });

    it('shows member details on show page', function () {
        $member = createBrowserMember($this->family, [
            'name' => 'Detail View User',
            'email' => 'detail@test.com',
        ]);

        $page = loginBrowserAs($this->admin);

        $page->navigate("/members/{$member->id}")
            ->assertSee('Detail View User')
            ->assertSee('detail@test.com')
            ->assertSee('Employed')
            ->assertSee('₦4,000')
            ->assertNoJavaScriptErrors();
    });

    it('member cannot access create member page', function () {
        $member = createBrowserMember($this->family, [
            'email' => 'member@test.com',
        ]);

        $page = loginBrowserAs($member);

        $page->navigate('/members/create')
            ->assertSee('403');
    });

    it('financial secretary cannot access create member page', function () {
        $fs = createBrowserFinancialSecretary($this->family, [
            'email' => 'fs@test.com',
        ]);

        $page = loginBrowserAs($fs);

        $page->navigate('/members/create')
            ->assertSee('403');
    });
});
