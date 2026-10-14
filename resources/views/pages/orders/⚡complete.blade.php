<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

new #[Layout('layouts::public'), Title('購入手続き')] class extends Component {
    public Order $order;

    /**
     * The Checkout Session id Stripe appended to the success URL. Kept for polling, never set by the browser.
     */
    #[Locked]
    public ?string $sessionId = null;

    /**
     * Mount the component. Only the buyer, the event's organizer and admins may see the order.
     */
    public function mount(Order $order, OrderService $orders, CheckoutService $checkout): void
    {
        $this->authorize('view', $order);

        $this->order = $order->load('event', 'items.ticketType');
        $sessionId = request()->query('session_id');
        $this->sessionId = is_string($sessionId) ? $sessionId : null;

        $this->confirmPayment($orders, $checkout);
    }

    /**
     * Ask Stripe whether the Checkout Session was paid, in case the webhook has not arrived yet.
     */
    public function confirmPayment(OrderService $orders, CheckoutService $checkout): void
    {
        $this->authorize('view', $this->order);

        $this->order->refresh();

        if ($this->order->status !== OrderStatus::Pending
            || $this->sessionId === null
            || $this->sessionId !== $this->order->stripe_checkout_session_id) {
            return;
        }

        try {
            $orders->confirmCheckout($this->order, $checkout->retrieveSession($this->sessionId));
        } catch (ApiErrorException $e) {
            report($e);
        }

        unset($this->ticketCount);
    }

    /**
     * Get the number of tickets issued for the order.
     */
    #[Computed]
    public function ticketCount(): int
    {
        return $this->order->tickets()->count();
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <div class="space-y-2">
        @if ($order->status === OrderStatus::Paid)
            <flux:heading size="xl" level="1">購入が完了しました</flux:heading>
            <flux:text>チケットを {{ $this->ticketCount }} 枚発行しました。</flux:text>
            <flux:button variant="primary" :href="route('orders.show', $order)" wire:navigate>注文の詳細とチケットを見る</flux:button>
        @elseif ($order->status === OrderStatus::Pending)
            <div wire:poll.5s="confirmPayment" class="space-y-2">
                <flux:heading size="xl" level="1">支払いを確認しています</flux:heading>
                <flux:text>確認が済むと、この画面は自動で切り替わります。</flux:text>
            </div>
        @else
            <flux:heading size="xl" level="1">この注文は取り消されています</flux:heading>
        @endif
    </div>

    <div class="space-y-1">
        <flux:text>注文番号: {{ $order->id }}</flux:text>
        <flux:text>イベント: {{ $order->event->title }}（{{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}）</flux:text>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>券種</flux:table.column>
            <flux:table.column align="end">単価</flux:table.column>
            <flux:table.column align="end">枚数</flux:table.column>
            <flux:table.column align="end">小計</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($order->items as $item)
                <flux:table.row :key="$item->id">
                    <flux:table.cell variant="strong">{{ $item->ticketType->name }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($item->unit_price) }}円</flux:table.cell>
                    <flux:table.cell align="end">{{ $item->quantity }} 枚</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($item->subtotal()) }}円</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <div class="flex justify-end">
        <flux:heading size="lg">合計 {{ number_format($order->total_amount) }}円</flux:heading>
    </div>

    <flux:link :href="route('home')" wire:navigate>イベント一覧に戻る</flux:link>
</section>
