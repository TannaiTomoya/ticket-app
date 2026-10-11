<?php

use App\Models\Event;
use App\Models\User;
use App\Policies\EventPolicy;

test('published events are visible to everyone', function (Closure $makeUser) {
    $event = Event::factory()->published()->create();

    expect((new EventPolicy)->view($makeUser($event), $event))->toBeTrue();
})->with([
    'guest' => [fn (Event $event) => null],
    'customer' => [fn (Event $event) => User::factory()->create()],
    'other organizer' => [fn (Event $event) => User::factory()->organizer()->create()],
    'owner' => [fn (Event $event) => $event->organizer],
]);

test('draft and unpublished events are visible only to the owner and admins', function (Closure $makeEvent, Closure $makeUser, bool $expected) {
    $event = $makeEvent();

    expect((new EventPolicy)->view($makeUser($event), $event))->toBe($expected);
})->with([
    'draft' => [fn () => Event::factory()->create()],
    'unpublished' => [fn () => Event::factory()->unpublished()->create()],
])->with([
    'owner' => [fn (Event $event) => $event->organizer, true],
    'admin' => [fn (Event $event) => User::factory()->admin()->create(), true],
    'other organizer' => [fn (Event $event) => User::factory()->organizer()->create(), false],
    'customer' => [fn (Event $event) => User::factory()->create(), false],
    'guest' => [fn (Event $event) => null, false],
]);

test('only admins can unpublish events', function (Closure $makeUser, bool $expected) {
    $event = Event::factory()->published()->create();

    expect((new EventPolicy)->unpublish($makeUser($event), $event))->toBe($expected);
})->with([
    'admin' => [fn (Event $event) => User::factory()->admin()->create(), true],
    'owner' => [fn (Event $event) => $event->organizer, false],
    'other organizer' => [fn (Event $event) => User::factory()->organizer()->create(), false],
    'customer' => [fn (Event $event) => User::factory()->create(), false],
]);
