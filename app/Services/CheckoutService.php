<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class CheckoutService
{
    /**
     * How long (in minutes) the Stripe payment page stays open. Shorter than Order::HOLD_MINUTES,
     * so a payment can never complete after the order has released its seats.
     */
    public const EXPIRES_MINUTES = 30;

    public function __construct(private StripeClient $stripe) {}

    /**
     * Create a Stripe Checkout Session for the order and return the payment page URL.
     *
     * @throws ApiErrorException
     */
    public function createSession(Order $order): string
    {
        $order->loadMissing('user', 'event', 'items.ticketType');

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'allowed_payment_method_types' => ['card'],
            'line_items' => $order->items->map(fn (OrderItem $item): array => [
                'price_data' => [
                    'currency' => 'jpy',
                    'unit_amount' => $item->unit_price,
                    'product_data' => [
                        'name' => "{$order->event->title} {$item->ticketType->name}",
                    ],
                ],
                'quantity' => $item->quantity,
            ])->values()->all(),
            'customer_email' => $order->user->email,
            'metadata' => [
                'order_id' => (string) $order->id,
            ],
            'expires_at' => now()->addMinutes(self::EXPIRES_MINUTES)->getTimestamp(),
            'success_url' => route('orders.complete', $order).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('orders.cancel', $order),
        ]);

        $order->stripe_checkout_session_id = $session->id;
        $order->save();

        return (string) $session->url;
    }

    /**
     * Retrieve a Stripe Checkout Session.
     *
     * @throws ApiErrorException
     */
    public function retrieveSession(string $id): Session
    {
        return $this->stripe->checkout->sessions->retrieve($id);
    }

    /**
     * Expire an open Stripe Checkout Session so it can no longer be paid.
     *
     * @throws ApiErrorException
     */
    public function expireSession(string $id): Session
    {
        return $this->stripe->checkout->sessions->expire($id);
    }

    /**
     * Build a Stripe event from a webhook request, or return null when the signature or payload is invalid.
     */
    public function constructEvent(string $payload, string $signature): ?Event
    {
        try {
            return Webhook::constructEvent($payload, $signature, (string) config('services.stripe.webhook_secret'));
        } catch (UnexpectedValueException|SignatureVerificationException) {
            return null;
        }
    }
}
