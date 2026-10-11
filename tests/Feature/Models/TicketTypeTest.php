<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;

test('a ticket type is on sale only within its sale period', function (Closure $makeTicketType, bool $expected) {
    expect($makeTicketType()->isOnSale())->toBe($expected);
})->with([
    'during the sale period' => [fn () => TicketType::factory()->create(), true],
    'before the sale starts' => [fn () => TicketType::factory()->notOnSaleYet()->create(), false],
    'after the sale ends' => [fn () => TicketType::factory()->saleEnded()->create(), false],
]);

test('remaining subtracts the seats of paid orders', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 20]);
    OrderItem::factory()->for(Order::factory()->paid()->for($ticketType->event))->for($ticketType)->create(['quantity' => 3]);

    expect($ticketType->remaining())->toBe(17);
});

test('remaining subtracts pending orders only within the hold period', function (int $minutesLater, int $expected) {
    $this->freezeSecond();
    $ticketType = TicketType::factory()->create(['capacity' => 20]);
    OrderItem::factory()->for(Order::factory()->for($ticketType->event))->for($ticketType)->create(['quantity' => 4]);
    $this->travel($minutesLater)->minutes();

    expect($ticketType->remaining())->toBe($expected);
})->with([
    'just created' => [0, 16],
    'at the end of the hold period' => [Order::HOLD_MINUTES, 16],
    'after the hold period' => [Order::HOLD_MINUTES + 1, 20],
]);

test('remaining ignores orders for other ticket types and other events', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 20]);
    $sameEventTicketType = TicketType::factory()->for($ticketType->event)->create();
    OrderItem::factory()->for(Order::factory()->paid()->for($ticketType->event))->for($sameEventTicketType)->create(['quantity' => 5]);
    OrderItem::factory()->for(Order::factory()->paid())->create(['quantity' => 6]);

    expect($ticketType->remaining())->toBe(20);
});

test('remaining never goes below zero', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 2]);
    OrderItem::factory()->for(Order::factory()->paid()->for($ticketType->event))->for($ticketType)->create(['quantity' => 3]);

    expect($ticketType->remaining())->toBe(0);
});

test('remaining gives the same result with and without the preloaded held quantity', function () {
    $this->freezeSecond();
    $ticketType = TicketType::factory()->create(['capacity' => 20]);
    OrderItem::factory()->for(Order::factory()->for($ticketType->event))->for($ticketType)->create(['quantity' => 4]);
    $this->travel(Order::HOLD_MINUTES + 1)->minutes();
    OrderItem::factory()->for(Order::factory()->paid()->for($ticketType->event))->for($ticketType)->create(['quantity' => 3]);
    OrderItem::factory()->for(Order::factory()->for($ticketType->event))->for($ticketType)->create(['quantity' => 2]);
    $preloaded = TicketType::query()->withHeldQuantity()->findOrFail($ticketType->id);
    $plain = TicketType::query()->findOrFail($ticketType->id);

    expect($preloaded->remaining())->toBe(15)
        ->and($plain->remaining())->toBe(15);
});

test('unavailable reason explains why a ticket type cannot be bought', function (Closure $makeTicketType, ?string $expected) {
    $this->freezeSecond();

    expect($makeTicketType()->unavailableReason())->toBe($expected);
})->with([
    'before the sale' => [fn () => TicketType::factory()->notOnSaleYet()->create(), '販売前'],
    'after the sale' => [fn () => TicketType::factory()->saleEnded()->create(), '販売終了'],
    'sold out' => [function () {
        $ticketType = TicketType::factory()->create(['capacity' => 2]);
        OrderItem::factory()->for(Order::factory()->paid()->for($ticketType->event))->for($ticketType)->create(['quantity' => 2]);

        return $ticketType;
    }, '売り切れ'],
    'on sale' => [fn () => TicketType::factory()->create(), null],
]);
