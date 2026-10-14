<?php

use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;

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

/**
 * Create a pending order with one item of the given quantity and a Checkout Session id.
 */
function payableOrder(int $quantity = 3, bool $cancelled = false): Order
{
    $ticketType = TicketType::factory()->for(Event::factory()->published())->create(['capacity' => 10]);
    $order = Order::factory()->for($ticketType->event)
        ->when($cancelled, fn ($factory) => $factory->cancelled())
        ->create(['stripe_checkout_session_id' => 'cs_test_123']);
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => $quantity]);

    return $order;
}

test('markAsPaid confirms a pending order once and issues one ticket per seat', function () {
    $order = payableOrder(quantity: 3);

    expect(app(OrderService::class)->markAsPaid($order, 'pi_test_123'))->toBeTrue()
        ->and($order->status)->toBe(OrderStatus::Paid)
        ->and($order->stripe_payment_intent_id)->toBe('pi_test_123')
        ->and(app(OrderService::class)->markAsPaid($order, 'pi_test_123'))->toBeFalse();

    $tickets = $order->tickets()->get();
    expect($tickets)->toHaveCount(3)
        ->and($tickets->every(fn ($ticket) => strlen($ticket->code) === 26))->toBeTrue()
        ->and($tickets->pluck('code')->unique())->toHaveCount(3);
});

test('markAsPaid does nothing for a cancelled order', function () {
    $order = payableOrder(cancelled: true);

    expect(app(OrderService::class)->markAsPaid($order, 'pi_test_123'))->toBeFalse()
        ->and($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->paid_at)->toBeNull()
        ->and($order->tickets()->count())->toBe(0);
});

test('confirmCheckout does not confirm a session that is unpaid or belongs to another order', function (string $id, string $paymentStatus) {
    $order = payableOrder();
    $session = Session::constructFrom(['id' => $id, 'payment_status' => $paymentStatus, 'payment_intent' => 'pi_test_123', 'amount_total' => $order->total_amount, 'currency' => 'jpy']);

    expect(app(OrderService::class)->confirmCheckout($order, $session))->toBeFalse()
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->tickets()->count())->toBe(0);
})->with([
    'unpaid' => ['cs_test_123', 'unpaid'],
    'other session' => ['cs_test_other', 'paid'],
]);

test('confirmCheckout confirms a paid session of the order', function () {
    $order = payableOrder();
    $session = Session::constructFrom(['id' => 'cs_test_123', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_123', 'amount_total' => $order->total_amount, 'currency' => 'jpy']);

    expect(app(OrderService::class)->confirmCheckout($order, $session))->toBeTrue()
        ->and($order->status)->toBe(OrderStatus::Paid);
});

/**
 * Create an order of the buyer for the ticket type, with one item of the given quantity.
 *
 * @param  array<string, mixed>  $attributes
 */
function buyerOrder(User $user, TicketType $ticketType, int $quantity, string $state = 'pending', array $attributes = []): Order
{
    $order = Order::factory()->for($user)->for($ticketType->event)
        ->when($state !== 'pending', fn ($factory) => $factory->{$state}())
        ->create($attributes + ['stripe_checkout_session_id' => 'cs_'.fake()->unique()->regexify('[a-z0-9]{12}')]);
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => $quantity]);

    return $order;
}

/**
 * Build a Checkout Session carrying only the id and status.
 */
function sessionWithStatus(string $id, string $status): Session
{
    return Session::constructFrom(['id' => $id, 'status' => $status]);
}

test('creating a second order cancels the first open checkout and only the second holds seats', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 10]);
    $user = User::factory()->create();
    $first = buyerOrder($user, $ticketType, 3, attributes: ['stripe_checkout_session_id' => 'cs_first']);

    $checkout = $this->mock(CheckoutService::class);
    $checkout->shouldReceive('retrieveSession')->once()->with('cs_first')->andReturn(sessionWithStatus('cs_first', Session::STATUS_OPEN));
    $checkout->shouldReceive('expireSession')->once()->with('cs_first');

    $second = app(OrderService::class)->createOrder($user, $event, [$ticketType->id => 2]);

    expect($first->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($second->status)->toBe(OrderStatus::Pending)
        ->and(Order::where('status', OrderStatus::Pending)->count())->toBe(1)
        ->and($ticketType->remaining())->toBe(8);
});

