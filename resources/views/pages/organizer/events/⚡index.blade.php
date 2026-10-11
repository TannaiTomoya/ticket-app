<?php

use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('主催者メニュー')] class extends Component {
    /**
     * Get the events organized by the current user, nearest first.
     *
     * @return Collection<int, Event>
     */
    #[Computed]
    public function events(): Collection
    {
        return Auth::user()->organizedEvents()->orderBy('starts_at')->get();
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">主催者メニュー</flux:heading>
            <flux:subheading>自分のイベントの一覧です</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('organizer.events.create')" wire:navigate>
            新規作成
        </flux:button>
    </div>

    @if ($this->events->isEmpty())
        <flux:text>まだイベントがありません。「新規作成」から登録してください。</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>タイトル</flux:table.column>
                <flux:table.column>開催日</flux:table.column>
                <flux:table.column>状態</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->events as $event)
                    <flux:table.row :key="$event->id">
                        <flux:table.cell variant="strong">
                            <flux:link :href="route('organizer.events.edit', $event)" wire:navigate>{{ $event->title }}</flux:link>
                        </flux:table.cell>
                        <flux:table.cell>{{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$event->status->color()">{{ $event->status->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" :href="route('organizer.events.edit', $event)" wire:navigate>編集</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
