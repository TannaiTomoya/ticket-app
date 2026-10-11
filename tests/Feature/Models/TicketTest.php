<?php

use App\Models\OrderItem;
use App\Models\Ticket;

test('a ticket is given a unique ULID code when created', function () {
    $orderItem = OrderItem::factory()->create();

    $tickets = Ticket::factory()->count(2)->for($orderItem)->create(['code' => null]);

    expect($tickets[0]->code)->toBeUlid()
        ->and($tickets[1]->code)->toBeUlid()
        ->and($tickets[0]->code)->not->toBe($tickets[1]->code);
});
