<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'ticket_type_id' => fn (array $attributes) => TicketType::factory()->state([
                'event_id' => Order::query()->whereKey($attributes['order_id'])->value('event_id'),
            ]),
            'quantity' => 1,
            'unit_price' => fn (array $attributes) => TicketType::query()->whereKey($attributes['ticket_type_id'])->value('price'),
        ];
    }
}
