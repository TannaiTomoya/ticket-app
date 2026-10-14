<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Mail\OrderPaidMail;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Throwable;

class OrderService
{
    /**
     * How long (in minutes) a pending order keeps its seats reserved.
     */
    public const PENDING_MINUTES = Order::HOLD_MINUTES;

    /**
     * The maximum quantity that can be purchased per ticket type in one order.
     */
    public const MAX_QUANTITY = 10;

    public function __construct(private CheckoutService $checkout) {}

    /**
     * Create a pending order with its items for the given ticket type quantities.
     * Each ticket type is limited to MAX_QUANTITY seats per buyer, counting paid and still-held orders.
     * The buyer's earlier checkouts for the event are cancelled, but only after a first check (without
     * locks, leaving those earlier orders out of the counts) shows the new purchase can go through,
     * so a purchase that fails does not throw away the earlier one.
     *
     * @param  array<int, int|string>  $quantities  ticket type id => quantity
     */
    public function createOrder(User $user, Event $event, array $quantities): Order
    {
        $quantities = array_filter(array_map(intval(...), $quantities), fn (int $quantity): bool => $quantity > 0);

        if ($quantities === []) {
            throw new PurchaseException('枚数を選んでください。');
        }

        if (! $event->isPublished() || $event->isFinished()) {
            throw new PurchaseException('このイベントは現在購入できません。');
        }

        ksort($quantities);

        $earlierCheckouts = $this->earlierCheckouts($user, $event);

        $this->checkLines($user, $event, $quantities, $earlierCheckouts->modelKeys(), lock: false);

        $this->cancelCheckouts($earlierCheckouts);

        return DB::transaction(function () use ($user, $event, $quantities): Order {
            $lines = $this->checkLines($user, $event, $quantities, [], lock: true);

            $order = new Order;
            $order->user_id = $user->id;
            $order->event_id = $event->id;
            $order->status = OrderStatus::Pending;
            $order->total_amount = array_sum(array_map(fn (array $line): int => $line['quantity'] * $line['ticket_type']->price, $lines));
            $order->save();

            foreach ($lines as $line) {
                $orderItem = new OrderItem;
                $orderItem->order_id = $order->id;
                $orderItem->ticket_type_id = $line['ticket_type']->id;
                $orderItem->quantity = $line['quantity'];
                $orderItem->unit_price = $line['ticket_type']->price;
                $orderItem->save();
            }

            return $order;
        });
    }

    /**
     * Check every selected ticket type and return the order lines, or throw the reason it cannot be bought.
     * With $lock, the ticket type rows are locked (in id order) before counting, so the counts read the
     * latest committed orders instead of a snapshot taken before a lock wait.
     *
     * @param  array<int, int>  $quantities  ticket type id => quantity
     * @param  array<int, int>  $ignoredOrderIds  orders about to be cancelled, left out of the counts
     * @return list<array{ticket_type: TicketType, quantity: int}>
     *
     * @throws PurchaseException
     */
    private function checkLines(User $user, Event $event, array $quantities, array $ignoredOrderIds, bool $lock): array
    {
        $query = TicketType::where('event_id', $event->id)
            ->whereKey(array_keys($quantities))
            ->orderBy('id');

        $ticketTypes = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');

        $lines = [];

        foreach ($quantities as $ticketTypeId => $quantity) {
            $ticketType = $ticketTypes->get($ticketTypeId);

            if ($ticketType === null) {
                throw new PurchaseException('選択した券種が見つかりません。');
            }

            if ($quantity > self::MAX_QUANTITY) {
                throw new PurchaseException('券種ごとに購入できるのは1枚から'.self::MAX_QUANTITY.'枚までです。');
            }

            $heldByBuyer = $this->heldQuantity($ticketType, $ignoredOrderIds, $user);

            if ($heldByBuyer + $quantity > self::MAX_QUANTITY) {
                $detail = $heldByBuyer > 0 ? "（購入済み・支払い中の {$heldByBuyer} 枚を含みます）" : '';

                throw new PurchaseException("「{$ticketType->name}」は1人".self::MAX_QUANTITY."枚までです{$detail}。");
            }

            if (! $ticketType->isOnSale()) {
                throw new PurchaseException("「{$ticketType->name}」は現在販売期間外です。");
            }

            if ($quantity > $ticketType->capacity - $this->heldQuantity($ticketType, $ignoredOrderIds)) {
                throw new PurchaseException("「{$ticketType->name}」の残り枚数が足りません。");
            }

            $lines[] = ['ticket_type' => $ticketType, 'quantity' => $quantity];
        }

        return $lines;
    }

