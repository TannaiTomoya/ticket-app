<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketType>
 */
class TicketTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => '一般',
            'price' => 3000,
            'capacity' => 20,
            'sale_starts_at' => now()->subDay(),
            'sale_ends_at' => now()->addDays(7),
        ];
    }

    /**
     * Indicate that the ticket type is free.
     */
    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => '無料券',
            'price' => 0,
        ]);
    }

    /**
     * Indicate that the sale period has not started yet.
     */
    public function notOnSaleYet(): static
    {
        return $this->state(fn (array $attributes) => [
            'sale_starts_at' => now()->addDay(),
            'sale_ends_at' => now()->addDays(7),
        ]);
    }

    /**
     * Indicate that the sale period has ended.
     */
    public function saleEnded(): static
    {
        return $this->state(fn (array $attributes) => [
            'sale_starts_at' => now()->subDays(7),
            'sale_ends_at' => now()->subDay(),
        ]);
    }
}
