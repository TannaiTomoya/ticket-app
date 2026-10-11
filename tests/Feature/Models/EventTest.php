<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\QueryException;

test('deleting an event also deletes its ticket types', function () {
    $ticketType = TicketType::factory()->create();

    $ticketType->event->delete();

    $this->assertModelMissing($ticketType);
});

test('an event with orders cannot be deleted', function () {
    $order = Order::factory()->create();

    expect(fn () => $order->event->delete())->toThrow(QueryException::class);

    $this->assertModelExists($order->event);
});

test('an organizer with events cannot be deleted', function () {
    $organizer = User::factory()->organizer()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create();

    expect(fn () => $organizer->delete())->toThrow(QueryException::class);

    $this->assertModelExists($event);
});
