<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use Livewire\Livewire;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;

/**
 * Create a pending order with one item and a Checkout Session id.
 */
function pendingCheckoutOrder(int $capacity = 5, int $quantity = 2): Order
{
    $ticketType = TicketType::factory()->for(Event::factory()->published())->create(['capacity' => $capacity]);
    $order = Order::factory()->for($ticketType->event)->create(['stripe_checkout_session_id' => 'cs_test_123']);
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => $quantity]);

    return $order;
}

test('a free order skips Stripe, is paid at once and opens the complete page', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['price' => 0]);
    session()->put("purchase.{$event->id}", [$ticketType->id => 1]);

    $this->mock(CheckoutService::class)->shouldNotReceive('createSession');

    $component = Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $event])
        ->call('pay')
        ->assertHasNoErrors();

    $order = Order::sole();
    $component->assertRedirect(route('orders.complete', $order));

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->total_amount)->toBe(0)
        ->and($order->tickets()->count())->toBe(1);
});

test('a Stripe error cancels the new order and shows an error', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create();
    session()->put("purchase.{$event->id}", [$ticketType->id => 1]);

    $this->mock(CheckoutService::class)
        ->shouldReceive('createSession')
        ->andThrow(new ApiConnectionException('down'));

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $event])
        ->call('pay')
        ->assertHasErrors('purchase')
        ->assertNoRedirect();

    expect(Order::sole()->status)->toBe(OrderStatus::Cancelled);
});

test('cancelling expires the open session, cancels the order and releases the seats', function () {
    $order = pendingCheckoutOrder(capacity: 5, quantity: 2);
    $ticketType = $order->items->sole()->ticketType;
    expect($ticketType->remaining())->toBe(3);

    $checkout = $this->mock(CheckoutService::class);
    $checkout->shouldReceive('retrieveSession')->once()->with('cs_test_123')
        ->andReturn(Session::constructFrom(['id' => 'cs_test_123', 'status' => Session::STATUS_OPEN]));
    $checkout->shouldReceive('expireSession')->once()->with('cs_test_123')
        ->andReturn(Session::constructFrom(['id' => 'cs_test_123', 'status' => Session::STATUS_EXPIRED]));

    $this->actingAs($order->user)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('支払いを中断しました');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($ticketType->remaining())->toBe(5);
});

test('a completed session is not cancelled and goes to the complete page', function () {
    $order = pendingCheckoutOrder();

    $checkout = $this->mock(CheckoutService::class);
    $checkout->shouldReceive('retrieveSession')
        ->andReturn(Session::constructFrom(['id' => 'cs_test_123', 'status' => Session::STATUS_COMPLETE]));
    $checkout->shouldNotReceive('expireSession');

    $this->actingAs($order->user)
        ->get(route('orders.cancel', $order))
        ->assertRedirect(route('orders.complete', ['order' => $order, 'session_id' => 'cs_test_123']));

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('opening the cancel page again keeps the order cancelled without calling Stripe', function () {
    $order = Order::factory()->cancelled()->create(['stripe_checkout_session_id' => 'cs_test_123']);

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    $this->actingAs($order->user)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('支払いを中断しました');
});

test('a paid order is not cancelled', function () {
    $order = Order::factory()->paid()->create();

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    $this->actingAs($order->user)
        ->get(route('orders.cancel', $order))
        ->assertRedirect(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]));

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

test('only the buyer can cancel the order', function (Closure $makeUser) {
    $order = pendingCheckoutOrder();

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    $this->actingAs($makeUser($order))
        ->get(route('orders.cancel', $order))
        ->assertForbidden();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
})->with([
    'another user' => [fn () => User::factory()->create()],
    'organizer' => [fn (Order $order) => $order->event->organizer],
    'admin' => [fn () => User::factory()->admin()->create()],
]);

test('guests are sent to the login page from the cancel page', function () {
    $this->get(route('orders.cancel', Order::factory()->create()))
        ->assertRedirect(route('login'));
});
