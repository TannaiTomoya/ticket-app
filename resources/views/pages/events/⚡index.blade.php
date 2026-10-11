<?php

use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::public'), Title('イベント一覧')] class extends Component {
    use WithPagination;

    /**
     * Get the published upcoming events, nearest first, with their lowest price.
     *
     * @return LengthAwarePaginator<int, Event>
     */
    #[Computed]
    public function events(): LengthAwarePaginator
    {
        return Event::query()
            ->published()
            ->upcoming()
            ->withMin('ticketTypes', 'price')
            ->orderBy('starts_at')
            ->paginate(12);
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">イベント一覧</flux:heading>
        <flux:subheading>開催日が近い順に並んでいます</flux:subheading>
    </div>

    @if ($this->events->isEmpty())
        <flux:text>いま募集中のイベントはありません。</flux:text>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->events as $event)
                <a href="{{ route('events.show', $event) }}" wire:navigate wire:key="event-{{ $event->id }}" class="block" data-test="event-card">
                    <flux:card class="h-full space-y-3 transition hover:border-zinc-400 dark:hover:border-zinc-500">
                        <flux:heading size="lg">{{ $event->title }}</flux:heading>
                        <div class="space-y-1">
                            <flux:text class="flex items-center gap-2">
                                <flux:icon.calendar variant="micro" />
                                {{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}
                            </flux:text>
                            <flux:text class="flex items-center gap-2">
                                <flux:icon.map-pin variant="micro" />
                                {{ $event->venue }}
                            </flux:text>
                        </div>
                        @if ($event->ticket_types_min_price !== null)
                            <flux:badge color="zinc">
                                {{ (int) $event->ticket_types_min_price === 0 ? '無料' : number_format($event->ticket_types_min_price).'円' }}〜
                            </flux:badge>
                        @endif
                    </flux:card>
                </a>
            @endforeach
        </div>

        <flux:pagination :paginator="$this->events" />
    @endif
</section>
