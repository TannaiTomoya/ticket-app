<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('注文の詳細')] class extends Component {
    public Order $order;

    /**
     * Mount the component. Only the buyer, the event's organizer and admins may see the order.
     */
    public function mount(Order $order): void
    {
        $this->authorize('view', $order);

        $this->order = $order->load(['event', 'items.ticketType', 'items.tickets']);
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <div class="space-y-2">
        <div class="flex items-center gap-3">
            <flux:heading size="xl" level="1">注文番号 {{ $order->id }}</flux:heading>
            <flux:badge :color="$order->status->color()">{{ $order->status->label() }}</flux:badge>
        </div>
        <flux:text>
            <flux:link :href="route('events.show', $order->event)" wire:navigate>{{ $order->event->title }}</flux:link>
            （{{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}・{{ $order->event->venue }}）
        </flux:text>
    </div>

    @if ($order->status === OrderStatus::Pending)
        <flux:callout icon="clock" color="amber" heading="支払いが完了していません"
            text="支払いの確認が済むとチケットが発行されます。時間が経っても変わらないときは、イベント詳細からもう一度購入してください。" />
    @elseif ($order->status !== OrderStatus::Paid)
        <flux:callout icon="x-circle" color="zinc" :heading="'この注文は'.$order->status->label().'です'" />
    @endif

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

    @if ($order->status === OrderStatus::Paid)
        <div class="space-y-3">
            <flux:heading size="lg">チケット</flux:heading>
            <flux:text>当日は、このコードを受付でお見せください。</flux:text>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>券種</flux:table.column>
                    <flux:table.column>コード</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($order->items as $item)
                        @foreach ($item->tickets as $ticket)
                            <flux:table.row :key="$ticket->id">
                                <flux:table.cell>{{ $item->ticketType->name }}</flux:table.cell>
                                <flux:table.cell class="font-mono" data-test="ticket-code">{{ $ticket->code }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:link :href="route('orders.index')" wire:navigate>マイチケットに戻る</flux:link>
</section>
