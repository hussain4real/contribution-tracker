<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ProductionSeeder extends Seeder
{
    /**
     * Seed the production database with only the Admin account and default family.
     *
     * Usage: php artisan db:seed --class=ProductionSeeder
     *
     * Environment variables:
     *   ADMIN_NAME  - Admin display name (default: Admin)
     *   ADMIN_EMAIL - Admin email (required)
     *   ADMIN_PASSWORD - Admin password (required)
     *   FAMILY_NAME - Family name (default: My Family)
     */
    public function run(): void
    {
        $email = config('app.admin_email');
        $password = config('app.admin_password');
        $name = config('app.admin_name') ?: 'Admin';
        $familyName = config('app.family_name') ?: 'My Family';

        if (! $email || ! $password) {
            $this->command->error('ADMIN_EMAIL and ADMIN_PASSWORD environment variables are required.');
            $this->command->info('Set them via: ADMIN_EMAIL=you@example.com ADMIN_PASSWORD=secret php artisan db:seed --class=ProductionSeeder');

            return;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->command->warn("User with email {$email} already exists. Skipping.");

            return;
        }

        // Create the family
        $family = Family::create([
            'name' => $familyName,
            'slug' => Str::slug($familyName),
            'currency' => '₦',
            'due_day' => 28,
        ]);

        // Create default categories
        FamilyCategory::create(['family_id' => $family->id, 'name' => 'Employed', 'slug' => 'employed', 'monthly_amount' => 4000, 'sort_order' => 0]);
        FamilyCategory::create(['family_id' => $family->id, 'name' => 'Unemployed', 'slug' => 'unemployed', 'monthly_amount' => 2000, 'sort_order' => 1]);
        FamilyCategory::create(['family_id' => $family->id, 'name' => 'Student', 'slug' => 'student', 'monthly_amount' => 1000, 'sort_order' => 2]);

        // Create admin user
        $admin = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'role' => Role::Admin,
            'category' => null,
            'family_id' => $family->id,
        ]);

        // Set family owner
        $family->update(['created_by' => $admin->id]);

        $this->command->info("Admin account created for {$email}.");
        $this->command->info("Family '{$familyName}' created with default categories.");
    }
}
