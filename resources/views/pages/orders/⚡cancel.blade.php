<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

new #[Layout('layouts::public'), Title('支払いを中断しました')] class extends Component {
    public Order $order;

    /**
     * Mount the component. Stripe sends the buyer here when they leave the payment page,
     * so the pending order is cancelled and its seats are released.
     */
    public function mount(Order $order, OrderService $orders): void
    {
        $this->authorize('cancel', $order);

        try {
            $orders->cancelCheckout($order);
        } catch (ApiErrorException $e) {
            report($e);
        }

        if ($order->status !== OrderStatus::Cancelled) {
            $this->redirectRoute('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id], navigate: true);

            return;
        }

        $this->order = $order->load('event');
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <div class="space-y-2">
        <flux:heading size="xl" level="1">支払いを中断しました</flux:heading>
        <flux:text>この注文（注文番号: {{ $order->id }}）は取り消しました。代金は請求されていません。</flux:text>
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <flux:button variant="primary" :href="route('events.show', $order->event)" wire:navigate>イベント詳細に戻る</flux:button>
        <flux:link :href="route('home')" wire:navigate>イベント一覧に戻る</flux:link>
    </div>
</section>
