<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * The order's owner, the event's organizer, and admins can view it.
     */
    public function view(User $user, Order $order): bool
    {
        return $user->id === $order->user_id
            || $user->id === $order->event->organizer_id
            || $user->isAdmin();
    }

    /**
     * Only the buyer can cancel their own order.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $user->id === $order->user_id;
    }
}
