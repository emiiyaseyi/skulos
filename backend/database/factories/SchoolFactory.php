<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    public function definition(): array
    {
        $name = fake()->unique()->company().' School';

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'status' => School::STATUS_PENDING,
        ];
    }
}
