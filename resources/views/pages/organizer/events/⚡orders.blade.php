<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('売上')] class extends Component {
    use WithPagination;

    public Event $event;

    /**
     * Mount the component. Only the organizer of the event and admins may see its sales.
     */
    public function mount(Event $event): void
    {
        $this->authorize('viewOrders', $event);

        $this->event = $event;
    }

    /**
     * Check the permission again on every later request, such as changing the page.
     */
    public function hydrate(): void
    {
        $this->authorize('viewOrders', $this->event);
    }

    /**
     * Get the ticket types with their paid sales, summed in SQL.
     *
     * @return Collection<int, TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        return $this->event->ticketTypes()->withPaidSales()->orderBy('id')->get();
    }

    /**
     * Get the paid orders of the event, newest payment first, with the buyer and ticket count.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return $this->event->orders()
            ->where('status', OrderStatus::Paid)
            ->with('user')
            ->withSum('items as tickets_quantity', 'quantity')
            ->latest('paid_at')
            ->latest('id')
            ->paginate(20);
    }
}; ?>

<section class="w-full max-w-4xl space-y-8">
    <div class="space-y-2">
        <flux:heading size="xl" level="1">売上</flux:heading>
        <flux:text>{{ $event->title }}（{{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}・{{ $event->venue }}）</flux:text>
        <flux:text>集計しているのは支払い済みの注文だけです。</flux:text>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">券種ごとの販売状況</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>券種</flux:table.column>
                <flux:table.column align="end">単価</flux:table.column>
                <flux:table.column align="end">定員</flux:table.column>
                <flux:table.column align="end">販売枚数</flux:table.column>
                <flux:table.column align="end">売上</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->ticketTypes as $ticketType)
                    <flux:table.row :key="$ticketType->id" data-test="sales-row-{{ $ticketType->id }}">
                        <flux:table.cell variant="strong">{{ $ticketType->name }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($ticketType->price) }}円</flux:table.cell>
                        <flux:table.cell align="end">{{ $ticketType->capacity }} 枚</flux:table.cell>
                        <flux:table.cell align="end">{{ (int) $ticketType->sold_quantity }} 枚</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format((int) $ticketType->revenue) }}円</flux:table.cell>
                    </flux:table.row>
                @endforeach

                <flux:table.row data-test="sales-total">
                    <flux:table.cell variant="strong">合計</flux:table.cell>
                    <flux:table.cell></flux:table.cell>
                    <flux:table.cell></flux:table.cell>
                    <flux:table.cell align="end" variant="strong">{{ $this->ticketTypes->sum(fn (TicketType $ticketType) => (int) $ticketType->sold_quantity) }} 枚</flux:table.cell>
                    <flux:table.cell align="end" variant="strong">{{ number_format($this->ticketTypes->sum(fn (TicketType $ticketType) => (int) $ticketType->revenue)) }}円</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">支払い済みの注文</flux:heading>

        @if ($this->orders->isEmpty())
            <flux:text>まだ支払い済みの注文はありません。</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>注文番号</flux:table.column>
                    <flux:table.column>支払い日時</flux:table.column>
                    <flux:table.column>購入者</flux:table.column>
                    <flux:table.column align="end">枚数</flux:table.column>
                    <flux:table.column align="end">合計</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->orders as $order)
                        <flux:table.row :key="$order->id" data-test="paid-order-{{ $order->id }}">
                            <flux:table.cell>{{ $order->id }}</flux:table.cell>
                            <flux:table.cell>{{ $order->paid_at?->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                            <flux:table.cell>{{ $order->user->name }}</flux:table.cell>
                            <flux:table.cell align="end">{{ (int) $order->tickets_quantity }} 枚</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($order->total_amount) }}円</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <flux:pagination :paginator="$this->orders" />
        @endif
    </div>

    @can('update', $event)
        <flux:link :href="route('organizer.events.edit', $event)" wire:navigate>イベントの編集に戻る</flux:link>
    @endcan
</section>
