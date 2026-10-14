<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;

class StripeWebhookController extends Controller
{
    /**
     * Confirm a paid order from a signed Stripe notification.
     * Anything other than a bad signature gets 2xx so Stripe does not keep retrying.
     */
    public function __invoke(Request $request, CheckoutService $checkout, OrderService $orders): Response
    {
        $event = $checkout->constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'));

        if ($event === null) {
            return response('Invalid signature', 400);
        }

        $session = $event->data->object;

        if ($event->type !== 'checkout.session.completed' || ! $session instanceof Session) {
            return response('Ignored');
        }

        $orderId = $session->metadata['order_id'] ?? null;
        $order = is_string($orderId) ? Order::find($orderId) : null;

        if ($order === null) {
            Log::warning('Stripe webhook for an unknown order.', ['session' => $session->id]);

            return response('Unknown order');
        }

        $orders->confirmCheckout($order, $session);

        return response('OK');
    }
}
