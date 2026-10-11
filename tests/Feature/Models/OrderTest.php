<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use Illuminate\Database\QueryException;

test('deleting an order also deletes its items and tickets', function () {
    $ticket = Ticket::factory()->create();
    $orderItem = $ticket->orderItem;

    $orderItem->order->delete();

    $this->assertModelMissing($orderItem);
    $this->assertModelMissing($ticket);
});

test('a user with orders cannot be deleted', function () {
    $order = Order::factory()->create();

    expect(fn () => $order->user->delete())->toThrow(QueryException::class);

    $this->assertModelExists($order);
});

test('tickets are reachable through the order items', function () {
    $orderItem = OrderItem::factory()->create();
    $tickets = Ticket::factory()->count(2)->for($orderItem)->create();

    expect($orderItem->order->tickets->pluck('id')->all())
        ->toEqualCanonicalizing($tickets->pluck('id')->all());
});
