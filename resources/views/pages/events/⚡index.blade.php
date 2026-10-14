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
     * Get the published upcoming events, nearest first, with their lowest and highest prices.
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
            ->withMax('ticketTypes', 'price')
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
                        <div class="flex aspect-video items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-700" data-test="event-image-placeholder">
                            <flux:icon.photo class="size-10 text-zinc-400 dark:text-zinc-500" />
                        </div>
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
                                @if ((int) $event->ticket_types_max_price === 0)
                                    無料
                                @elseif ((int) $event->ticket_types_min_price === 0)
                                    無料〜
                                @else
                                    {{ number_format($event->ticket_types_min_price) }}円〜
                                @endif
                            </flux:badge>
                        @endif
                    </flux:card>
                </a>
            @endforeach
        </div>

        <flux:pagination :paginator="$this->events" />
    @endif
</section>
