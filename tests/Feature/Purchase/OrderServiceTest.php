<?php

use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Services\OrderService;

test('creates a pending order with items whose unit price and total are correct', function () {
    $event = Event::factory()->published()->create();
    $ticketTypeA = TicketType::factory()->for($event)->create(['price' => 3000, 'capacity' => 10]);
    $ticketTypeB = TicketType::factory()->for($event)->create(['price' => 1000, 'capacity' => 10]);
    $user = User::factory()->create();

    $order = app(OrderService::class)->createOrder($user, $event, [
        $ticketTypeA->id => 2,
        $ticketTypeB->id => 3,
    ]);

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->user_id)->toBe($user->id)
        ->and($order->event_id)->toBe($event->id)
        ->and($order->total_amount)->toBe(2 * 3000 + 3 * 1000)
        ->and($order->items)->toHaveCount(2);

    $itemA = $order->items->firstWhere('ticket_type_id', $ticketTypeA->id);
    $itemB = $order->items->firstWhere('ticket_type_id', $ticketTypeB->id);

    expect($itemA->quantity)->toBe(2)
        ->and($itemA->unit_price)->toBe(3000)
        ->and($itemB->quantity)->toBe(3)
        ->and($itemB->unit_price)->toBe(1000);
});

test('fails when the requested quantity exceeds the remaining seats', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 2]);
    $user = User::factory()->create();

    expect(fn () => app(OrderService::class)->createOrder($user, $event, [$ticketType->id => 3]))
        ->toThrow(PurchaseException::class);

    expect(Order::count())->toBe(0);
});

test('fails when the ticket type is outside its sale period', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->saleEnded()->create();
    $user = User::factory()->create();

    expect(fn () => app(OrderService::class)->createOrder($user, $event, [$ticketType->id => 1]))
        ->toThrow(PurchaseException::class);

    expect(Order::count())->toBe(0);
});

test('fails when the quantity exceeds the maximum per ticket type', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 50]);
    $user = User::factory()->create();

    expect(fn () => app(OrderService::class)->createOrder($user, $event, [$ticketType->id => 11]))
        ->toThrow(PurchaseException::class);

    expect(Order::count())->toBe(0);
});

test('fails when no quantity is selected', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create();

    expect(fn () => app(OrderService::class)->createOrder(User::factory()->create(), $event, [$ticketType->id => 0]))
        ->toThrow(PurchaseException::class, '枚数を選んでください。');

    expect(Order::count())->toBe(0);
});

test('fails when the event cannot be purchased', function (Closure $makeEvent) {
    $event = $makeEvent();
    $ticketType = TicketType::factory()->for($event)->create();

    expect(fn () => app(OrderService::class)->createOrder(User::factory()->create(), $event, [$ticketType->id => 1]))
        ->toThrow(PurchaseException::class, 'このイベントは現在購入できません。');

    expect(Order::count())->toBe(0);
})->with([
    'draft' => [fn () => Event::factory()->create()],
    'unpublished' => [fn () => Event::factory()->unpublished()->create()],
    'finished' => [fn () => Event::factory()->published()->past()->create()],
]);

test('fails when the ticket type belongs to another event', function () {
    $event = Event::factory()->published()->create();
    $otherTicketType = TicketType::factory()->for(Event::factory()->published())->create();

    expect(fn () => app(OrderService::class)->createOrder(User::factory()->create(), $event, [$otherTicketType->id => 1]))
        ->toThrow(PurchaseException::class);

    expect(Order::count())->toBe(0);
});

test('rolls back the order and its already-saved items when a later item fails to save', function () {
    $event = Event::factory()->published()->create();
    $ticketTypeA = TicketType::factory()->for($event)->create();
    $ticketTypeB = TicketType::factory()->for($event)->create();
    $user = User::factory()->create();

    $savedCount = 0;
    OrderItem::saving(function () use (&$savedCount) {
        $savedCount++;

        if ($savedCount === 2) {
            throw new RuntimeException('simulated failure while saving the second item');
        }
    });

    expect(fn () => app(OrderService::class)->createOrder($user, $event, [
        $ticketTypeA->id => 1,
        $ticketTypeB->id => 1,
    ]))->toThrow(RuntimeException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});
