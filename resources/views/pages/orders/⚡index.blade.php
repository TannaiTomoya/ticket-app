<?php

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('マイチケット')] class extends Component {
    use WithPagination;

    /**
     * Get the current user's orders, newest first, with their event and ticket count.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return Order::query()
            ->whereBelongsTo(Auth::user())
            ->with('event')
            ->withSum('items as tickets_quantity', 'quantity')
            ->latest()
            ->latest('id')
            ->paginate(10);
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">マイチケット</flux:heading>
        <flux:subheading>自分の注文の一覧です</flux:subheading>
    </div>

    @if ($this->orders->isEmpty())
        <flux:text>まだ注文がありません。</flux:text>
        <flux:link :href="route('home')" wire:navigate>イベント一覧へ</flux:link>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>注文日</flux:table.column>
                <flux:table.column>イベント</flux:table.column>
                <flux:table.column>開催日</flux:table.column>
                <flux:table.column align="end">枚数</flux:table.column>
                <flux:table.column align="end">合計</flux:table.column>
                <flux:table.column>状態</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->orders as $order)
                    <flux:table.row :key="$order->id" data-test="order-row-{{ $order->id }}">
                        <flux:table.cell>{{ $order->created_at?->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                        <flux:table.cell variant="strong">{{ $order->event->title }}</flux:table.cell>
                        <flux:table.cell>{{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                        <flux:table.cell align="end">{{ (int) $order->tickets_quantity }} 枚</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($order->total_amount) }}円</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$order->status->color()" inset="top bottom">{{ $order->status->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" :href="route('orders.show', $order)" wire:navigate>詳細</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <flux:pagination :paginator="$this->orders" />
    @endif
</section>
