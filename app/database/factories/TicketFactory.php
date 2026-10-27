<?php

namespace Database\Factories;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Priority;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        $year = (int) date('Y');

        return [
            'ticket_number' => sprintf('IT-%d-%06d', $year, fake()->unique()->numberBetween(1, 999999)),
            'employee_id' => Employee::factory(),
            'assigned_to' => null,
            'subject' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'category_id' => Category::resolve('general')->id,
            'priority_id' => Priority::resolve('medium')->id,
            'status' => TicketStatus::New,
        ];
    }

    public function status(TicketStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
