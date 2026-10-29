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
