<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;

test('guests can view a published event with its ticket types', function () {
    $event = Event::factory()->published()->create(['title' => '秋の朗読会', 'venue' => '中央図書館']);
    TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 1500]);
    TicketType::factory()->for($event)->free()->create(['name' => '小学生']);

    $response = $this->get(route('events.show', $event));

    $response->assertOk()
        ->assertSee('秋の朗読会')
        ->assertSee('中央図書館')
        ->assertSee('一般')
        ->assertSee('1,500円')
        ->assertSee('小学生')
        ->assertSee('無料')
        ->assertDontSee('公開されていません');
});

test('the owner and admins can view draft and unpublished events with a notice', function (Closure $makeEvent, Closure $makeUser) {
    $event = $makeEvent();
    $this->actingAs($makeUser($event));

    $response = $this->get(route('events.show', $event));

    $response->assertOk()
        ->assertSee($event->title)
        ->assertSee('このイベントは公開されていません');
})->with([
    'draft' => [fn () => Event::factory()->create()],
    'unpublished' => [fn () => Event::factory()->unpublished()->create()],
])->with([
    'owner' => [fn (Event $event) => $event->organizer],
    'admin' => [fn (Event $event) => User::factory()->admin()->create()],
]);

test('draft and unpublished events are not found for everyone else', function (Closure $makeEvent, Closure $makeUser) {
    $event = $makeEvent();
    if ($user = $makeUser()) {
        $this->actingAs($user);
    }

    $response = $this->get(route('events.show', $event));

    $response->assertNotFound();
})->with([
    'draft' => [fn () => Event::factory()->create()],
    'unpublished' => [fn () => Event::factory()->unpublished()->create()],
])->with([
    'other organizer' => [fn () => User::factory()->organizer()->create()],
    'customer' => [fn () => User::factory()->create()],
    'guest' => [fn () => null],
]);

test('the remaining count subtracts paid and recent pending orders', function () {
    $this->freezeSecond();
    $ticketType = TicketType::factory()->for(Event::factory()->published())->create(['capacity' => 20]);
    $expiredPending = Order::factory()->for($ticketType->event)->create();
    OrderItem::factory()->for($expiredPending)->for($ticketType)->create(['quantity' => 4]);
    $this->travel(Order::HOLD_MINUTES + 1)->minutes();
    OrderItem::factory()->for(Order::factory()->paid()->for($ticketType->event))->for($ticketType)->create(['quantity' => 3]);
    OrderItem::factory()->for(Order::factory()->for($ticketType->event))->for($ticketType)->create(['quantity' => 2]);

    $response = $this->get(route('events.show', $ticketType->event));

    $response->assertOk()->assertSee('残り 15 枚');
});

test('each ticket type shows whether it is on sale', function (Closure $makeTicketType, string $label) {
    $this->freezeSecond();
    $ticketType = $makeTicketType(Event::factory()->published()->create());

    $response = $this->get(route('events.show', $ticketType->event));

    $response->assertOk()->assertSee($label);
    if ($label !== '販売中') {
        $response->assertDontSee('販売中');
    }
})->with([
    'before the sale' => [fn (Event $event) => TicketType::factory()->for($event)->notOnSaleYet()->create(), '販売前'],
    'after the sale' => [fn (Event $event) => TicketType::factory()->for($event)->saleEnded()->create(), '販売終了'],
    'sold out' => [function (Event $event) {
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 2]);
        OrderItem::factory()->for(Order::factory()->paid()->for($event))->for($ticketType)->create(['quantity' => 2]);

        return $ticketType;
    }, '売り切れ'],
    'on sale' => [fn (Event $event) => TicketType::factory()->for($event)->create(), '販売中'],
]);
