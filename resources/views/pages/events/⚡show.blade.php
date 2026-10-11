<?php

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::public')] class extends Component {
    public Event $event;

    /**
     * Mount the component. Unpublished events are a 404 for everyone but the organizer and admins.
     */
    public function mount(Event $event): void
    {
        $this->authorize('view', $event);

        $this->event = $event->load('organizer');
    }

    /**
     * Get the ticket types with the number of seats already held.
     *
     * @return Collection<int, TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        return $this->event->ticketTypes()->withHeldQuantity()->orderBy('price')->orderBy('id')->get();
    }

    /**
     * Render the component with the event title as the page title.
     */
    public function render(): View
    {
        return $this->view()->title($this->event->title);
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    @unless ($event->isPublished())
        <flux:callout icon="eye-slash" color="amber" heading="このイベントは公開されていません（{{ $event->status->label() }}）" text="主催者と管理者だけが見られます。" />
    @endunless

    <div class="space-y-3">
        <flux:heading size="xl" level="1">{{ $event->title }}</flux:heading>
        <div class="space-y-1">
            <flux:text class="flex items-center gap-2">
                <flux:icon.calendar variant="micro" />
                {{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}〜{{ $event->ends_at->isoFormat('M月D日(ddd) HH:mm') }}
            </flux:text>
            <flux:text class="flex items-center gap-2">
                <flux:icon.map-pin variant="micro" />
                {{ $event->venue }}
            </flux:text>
            <flux:text class="flex items-center gap-2">
                <flux:icon.user variant="micro" />
                主催: {{ $event->organizer->name }}
            </flux:text>
        </div>
    </div>

    <flux:text class="whitespace-pre-line">{{ $event->description }}</flux:text>

    <div class="space-y-3">
        <flux:heading size="lg">チケット</flux:heading>

        @if ($this->ticketTypes->isEmpty())
            <flux:text>券種がまだありません。</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>券種</flux:table.column>
                    <flux:table.column align="end">価格</flux:table.column>
                    <flux:table.column align="end">残り</flux:table.column>
                    <flux:table.column>販売期間</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->ticketTypes as $ticketType)
                        <flux:table.row :key="$ticketType->id">
                            <flux:table.cell variant="strong">{{ $ticketType->name }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $ticketType->price === 0 ? '無料' : number_format($ticketType->price).'円' }}</flux:table.cell>
                            <flux:table.cell align="end">残り {{ number_format($ticketType->remaining()) }} 枚</flux:table.cell>
                            <flux:table.cell>
                                {{ $ticketType->sale_starts_at->isoFormat('M月D日(ddd) HH:mm') }}〜{{ $ticketType->sale_ends_at->isoFormat('M月D日(ddd) HH:mm') }}
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($reason = $ticketType->unavailableReason())
                                    <flux:badge size="sm" color="zinc">{{ $reason }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="green">販売中</flux:badge>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>
