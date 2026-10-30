<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contribution;
use App\Models\ContributionReminderDelivery;
use Illuminate\Database\Seeder;

class ContributionReminderDeliverySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Contribution::query()->where(function ($query): void {
            $query->whereNotNull('reminder_sent_at')->orWhereNotNull('follow_up_sent_at');
        })->chunkById(200, function ($contributions): void {
            foreach ($contributions as $contribution) {
                foreach (['reminder' => $contribution->reminder_sent_at, 'follow_up' => $contribution->follow_up_sent_at] as $type => $sentAt) {
                    if ($sentAt !== null) {
                        ContributionReminderDelivery::query()->firstOrCreate([
                            'contribution_id' => $contribution->id, 'reminder_type' => $type, 'channel' => 'unknown',
                        ], ['status' => 'unknown']);
                    }
                }
            }
        });
    }
}
