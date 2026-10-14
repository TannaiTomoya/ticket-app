<?php

use App\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public'), Title('注文を受け付けました')] class extends Component {
    public Order $order;

    /**
     * Mount the component. Only the buyer, the event's organizer and admins may see the order.
     */
    public function mount(Order $order): void
    {
        $this->authorize('view', $order);

        $this->order = $order->load('event', 'items.ticketType');
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <div class="space-y-2">
        <flux:heading size="xl" level="1">注文を受け付けました</flux:heading>
        <flux:text>支払いは平日課題で実装します。この注文は「{{ $order->status->label() }}」のまま、作成から15分間だけ枠を押さえます。</flux:text>
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
