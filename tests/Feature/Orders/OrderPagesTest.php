<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('the list shows only my orders, newest first', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    $old = Order::factory()->for($user)->create(['created_at' => now()->subDays(2)]);
    $new = Order::factory()->for($user)->create(['created_at' => now()]);
    $mid = Order::factory()->for($user)->create(['created_at' => now()->subDay()]);
    $others = Order::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::orders.index')
        ->assertSeeInOrder(["order-row-{$new->id}", "order-row-{$mid->id}", "order-row-{$old->id}"])
        ->assertDontSeeHtml("order-row-{$others->id}\"");
});

test('the list is split into pages of 10', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    $orders = collect(range(1, 11))->map(
        fn (int $i) => Order::factory()->for($user)->create(['created_at' => now()->subMinutes($i)]),
    );
    $oldest = $orders->last();

    Livewire::actingAs($user)
        ->test('pages::orders.index')
        ->assertDontSeeHtml("order-row-{$oldest->id}\"")
        ->assertSee('gotoPage(2', false)
        ->call('gotoPage', 2)
        ->assertSeeHtml("order-row-{$oldest->id}\"");
});

test('the buyer, the organizer and an admin can see an order', function (Closure $makeViewer) {
    $order = Order::factory()->paid()->create();

    $this->actingAs($makeViewer($order))
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee("注文番号 {$order->id}");
})->with([
    'buyer' => [fn (Order $order) => $order->user],
    'organizer of the event' => [fn (Order $order) => $order->event->organizer],
    'admin' => [fn () => User::factory()->admin()->create()],
]);

test('another customer gets 403 and a guest is sent to login', function () {
    $order = Order::factory()->paid()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('orders.show', $order))
        ->assertForbidden();

    auth()->logout();

    $this->get(route('orders.show', $order))->assertRedirect(route('login'));
});

test('the list reads events with a single query', function () {
    $user = User::factory()->create();
    Order::factory()->count(5)->for($user)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($user)->test('pages::orders.index');

    $eventQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query) => str_contains($query['query'], 'from `events`'));

    expect($eventQueries)->toHaveCount(1);
});

test('a paid order shows its ticket codes', function () {
    $ticketType = TicketType::factory()->for(Event::factory()->published())->create();
    $order = Order::factory()->paid()->for($ticketType->event)->create();
    $item = OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => 2]);
    $tickets = Ticket::factory()->count(2)->for($item)->create();

    $this->actingAs($order->user)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee($tickets[0]->code)
        ->assertSee($tickets[1]->code);
});

test('a pending order says payment is incomplete and shows no ticket code', function () {
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create();

    $this->actingAs($order->user)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('支払いが完了していません')
        ->assertDontSeeHtml('data-test="ticket-code"');
});

test('the navigation links マイチケット to the order list', function () {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('orders.index'))
        ->assertOk();

    expect($response->getContent())
        ->toMatch('/href="'.preg_quote(route('orders.index'), '/').'"[^>]*>\s*(<[^>]+>\s*)*マイチケット/u');
});
