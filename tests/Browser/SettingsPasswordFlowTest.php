<?php

use App\Models\User;

describe('Settings password flow', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'email' => 'settings-password@test.com',
            'password' => bcrypt('password'),
        ]);
    });

    it('renders the password settings page without JavaScript errors', function () {
        $page = visit('/login');

        $page->fill('email', 'settings-password@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->navigate(route('security.edit'))
            ->assertSee('Update password')
            ->assertNoJavaScriptErrors();
    });
});
