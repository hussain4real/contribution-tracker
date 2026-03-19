<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\FamilyCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FamilyCategory>
 */
class FamilyCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Employed', 'Unemployed', 'Student', 'Senior', 'Youth']);

        return [
            'family_id' => Family::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'monthly_amount' => fake()->randomElement([1000, 2000, 3000, 4000, 5000]),
            'sort_order' => 0,
        ];
    }
}
