<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class DevelopmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        throw_unless(
            app()->environment(['local', 'testing', 'staging']),
            RuntimeException::class,
            'Development demo data may only be seeded in local, testing, or staging environments.',
        );

        $this->call([
            DemoIdentitySeeder::class,
            DemoLedgerSeeder::class,
            DemoReportingSeeder::class,
            DemoCommunicationSeeder::class,
        ]);

        $this->command->newLine();
        $this->command->info('Demo data is ready. All demo accounts use password: password');
        $this->command->table(
            ['Account', 'Email', 'Purpose'],
            [
                ['Platform admin', 'platform@family.test', 'Filament platform administration'],
                ['Family admin', 'admin@family.test', 'Primary Growth family administration'],
                ['Financial secretary', 'finance@family.test', 'Payments, expenses, reports, and reminders'],
                ['Member', 'member@family.test', 'Fully paid member experience'],
                ['Partial member', 'partial@family.test', 'Partially paid contribution state'],
                ['Overdue member', 'overdue@family.test', 'Overdue contribution state'],
                ['Multi-family member', 'multi@family.test', 'Family switching and different roles'],
            ],
        );
    }
}
