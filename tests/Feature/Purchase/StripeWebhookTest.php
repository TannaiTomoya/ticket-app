<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\TicketType;
use Illuminate\Testing\TestResponse;

/**
 * Create a pending order with one item (3 seats) and a Checkout Session id.
 */
function webhookOrder(int $quantity = 3, bool $cancelled = false): Order
{
    $ticketType = TicketType::factory()->for(Event::factory()->published())->create(['capacity' => 10]);
    $order = Order::factory()->for($ticketType->event)
        ->when($cancelled, fn ($factory) => $factory->cancelled())
        ->create(['stripe_checkout_session_id' => 'cs_test_123']);
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => $quantity]);

    return $order;
}

/**
 * Build a Stripe event JSON payload carrying a Checkout Session.
 *
 * @param  array<string, mixed>  $session
 */
function webhookPayload(Order $order, array $session = [], string $type = 'checkout.session.completed'): string
{
    return json_encode([
        'id' => 'evt_test_123',
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => array_merge([
            'id' => $order->stripe_checkout_session_id,
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_123',
            'metadata' => ['order_id' => (string) $order->id],
        ], $session)],
    ], JSON_THROW_ON_ERROR);
}

/**
 * Send the payload to the webhook, signed with the given secret (or unsigned when null).
 */
function postWebhook(string $payload, ?string $secret = 'whsec_dummy'): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($secret !== null) {
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        $server['HTTP_STRIPE_SIGNATURE'] = "t={$timestamp},v1={$signature}";
    }

    return test()->call('POST', route('stripe.webhook'), [], [], [], $server, $payload);
}

test('a signed completed notification marks the order paid and issues the tickets', function () {
    $order = webhookOrder(quantity: 3);

    postWebhook(webhookPayload($order))->assertOk();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->stripe_payment_intent_id)->toBe('pi_test_123')
        ->and($order->tickets()->count())->toBe(3);
});

test('a bad or missing signature is rejected and changes nothing', function (?string $secret) {
    $order = webhookOrder();

    postWebhook(webhookPayload($order), $secret)->assertStatus(400);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->stripe_payment_intent_id)->toBeNull()
        ->and(Ticket::count())->toBe(0);
})->with([
    'wrong secret' => ['whsec_wrong'],
    'no signature' => [null],
]);

test('the same notification twice is accepted both times and issues tickets once', function () {
    $order = webhookOrder(quantity: 3);
    $payload = webhookPayload($order);

    postWebhook($payload)->assertOk();
    postWebhook($payload)->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(Ticket::count())->toBe(3);
});

test('notifications that do not confirm a payment leave the order pending', function (array $session, string $type) {
    $order = webhookOrder();

    postWebhook(webhookPayload($order, $session, $type))->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
})->with([
    'unpaid session' => [['payment_status' => 'unpaid'], 'checkout.session.completed'],
    'expired session' => [[], 'checkout.session.expired'],
    'session id of another order' => [['id' => 'cs_test_other'], 'checkout.session.completed'],
]);

test('a notification for an unknown order is acknowledged', function () {
    $order = webhookOrder();
    $payload = webhookPayload($order, ['metadata' => ['order_id' => '999999']]);

    postWebhook($payload)->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('a cancelled order is not revived by a late notification', function () {
    $order = webhookOrder(cancelled: true);

    postWebhook(webhookPayload($order))->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(Ticket::count())->toBe(0);
});
