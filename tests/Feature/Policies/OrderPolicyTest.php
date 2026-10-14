<?php

use App\Models\Order;
use App\Models\User;
use App\Policies\OrderPolicy;

test('the order owner, the event organizer, and admins can view the order', function (Closure $makeUser, bool $expected) {
    $order = Order::factory()->create();

    expect((new OrderPolicy)->view($makeUser($order), $order))->toBe($expected);
})->with([
    'owner' => [fn (Order $order) => $order->user, true],
    'organizer' => [fn (Order $order) => $order->event->organizer, true],
    'admin' => [fn (Order $order) => User::factory()->admin()->create(), true],
    'other customer' => [fn (Order $order) => User::factory()->create(), false],
]);
