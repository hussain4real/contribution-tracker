<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditEvent;
use App\Models\Expense;
use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<AuditEvent>, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'actor_id' => User::factory(),
            'action' => 'test.event',
            'auditable_type' => Expense::MORPH_TYPE,
            'auditable_id' => Expense::factory(),
            'request_id' => (string) fake()->uuid(),
            'before' => null,
            'after' => ['status' => 'created'],
            'metadata' => null,
        ];
    }
}
