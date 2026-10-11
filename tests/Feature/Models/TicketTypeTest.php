<?php

use App\Models\TicketType;

test('a ticket type is on sale only within its sale period', function (Closure $makeTicketType, bool $expected) {
    expect($makeTicketType()->isOnSale())->toBe($expected);
})->with([
    'during the sale period' => [fn () => TicketType::factory()->create(), true],
    'before the sale starts' => [fn () => TicketType::factory()->notOnSaleYet()->create(), false],
    'after the sale ends' => [fn () => TicketType::factory()->saleEnded()->create(), false],
]);