    /**
     * Get the buyer's pending orders for the event that still hold seats and already have a Checkout Session.
     * Orders without a Checkout Session yet are being created by another request right now, so they
     * are not cancelled (that request would send the buyer to pay a cancelled order); they still count.
     *
     * @return Collection<int, Order>
     */
    private function earlierCheckouts(User $user, Event $event): Collection
    {
        return Order::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($event)
            ->where('status', OrderStatus::Pending)
            ->whereNotNull('stripe_checkout_session_id')
            ->holdingSeats()
            ->get();
    }

    /**
     * Cancel the given checkouts, outside the transaction because it calls Stripe.
     * A checkout that was already paid is left alone and counts toward the limit in the locked check.
     *
     * @param  Collection<int, Order>  $orders
     *
     * @throws PurchaseException when Stripe cannot be reached to cancel an earlier checkout
     */
    private function cancelCheckouts(Collection $orders): void
    {
        foreach ($orders as $order) {
            try {
                $this->cancelCheckout($order);
            } catch (ApiErrorException $e) {
                report($e);

                throw new PurchaseException('前の支払い手続きを取り消せませんでした。時間をおいてもう一度お試しください。');
            }
        }
    }

    /**
     * Count the seats of the ticket type held by paid and recent pending orders, leaving out the given
     * orders. With a buyer, only that buyer's orders are counted.
     *
     * @param  array<int, int>  $ignoredOrderIds
     */
    private function heldQuantity(TicketType $ticketType, array $ignoredOrderIds, ?User $buyer = null): int
    {
        $holdingOrders = Order::query()
            ->holdingSeats()
            ->whereKeyNot($ignoredOrderIds)
            ->when($buyer !== null, fn (Builder $query) => $query->whereBelongsTo($buyer))
            ->select('id');

        return (int) $ticketType->orderItems()->whereIn('order_id', $holdingOrders)->sum('quantity');
    }

    /**
     * Mark the order as paid, issue one ticket per purchased seat and mail the buyer.
     * Only a pending order is updated, so concurrent calls confirm it (and send the mail) once.
     */
    public function markAsPaid(Order $order, ?string $paymentIntentId = null): bool
    {
        $paid = DB::transaction(function () use ($order, $paymentIntentId): bool {
            $affected = Order::query()
                ->whereKey($order->id)
                ->where('status', OrderStatus::Pending)
                ->update([
                    'status' => OrderStatus::Paid,
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                return false;
            }

            $order->items()->get()->each(
                fn (OrderItem $item) => $item->tickets()->createMany(array_fill(0, $item->quantity, [])),
            );

            return true;
        });

        $order->refresh();

        if ($paid) {
            $this->sendPaidMail($order);
        }

        return $paid;
    }

    /**
     * Send the purchase mail outside the transaction. A failure is only reported,
     * because the order is already paid and must not turn into a failed webhook.
     */
    private function sendPaidMail(Order $order): void
    {
        $order->load(['user', 'event', 'items.ticketType']);

        try {
            Mail::to($order->user)->send(new OrderPaidMail($order));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Mark the order as paid when the Checkout Session belongs to it, has been paid,
     * and charged exactly the order total in yen. A mismatched amount is logged and not confirmed.
     */
    public function confirmCheckout(Order $order, Session $session): bool
    {
        if ($session->id !== $order->stripe_checkout_session_id
            || $session->payment_status !== Session::PAYMENT_STATUS_PAID) {
            return false;
        }

        if ($session->amount_total !== $order->total_amount || $session->currency !== 'jpy') {
            Log::error('Stripe checkout amount does not match the order.', [
                'order' => $order->id,
                'session' => $session->id,
                'amount_total' => $session->amount_total,
                'currency' => $session->currency,
                'total_amount' => $order->total_amount,
            ]);

            return false;
        }

        return $this->markAsPaid($order, is_string($session->payment_intent) ? $session->payment_intent : null);
    }

    /**
     * Cancel the order if it is still pending. Its seats are released because cancelled orders hold none.
     */
    public function cancel(Order $order): bool
    {
        $cancelled = Order::whereKey($order->id)
            ->where('status', OrderStatus::Pending)
            ->update(['status' => OrderStatus::Cancelled, 'updated_at' => now()]) === 1;

        $order->refresh();

        return $cancelled;
    }

    /**
     * Cancel a pending order whose payment was abandoned, expiring its Checkout Session first
     * so the payment page cannot be reopened and paid. A completed session is left to be confirmed.
     *
     * @throws ApiErrorException
     */
    public function cancelCheckout(Order $order): bool
    {
        if ($order->status !== OrderStatus::Pending) {
            return false;
        }

        if ($order->stripe_checkout_session_id !== null) {
            $session = $this->checkout->retrieveSession($order->stripe_checkout_session_id);

            if ($session->status === Session::STATUS_COMPLETE) {
                return false;
            }

            if ($session->status === Session::STATUS_OPEN) {
                $this->checkout->expireSession($order->stripe_checkout_session_id);
            }
        }

        return $this->cancel($order);
    }
}
