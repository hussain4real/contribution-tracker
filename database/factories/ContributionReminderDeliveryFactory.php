<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contribution;
use App\Models\ContributionReminderDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContributionReminderDelivery>
 */
class ContributionReminderDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<ContributionReminderDelivery>, mixed>
     */
    public function definition(): array
    {
        return [
            'contribution_id' => Contribution::factory(),
            'reminder_type' => 'reminder',
            'channel' => 'mail',
            'status' => 'queued',
        ];
    }
}