test('a purchase that fails keeps the earlier pending order and its checkout', function (Closure $breakPurchase) {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 20]);
    $user = User::factory()->create();
    buyerOrder($user, $ticketType, 2, 'paid');
    $earlier = buyerOrder($user, $ticketType, 1, attributes: ['stripe_checkout_session_id' => 'cs_earlier']);
    $quantity = $breakPurchase($ticketType);

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession', 'expireSession');

    expect(fn () => app(OrderService::class)->createOrder($user, $event, [$ticketType->id => $quantity]))
        ->toThrow(PurchaseException::class);

    expect($earlier->refresh()->status)->toBe(OrderStatus::Pending);
})->with([
    'over the per-buyer limit' => [fn (TicketType $ticketType) => 9],
    'sold out' => [function (TicketType $ticketType) {
        buyerOrder(User::factory()->create(), $ticketType, 17, 'paid');

        return 2;
    }],
    'outside the sale period' => [function (TicketType $ticketType) {
        $ticketType->update(['sale_ends_at' => now()->subMinute(), 'sale_starts_at' => now()->subDay()]);

        return 1;
    }],
]);

test('the seats of the checkout being replaced are free for the new purchase', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 5]);
    $user = User::factory()->create();
    buyerOrder(User::factory()->create(), $ticketType, 2, 'paid');
    $earlier = buyerOrder($user, $ticketType, 3, attributes: ['stripe_checkout_session_id' => 'cs_earlier']);

    $checkout = $this->mock(CheckoutService::class);
    $checkout->shouldReceive('retrieveSession')->once()->andReturn(sessionWithStatus('cs_earlier', Session::STATUS_OPEN));
    $checkout->shouldReceive('expireSession')->once();

    $order = app(OrderService::class)->createOrder($user, $event, [$ticketType->id => 3]);

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($earlier->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($ticketType->remaining())->toBe(0);
});

test('a completed earlier checkout is not cancelled and counts toward the limit', function (int $quantity, bool $allowed) {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 50]);
    $user = User::factory()->create();
    $first = buyerOrder($user, $ticketType, 8, attributes: ['stripe_checkout_session_id' => 'cs_first']);

    $checkout = $this->mock(CheckoutService::class);
    $checkout->shouldReceive('retrieveSession')->with('cs_first')->andReturn(sessionWithStatus('cs_first', Session::STATUS_COMPLETE));
    $checkout->shouldNotReceive('expireSession');

    $attempt = fn () => app(OrderService::class)->createOrder($user, $event, [$ticketType->id => $quantity]);

    if ($allowed) {
        expect($attempt()->status)->toBe(OrderStatus::Pending)
            ->and(Order::count())->toBe(2);
    } else {
        expect($attempt)->toThrow(PurchaseException::class, "「{$ticketType->name}」は1人10枚までです（購入済み・支払い中の 8 枚を含みます）。")
            ->and(Order::count())->toBe(1);
    }

    expect($first->refresh()->status)->toBe(OrderStatus::Pending);
})->with([
    'over the limit' => [3, false],
    'exactly the limit' => [2, true],
]);

test('a Stripe error while cancelling the earlier checkout stops the purchase', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 10]);
    $user = User::factory()->create();
    $first = buyerOrder($user, $ticketType, 3, attributes: ['stripe_checkout_session_id' => 'cs_first']);

    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')->andThrow(new ApiConnectionException('down'));

    expect(fn () => app(OrderService::class)->createOrder($user, $event, [$ticketType->id => 1]))
        ->toThrow(PurchaseException::class, '前の支払い手続きを取り消せませんでした');

    expect(Order::count())->toBe(1)
        ->and($first->refresh()->status)->toBe(OrderStatus::Pending);
});

test('seats already paid count toward the per-buyer limit', function (int $price, int $paidQuantity, int $quantity, bool $allowed) {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['price' => $price, 'capacity' => 50]);
    $user = User::factory()->create();
    buyerOrder($user, $ticketType, $paidQuantity, 'paid');

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    $attempt = fn () => app(OrderService::class)->createOrder($user, $event, [$ticketType->id => $quantity]);

    if ($allowed) {
        expect($attempt()->status)->toBe(OrderStatus::Pending);
    } else {
        expect($attempt)->toThrow(PurchaseException::class, '1人10枚まで');
    }

    expect(Order::where('status', OrderStatus::Pending)->count())->toBe($allowed ? 1 : 0);
})->with([
    'paid 8 then 3' => [3000, 8, 3, false],
    'paid 8 then 2' => [3000, 8, 2, true],
    'free paid 8 then 3' => [0, 8, 3, false],
    'free paid 10 then 1' => [0, 10, 1, false],
]);

