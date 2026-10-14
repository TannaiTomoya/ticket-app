<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Services\CheckoutService;
use Livewire\Livewire;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;

/**
 * Create a pending order with one item and a Checkout Session id.
 */
function completePageOrder(int $quantity = 2): Order
{
    $ticketType = TicketType::factory()->for(Event::factory()->published())->create(['capacity' => 10]);
    $order = Order::factory()->for($ticketType->event)->create(['stripe_checkout_session_id' => 'cs_test_123']);
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => $quantity]);

    return $order;
}

/**
 * Build a Checkout Session as Stripe would return it. The default amount is the OrderFactory total.
 */
function stripeSession(string $paymentStatus, int $amountTotal = 3000): Session
{
    return Session::constructFrom([
        'id' => 'cs_test_123',
        'payment_status' => $paymentStatus,
        'payment_intent' => 'pi_test_123',
        'amount_total' => $amountTotal,
        'currency' => 'jpy',
    ]);
}

test('a paid session confirms the order and shows the tickets', function () {
    $order = completePageOrder(quantity: 2);

    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')->with('cs_test_123')->andReturn(stripeSession('paid'));

    $this->actingAs($order->user)
        ->get(route('orders.complete', ['order' => $order, 'session_id' => 'cs_test_123']))
        ->assertOk()
        ->assertSee('購入が完了しました')
        ->assertSee('チケットを 2 枚発行しました');

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->stripe_payment_intent_id)->toBe('pi_test_123')
        ->and($order->tickets()->count())->toBe(2);
});

test('an unpaid session keeps the order pending and polls', function () {
    $order = completePageOrder();

    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')->andReturn(stripeSession('unpaid'));

    $this->actingAs($order->user)
        ->get(route('orders.complete', ['order' => $order, 'session_id' => 'cs_test_123']))
        ->assertOk()
        ->assertSee('支払いを確認しています')
        ->assertSee('wire:poll.5s', false);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->tickets()->count())->toBe(0);
});

test('Stripe is not asked when the session id is missing or differs', function (?string $sessionId) {
    $order = completePageOrder();

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    $this->actingAs($order->user)
        ->get(route('orders.complete', ['order' => $order] + ($sessionId === null ? [] : ['session_id' => $sessionId])))
        ->assertOk()
        ->assertSee('支払いを確認しています');

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
})->with([
    'missing' => [null],
    'different' => ['cs_test_other'],
]);

test('an already paid order is shown without asking Stripe', function () {
    $order = Order::factory()->paid()->create();

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    $this->actingAs($order->user)
        ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
        ->assertOk()
        ->assertSee('購入が完了しました');
});

test('a cancelled order is shown as cancelled without asking Stripe', function () {
    $order = Order::factory()->cancelled()->create(['stripe_checkout_session_id' => 'cs_test_123']);

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    $this->actingAs($order->user)
        ->get(route('orders.complete', ['order' => $order, 'session_id' => 'cs_test_123']))
        ->assertOk()
        ->assertSee('この注文は取り消されています');
});

test('polling confirms the order once the payment goes through', function () {
    $order = completePageOrder(quantity: 2);

    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')->twice()
        ->andReturn(stripeSession('unpaid'), stripeSession('paid'));

    Livewire::withQueryParams(['session_id' => 'cs_test_123'])
        ->actingAs($order->user)
        ->test('pages::orders.complete', ['order' => $order])
        ->assertSee('支払いを確認しています')
        ->call('confirmPayment')
        ->assertSee('購入が完了しました');

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

test('a Stripe error on the complete page keeps the order pending', function () {
    $order = completePageOrder();

    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')
        ->andThrow(new ApiConnectionException('down'));

    $this->actingAs($order->user)
        ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
        ->assertOk()
        ->assertSee('支払いを確認しています');

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('a paid session with a different amount keeps the order pending', function () {
    $order = completePageOrder();

    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')->andReturn(stripeSession('paid', amountTotal: 100));

    $this->actingAs($order->user)
        ->get(route('orders.complete', ['order' => $order, 'session_id' => 'cs_test_123']))
        ->assertOk()
        ->assertSee('支払いを確認しています');

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->tickets()->count())->toBe(0);
});
