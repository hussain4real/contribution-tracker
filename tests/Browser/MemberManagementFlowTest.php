<?php

/**
 * T059 [US3] Browser test for member management flow
 *
 * Run with: ./vendor/bin/pest tests/Browser/ --headed
 */

use App\Models\User;

describe('Member Management Flow (Browser)', function () {
    beforeEach(function () {
        $this->superAdmin = User::factory()->superAdmin()->create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
        ]);
    });

    it('can navigate to members page from dashboard', function () {
        $page = visit('/login');

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->assertSee('Dashboard')
            ->click('Members')
            ->assertSee('Family Members')
            ->assertNoJavaScriptErrors();
    });

    it('can access create member form', function () {
        $page = visit('/login');

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->click('Members')
            ->assertSee('Family Members')
            ->click('Add Member')
            ->assertSee('Add New Member')
            ->assertNoJavaScriptErrors();
    });

    it('can create a new member with student category', function () {
        $page = visit('/login');

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->click('Members')
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

        // Verify in database
        expect(User::where('email', 'student@test.com')->exists())->toBeTrue();
    });

    it('displays correct monthly amount for student category', function () {
        // Create a student member
        User::factory()->member()->student()->create([
            'name' => 'Student User',
        ]);

        $page = visit('/login');

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->click('Members')
            ->assertSee('Student User')
            ->assertSee('₦1,000') // Student monthly amount
            ->assertNoJavaScriptErrors();
    });

    it('can edit member category from student to employed', function () {
        $member = User::factory()->member()->student()->create([
            'name' => 'Changing Category',
            'email' => 'change@test.com',
        ]);

        $page = visit('/login');

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->click('Members')
            ->assertSee('Changing Category')
            ->click('Edit') // Click edit on the member row
            ->assertSee('Edit Member')
            ->select('category', 'employed')
            ->click('Save Changes')
            ->assertSee('₦4,000') // Employed monthly amount
            ->assertNoJavaScriptErrors();

        // Verify in database
        $member->refresh();
        expect($member->category->value)->toBe('employed');
    });

    it('can archive a member', function () {
        $member = User::factory()->member()->create([
            'name' => 'Archive Me',
        ]);

        $page = visit('/login');

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->click('Members')
            ->assertSee('Archive Me')
            ->click('Archive') // Click archive button
            ->assertNoJavaScriptErrors();

        // Verify in database
        $member->refresh();
        expect($member->isArchived())->toBeTrue();
    });

    it('can view archived members and restore them', function () {
        $member = User::factory()->member()->create([
            'name' => 'Archived User',
            'archived_at' => now(),
        ]);

        $page = visit('/login');

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->click('Members')
            ->click('Show Archived')
            ->assertSee('Archived User')
            ->click('Restore')
            ->assertNoJavaScriptErrors();

        // Verify in database
        $member->refresh();
        expect($member->isArchived())->toBeFalse();
    });

    it('shows member details on show page', function () {
        $member = User::factory()->member()->employed()->create([
            'name' => 'Detail View User',
            'email' => 'detail@test.com',
        ]);

        $page = visit('/login');

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->click('Members')
            ->assertSee('Detail View User')
            ->click('View') // Click view button
            ->assertSee('Detail View User')
            ->assertSee('detail@test.com')
            ->assertSee('Employed')
            ->assertSee('₦4,000')
            ->assertNoJavaScriptErrors();
    });

    it('member cannot access create member page', function () {
        $member = User::factory()->member()->create([
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
        ]);

        $page = visit('/login');

        $page->fill('email', 'member@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->assertSee('Dashboard');

        // Try to navigate directly to create member page
        $page = visit('/members/create');
        $page->assertSee('403'); // Should be forbidden
    });

    it('financial secretary cannot access create member page', function () {
        $fs = User::factory()->financialSecretary()->create([
            'email' => 'fs@test.com',
            'password' => bcrypt('password'),
        ]);

        $page = visit('/login');

        $page->fill('email', 'fs@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->assertSee('Dashboard');

        // Try to navigate directly to create member page
        $page = visit('/members/create');
        $page->assertSee('403'); // Should be forbidden
    });
});