test('a pending order older than the hold period is neither cancelled nor counted', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 50]);
    $user = User::factory()->create();
    $old = buyerOrder($user, $ticketType, 9, attributes: ['created_at' => now()->subMinutes(Order::HOLD_MINUTES + 1)]);

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession', 'expireSession');

    $order = app(OrderService::class)->createOrder($user, $event, [$ticketType->id => 10]);

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($old->refresh()->status)->toBe(OrderStatus::Pending);
});

test('a pending order still waiting for its checkout page is not cancelled but counts toward the limit', function (int $quantity, bool $allowed) {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 50]);
    $user = User::factory()->create();
    $inFlight = buyerOrder($user, $ticketType, 8, attributes: ['stripe_checkout_session_id' => null]);

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession', 'expireSession');

    $create = fn () => app(OrderService::class)->createOrder($user, $event, [$ticketType->id => $quantity]);

    if ($allowed) {
        expect($create()->status)->toBe(OrderStatus::Pending);
    } else {
        expect($create)->toThrow(PurchaseException::class);
    }

    expect($inFlight->refresh()->status)->toBe(OrderStatus::Pending);
})->with([
    'over the limit' => [3, false],
    'within the limit' => [2, true],
]);

test('orders of another event or another buyer are neither cancelled nor counted', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 50]);
    $otherTicketType = TicketType::factory()->for(Event::factory()->published())->create(['capacity' => 50]);
    $user = User::factory()->create();
    $otherEventOrder = buyerOrder($user, $otherTicketType, 10);
    $otherBuyerOrder = buyerOrder(User::factory()->create(), $ticketType, 10);
    $otherBuyerPaid = buyerOrder(User::factory()->create(), $ticketType, 10, 'paid');

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession', 'expireSession');

    $order = app(OrderService::class)->createOrder($user, $event, [$ticketType->id => 10]);

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($otherEventOrder->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($otherBuyerOrder->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($otherBuyerPaid->refresh()->status)->toBe(OrderStatus::Paid);
});

test('paying twice from the purchase page leaves a single pending order', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 50]);
    $user = User::factory()->create();
    session()->put("purchase.{$event->id}", [$ticketType->id => 6]);

    $checkout = $this->mock(CheckoutService::class);
    $checkout->shouldReceive('createSession')->twice()->andReturnUsing(function (Order $order): string {
        $order->forceFill(['stripe_checkout_session_id' => "cs_order_{$order->id}"])->save();

        return 'https://checkout.stripe.test/pay';
    });
    $checkout->shouldReceive('retrieveSession')->once()->andReturnUsing(
        fn (string $id) => sessionWithStatus($id, Session::STATUS_OPEN),
    );
    $checkout->shouldReceive('expireSession')->once();

    Livewire::actingAs($user)
        ->test('pages::events.purchase', ['event' => $event])
        ->call('pay')
        ->assertHasNoErrors()
        ->call('pay')
        ->assertHasNoErrors();

    expect(Order::where('status', OrderStatus::Pending)->count())->toBe(1)
        ->and(Order::where('status', OrderStatus::Cancelled)->count())->toBe(1)
        ->and($ticketType->remaining())->toBe(44);
});

test('confirmCheckout does not confirm a paid session whose amount or currency differs from the order', function (int $amountTotal, string $currency) {
    $order = payableOrder();
    $session = Session::constructFrom(['id' => 'cs_test_123', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_123', 'amount_total' => $amountTotal, 'currency' => $currency]);
    Log::spy();

    expect(app(OrderService::class)->confirmCheckout($order, $session))->toBeFalse()
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->tickets()->count())->toBe(0);

    Log::shouldHaveReceived('error')->once();
})->with([
    'lower amount' => [1, 'jpy'],
    'other currency' => [3000, 'usd'],
]);
