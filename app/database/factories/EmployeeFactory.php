<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'whatsapp_number' => '+63'.fake()->unique()->numerify('9#########'),
            'email' => fake()->unique()->safeEmail(),
            'department_id' => Department::factory(),
            'company_id' => Company::factory(),
            'position' => fake()->jobTitle(),
            'location' => fake()->city(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
