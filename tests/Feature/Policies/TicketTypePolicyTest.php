<?php

use App\Models\Event;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Policies\TicketTypePolicy;

test('only the owner of the event can add ticket types', function (Closure $makeUser, bool $expected) {
    $event = Event::factory()->create();

    expect((new TicketTypePolicy)->create($makeUser($event), $event))->toBe($expected);
})->with([
    'owner' => [fn (Event $event) => $event->organizer, true],
    'other organizer' => [fn (Event $event) => User::factory()->organizer()->create(), false],
    'customer' => [fn (Event $event) => User::factory()->create(), false],
    'admin' => [fn (Event $event) => User::factory()->admin()->create(), false],
]);

test('only the owner of the event can update its ticket types', function (Closure $makeUser, bool $expected) {
    $ticketType = TicketType::factory()->create();

    expect((new TicketTypePolicy)->update($makeUser($ticketType->event), $ticketType))->toBe($expected);
})->with([
    'owner' => [fn (Event $event) => $event->organizer, true],
    'other organizer' => [fn (Event $event) => User::factory()->organizer()->create(), false],
    'customer' => [fn (Event $event) => User::factory()->create(), false],
    'admin' => [fn (Event $event) => User::factory()->admin()->create(), false],
]);

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
