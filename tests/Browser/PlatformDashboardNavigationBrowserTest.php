<?php

declare(strict_types=1);

it('lets a super admin open their family dashboard from the platform sidebar', function () {
    $family = createBrowserFamily(['name' => 'Receipt Test Family']);
    $user = createBrowserSuperAdmin($family);

    loginBrowserAs($user)
        ->assertSee('Platform Overview')
        ->assertSee('Family dashboard')
        ->screenshot(filename: 'platform-family-dashboard-menu')
        ->click('Family dashboard')
        ->assertPathIs("/{$family->slug}/dashboard")
        ->assertSee('Receipt Test Family')
        ->assertNoJavaScriptErrors();
});

it('returns the impersonating administrator to the full platform dashboard', function () {
    $family = createBrowserFamily();
    $admin = createBrowserSuperAdmin($family);
    $member = createBrowserMember($family);
    $page = loginBrowserAs($admin);
    $page->navigate(route('filament.platform.resources.users.view', ['record' => $member]))
        ->click('Impersonate')->click('Confirm')->assertSee('Stop Impersonating')
        ->click('Stop Impersonating')->assertPathIs('/platform')
        ->assertSee('Platform Overview')->assertSee($admin->name)
        ->assertNoJavaScriptErrors();
});
