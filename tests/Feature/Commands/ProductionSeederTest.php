<?php

use App\Enums\Role;
use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a super admin account with environment variables', function () {
    putenv('ADMIN_EMAIL=admin@production.test');
    putenv('ADMIN_PASSWORD=secure-password');
    putenv('ADMIN_NAME=Family Admin');

    $this->artisan('db:seed', ['--class' => 'ProductionSeeder'])
        ->assertSuccessful();

    $admin = User::query()->where('email', 'admin@production.test')->first();

    expect($admin)->not->toBeNull();
    expect($admin->name)->toBe('Family Admin');
    expect($admin->role)->toBe(Role::SuperAdmin);
    expect($admin->category)->toBeNull();
    expect($admin->email_verified_at)->not->toBeNull();

    putenv('ADMIN_EMAIL');
    putenv('ADMIN_PASSWORD');
    putenv('ADMIN_NAME');
});

it('uses default name when ADMIN_NAME is not set', function () {
    putenv('ADMIN_EMAIL=admin@production.test');
    putenv('ADMIN_PASSWORD=secure-password');

    $this->artisan('db:seed', ['--class' => 'ProductionSeeder'])
        ->assertSuccessful();

    $admin = User::query()->where('email', 'admin@production.test')->first();

    expect($admin->name)->toBe('Super Admin');

    putenv('ADMIN_EMAIL');
    putenv('ADMIN_PASSWORD');
});

it('fails when email is not provided', function () {
    putenv('ADMIN_PASSWORD=secure-password');

    $this->artisan('db:seed', ['--class' => 'ProductionSeeder'])
        ->expectsOutputToContain('ADMIN_EMAIL and ADMIN_PASSWORD environment variables are required');

    expect(User::query()->count())->toBe(0);

    putenv('ADMIN_PASSWORD');
});

it('fails when password is not provided', function () {
    putenv('ADMIN_EMAIL=admin@production.test');

    $this->artisan('db:seed', ['--class' => 'ProductionSeeder'])
        ->expectsOutputToContain('ADMIN_EMAIL and ADMIN_PASSWORD environment variables are required');

    expect(User::query()->count())->toBe(0);

    putenv('ADMIN_EMAIL');
});

it('skips creation if user with email already exists', function () {
    User::factory()->superAdmin()->create(['email' => 'admin@production.test']);

    putenv('ADMIN_EMAIL=admin@production.test');
    putenv('ADMIN_PASSWORD=secure-password');

    $this->artisan('db:seed', ['--class' => 'ProductionSeeder'])
        ->expectsOutputToContain('already exists');

    expect(User::query()->where('email', 'admin@production.test')->count())->toBe(1);

    putenv('ADMIN_EMAIL');
    putenv('ADMIN_PASSWORD');
});

it('does not create any demo data', function () {
    putenv('ADMIN_EMAIL=admin@production.test');
    putenv('ADMIN_PASSWORD=secure-password');

    $this->artisan('db:seed', ['--class' => 'ProductionSeeder'])
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1);
    expect(Contribution::query()->count())->toBe(0);
    expect(Payment::query()->count())->toBe(0);

    putenv('ADMIN_EMAIL');
    putenv('ADMIN_PASSWORD');
});
