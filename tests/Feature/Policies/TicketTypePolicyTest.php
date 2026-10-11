<?php

use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Policies\TicketTypePolicy;

test('the owner can delete a ticket type only while it has no order items', function () {
    $ticketType = TicketType::factory()->create();
    $policy = new TicketTypePolicy;
    $owner = $ticketType->event->organizer;

    expect($policy->delete($owner, $ticketType))->toBeTrue();

    OrderItem::factory()->for($ticketType)->create();

    expect($policy->delete($owner, $ticketType))->toBeFalse();
});

test('other organizers cannot delete a ticket type', function () {
    $ticketType = TicketType::factory()->create();

    expect((new TicketTypePolicy)->delete(User::factory()->organizer()->create(), $ticketType))->toBeFalse();
});
