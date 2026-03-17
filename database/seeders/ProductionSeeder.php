<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ProductionSeeder extends Seeder
{
    /**
     * Seed the production database with only the Super Admin account.
     *
     * Usage: php artisan db:seed --class=ProductionSeeder
     *
     * Environment variables:
     *   ADMIN_NAME  - Super Admin display name (default: Super Admin)
     *   ADMIN_EMAIL - Super Admin email (required)
     *   ADMIN_PASSWORD - Super Admin password (required)
     */
    public function run(): void
    {
        $email = config('app.admin_email');
        $password = config('app.admin_password');
        $name = config('app.admin_name') ?: 'Super Admin';

        if (! $email || ! $password) {
            $this->command->error('ADMIN_EMAIL and ADMIN_PASSWORD environment variables are required.');
            $this->command->info('Set them via: ADMIN_EMAIL=you@example.com ADMIN_PASSWORD=secret php artisan db:seed --class=ProductionSeeder');

            return;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->command->warn("User with email {$email} already exists. Skipping.");

            return;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'role' => Role::SuperAdmin,
            'category' => null,
        ]);

        $this->command->info("Super Admin account created for {$email}.");
    }
}
