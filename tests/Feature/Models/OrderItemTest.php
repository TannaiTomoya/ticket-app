<?php

use App\Models\OrderItem;
use App\Models\TicketType;
use Illuminate\Database\QueryException;

test('a ticket type that has been ordered cannot be deleted', function () {
    $orderItem = OrderItem::factory()->create();

    expect(fn () => $orderItem->ticketType->delete())->toThrow(QueryException::class);

    $this->assertModelExists($orderItem->ticketType);
});

test('the order item keeps its unit price when the ticket type price changes', function () {
    $ticketType = TicketType::factory()->create(['price' => 3000]);
    $orderItem = OrderItem::factory()->for($ticketType)->create(['quantity' => 2]);

    $ticketType->update(['price' => 5000]);

    expect($orderItem->fresh()->unit_price)->toBe(3000)
        ->and($orderItem->fresh()->subtotal())->toBe(6000);
});

test('the order item belongs to a ticket type of the ordered event', function () {
    $orderItem = OrderItem::factory()->create();

    expect($orderItem->ticketType->event_id)->toBe($orderItem->order->event_id);
});
