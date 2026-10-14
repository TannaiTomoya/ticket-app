<?php

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

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
