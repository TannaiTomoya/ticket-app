<?php

use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\OrderService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public'), Title('購入確認')] class extends Component {
    public Event $event;

    /**
     * The quantities chosen on the event page (ticket type id => quantity).
     *
     * @var array<int, int|string>
     */
    public array $quantities = [];

    /**
     * Mount the component with the quantities kept in the session.
     */
    public function mount(Event $event): void
    {
        $this->authorize('view', $event);

        $this->event = $event;
        $this->quantities = session()->get($this->selectionKey(), []);

        if ($this->quantities === []) {
            $this->redirectRoute('events.show', $event, navigate: true);
        }
    }

    /**
     * Build the order lines from the database prices, never from client input.
     *
     * @return list<array{ticket_type: TicketType, quantity: int, subtotal: int}>
     */
    #[Computed]
    public function lines(): array
    {
        return $this->event->ticketTypes()
            ->whereKey(array_keys($this->quantities))
            ->orderBy('price')
            ->orderBy('id')
            ->get()
            ->map(fn (TicketType $ticketType): array => [
                'ticket_type' => $ticketType,
                'quantity' => (int) $this->quantities[$ticketType->id],
                'subtotal' => (int) $this->quantities[$ticketType->id] * $ticketType->price,
            ])
            ->values()
            ->all();
    }

    /**
     * Get the total amount of the order lines.
     */
    #[Computed]
    public function total(): int
    {
        return array_sum(array_column($this->lines, 'subtotal'));
    }

    /**
     * Create the pending order. Stock, sale period and quantity limits are checked by the service.
     */
    public function pay(OrderService $orders): void
    {
        $this->authorize('view', $this->event);

        try {
            $order = $orders->createOrder(auth()->user(), $this->event, $this->quantities);
        } catch (PurchaseException $e) {
            $this->addError('purchase', $e->getMessage());

            return;
        }

        session()->forget($this->selectionKey());

        $this->redirectRoute('orders.complete', $order, navigate: true);
    }

    /**
     * Get the session key holding the selection for this event.
     */
    private function selectionKey(): string
    {
        return "purchase.{$this->event->id}";
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <div class="space-y-2">
        <flux:heading size="xl" level="1">購入確認</flux:heading>
        <flux:text>{{ $event->title }}（{{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}・{{ $event->venue }}）</flux:text>
    </div>

    @error('purchase')
        <flux:callout icon="exclamation-triangle" color="red" :heading="$message" text="イベント詳細に戻って選び直してください。" />
    @enderror

    <flux:table>
        <flux:table.columns>
            <flux:table.column>券種</flux:table.column>
            <flux:table.column align="end">単価</flux:table.column>
            <flux:table.column align="end">枚数</flux:table.column>
            <flux:table.column align="end">小計</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->lines as $line)
                <flux:table.row :key="$line['ticket_type']->id">
                    <flux:table.cell variant="strong">{{ $line['ticket_type']->name }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($line['ticket_type']->price) }}円</flux:table.cell>
                    <flux:table.cell align="end">{{ $line['quantity'] }} 枚</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($line['subtotal']) }}円</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <div class="flex justify-end">
        <flux:heading size="lg">合計 {{ number_format($this->total) }}円</flux:heading>
    </div>

    <flux:text>支払い（Stripe Checkout）は平日課題で実装します。いまは「支払いへ進む」で支払い待ちの注文を作ります。</flux:text>

    <div class="flex flex-wrap items-center justify-end gap-4">
        <flux:link :href="route('events.show', $event)" wire:navigate>イベント詳細に戻る</flux:link>
        <flux:button variant="primary" wire:click="pay" data-test="pay-button">支払いへ進む</flux:button>
    </div>
</section>
