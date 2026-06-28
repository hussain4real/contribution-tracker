<?php

declare(strict_types=1);

use App\Models\User;

describe('Settings password flow', function () {
    beforeEach(function () {
        $this->user = User::factory()->withoutTwoFactor()->create([
            'email' => 'settings-password@test.com',
            'password' => bcrypt('password'),
        ]);
    });

    it('renders the password settings page without JavaScript errors', function () {
        $passwordConfirmPath = parse_url(route('password.confirm'), PHP_URL_PATH) ?: '/user/confirm-password';
        $securitySettingsPath = parse_url(route('security.edit'), PHP_URL_PATH) ?: '/settings/security';

        $page = visit('/login');

        $page->fill('email', 'settings-password@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->navigate(route('security.edit'))
            ->assertPathIs($passwordConfirmPath)
            ->fill('password', 'password')
            ->click('@confirm-password-button')
            ->assertPathIs($securitySettingsPath)
            ->assertSee('Update password')
            ->assertNoJavaScriptErrors();
    });
});
