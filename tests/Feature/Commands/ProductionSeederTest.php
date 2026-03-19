<?php

use App\Enums\Role;
use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.admin_email', null);
    config()->set('app.admin_password', null);
    config()->set('app.admin_name', null);
});

it('creates a super admin account with environment variables', function () {
    config()->set('app.admin_email', 'admin@production.test');
    config()->set('app.admin_password', 'secure-password');
    config()->set('app.admin_name', 'Family Admin');

    $this->artisan('db:seed', ['--class' => 'ProductionSeeder'])
        ->assertSuccessful();

    $admin = User::query()->where('email', 'admin@production.test')->first();

    expect($admin)->not->toBeNull();
    expect($admin->name)->toBe('Family Admin');
    expect($admin->role)->toBe(Role::Admin);
    expect($admin->category)->toBeNull();
    expect($admin->email_verified_at)->not->toBeNull();
});

it('uses default name when ADMIN_NAME is not set', function () {
    config()->set('app.admin_email', 'admin@production.test');
    config()->set('app.admin_password', 'secure-password');

    $this->artisan('db:seed', ['--class' => 'ProductionSeeder'])
        ->assertSuccessful();

    $admin = User::query()->where('email', 'admin@production.test')->first();

    expect($admin->name)->toBe('Admin');
});

it('fails when email is not provided', function () {
    config()->set('app.admin_password', 'secure-password');

    $this->artisan('db:seed', ['--class' => 'ProductionSeeder'])
        ->expectsOutputToContain('ADMIN_EMAIL and ADMIN_PASSWORD environment variables are required');

    expect(User::query()->count())->toBe(0);
});

it('fails when password is not provided', function () {
    config()->set('app.admin_email', 'admin@production.test');

    $this->artisan('db:seed', ['--class' => 'ProductionSeeder'])
        ->expectsOutputToContain('ADMIN_EMAIL and ADMIN_PASSWORD environment variables are required');

    expect(User::query()->count())->toBe(0);
});

it('skips creation if user with email already exists', function () {
    User::factory()->admin()->create(['email' => 'admin@production.test']);

    config()->set('app.admin_email', 'admin@production.test');
    config()->set('app.admin_password', 'secure-password');

    $this->artisan('db:seed', ['--class' => 'ProductionSeeder'])
        ->expectsOutputToContain('already exists');

    expect(User::query()->where('email', 'admin@production.test')->count())->toBe(1);
});

it('does not create any demo data', function () {
    config()->set('app.admin_email', 'admin@production.test');
    config()->set('app.admin_password', 'secure-password');

    $this->artisan('db:seed', ['--class' => 'ProductionSeeder'])
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1);
    expect(Contribution::query()->count())->toBe(0);
    expect(Payment::query()->count())->toBe(0);
});
