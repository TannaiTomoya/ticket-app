<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Services\CheckoutService;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

test('creates a Checkout Session from the order items and stores its id', function () {
    $this->freezeTime();
    $event = Event::factory()->published()->create(['title' => '朝市']);
    $ticketType = TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 3000]);
    $order = Order::factory()->for($event)->create(['total_amount' => 4000]);
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => 2, 'unit_price' => 2000]);

    $stripe = Mockery::mock(StripeClient::class, [['api_key' => 'sk_test_dummy']])->makePartial();
    $stripe->shouldReceive('request')
        ->once()
        ->with('post', '/v1/checkout/sessions', Mockery::on(fn (array $params): bool => $params === [
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'jpy',
                    'unit_amount' => 2000,
                    'product_data' => ['name' => '朝市 一般'],
                ],
                'quantity' => 2,
            ]],
            'customer_email' => $order->user->email,
            'metadata' => ['order_id' => (string) $order->id],
            'expires_at' => now()->addMinutes(30)->getTimestamp(),
            'success_url' => route('orders.complete', $order).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('orders.cancel', $order),
        ]), null)
        ->andReturn(Session::constructFrom(['id' => 'cs_test_abc', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc']));

    $url = (new CheckoutService($stripe))->createSession($order);

    expect($url)->toBe('https://checkout.stripe.com/c/pay/cs_test_abc')
        ->and($order->refresh()->stripe_checkout_session_id)->toBe('cs_test_abc');
});

test('the payment page expires before the order releases its seats', function () {
    expect(CheckoutService::EXPIRES_MINUTES)->toBeLessThan(Order::HOLD_MINUTES);
});
