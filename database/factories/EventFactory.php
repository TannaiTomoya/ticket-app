<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organizer_id' => User::factory()->organizer(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'venue' => fake()->address(),
            'starts_at' => now()->addDays(14)->setTime(10, 0),
            'status' => EventStatus::Draft,
        ];
    }

    /**
     * Indicate that the event is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventStatus::Published,
        ]);
    }

    /**
     * Indicate that the event was unpublished by an admin.
     */
    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventStatus::Unpublished,
        ]);
    }

    /**
     * Indicate that the event has already taken place.
     */
    public function past(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subDays(7)->setTime(10, 0),
        ]);
    }
}
