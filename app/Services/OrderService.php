<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

    /**
     * Create a pending order with its items for the given ticket type quantities.
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

        return DB::transaction(function () use ($user, $event, $quantities): Order {
            $lines = [];
            $totalAmount = 0;

            foreach ($quantities as $ticketTypeId => $quantity) {
                $ticketType = TicketType::where('event_id', $event->id)
                    ->lockForUpdate()
                    ->find($ticketTypeId);

                if ($ticketType === null) {
                    throw new PurchaseException('選択した券種が見つかりません。');
                }

                if ($quantity > self::MAX_QUANTITY) {
                    throw new PurchaseException('券種ごとに購入できるのは1枚から'.self::MAX_QUANTITY.'枚までです。');
                }

                if (! $ticketType->isOnSale()) {
                    throw new PurchaseException("「{$ticketType->name}」は現在販売期間外です。");
                }

                if ($quantity > $ticketType->remaining()) {
                    throw new PurchaseException("「{$ticketType->name}」の残り枚数が足りません。");
                }

                $lines[] = ['ticket_type' => $ticketType, 'quantity' => $quantity];
                $totalAmount += $quantity * $ticketType->price;
            }

            $order = new Order;
            $order->user_id = $user->id;
            $order->event_id = $event->id;
            $order->status = OrderStatus::Pending;
            $order->total_amount = $totalAmount;
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
}
