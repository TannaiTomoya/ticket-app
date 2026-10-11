<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory()->published(),
            'status' => OrderStatus::Pending,
            'total_amount' => 3000,
            'stripe_checkout_session_id' => null,
            'paid_at' => null,
        ];
    }

    /**
     * Indicate that the order has been paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Paid,
            'stripe_checkout_session_id' => 'cs_test_'.fake()->unique()->regexify('[A-Za-z0-9]{24}'),
            'paid_at' => now(),
        ]);
    }

    /**
     * Indicate that the order was cancelled before payment.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Cancelled,
        ]);
    }

    /**
     * Indicate that the order was paid and then refunded.
     */
    public function refunded(): static
    {
        return $this->paid()->state(fn (array $attributes) => [
            'status' => OrderStatus::Refunded,
        ]);
    }
}
