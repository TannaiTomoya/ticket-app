<?php

use App\Enums\EventStatus;
use App\Livewire\Forms\EventForm;
use App\Models\Event;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベントの編集')] class extends Component {
    public Event $event;

    public EventForm $form;

    /**
     * Mount the component.
     */
    public function mount(Event $event): void
    {
        $this->authorize('update', $event);

        $this->event = $event;
        $this->form->setEvent($event);
    }

    /**
     * Update the event details.
     */
    public function save(): void
    {
        $this->authorize('update', $this->event);

        $this->event->update($this->form->validate($this->form->rulesFor($this->event), $this->form->messagesFor($this->event)));

        Flux::toast(variant: 'success', text: 'イベントを保存しました。');
    }

    /**
     * Switch the event between draft and published.
     */
    public function togglePublish(): void
    {
        $this->authorize('update', $this->event);

        abort_if($this->event->status === EventStatus::Unpublished, 403, '管理者により非公開にされたイベントは公開できません。');

        if (! $this->event->isPublished() && $this->event->ticketTypes()->doesntExist()) {
            Flux::toast(variant: 'danger', text: '公開するには券種を1つ以上追加してください。');

            return;
        }

        $this->event->status = $this->event->isPublished() ? EventStatus::Draft : EventStatus::Published;
        $this->event->save();

        Flux::toast(variant: 'success', text: $this->event->isPublished() ? 'イベントを公開しました。' : 'イベントを下書きに戻しました。');
    }

    /**
     * Delete the event.
     */
    public function deleteEvent(): void
    {
        $this->authorize('delete', $this->event);

        $this->event->delete();

        Flux::toast(variant: 'success', text: 'イベントを削除しました。');

        $this->redirectRoute('organizer.events.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">イベントの編集</flux:heading>
            <div class="mt-2">
                <flux:badge size="sm" :color="$event->status->color()">{{ $event->status->label() }}</flux:badge>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button icon="chart-bar" :href="route('organizer.events.orders', $event)" wire:navigate data-test="sales-link">売上を見る</flux:button>

            @if ($event->status !== \App\Enums\EventStatus::Unpublished)
                <flux:button wire:click="togglePublish" :variant="$event->isPublished() ? 'filled' : 'primary'" data-test="toggle-publish-button">
                    {{ $event->isPublished() ? '下書きに戻す' : '公開する' }}
                </flux:button>
            @else
                <flux:text>管理者により非公開にされています。</flux:text>
            @endif
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        @include('partials.event-form-fields')

        <div class="flex items-center gap-4">
            <flux:button variant="primary" type="submit" data-test="save-event-button">保存</flux:button>
            <flux:link :href="route('organizer.events.index')" wire:navigate>一覧に戻る</flux:link>
        </div>
    </form>

    <flux:separator />

    <livewire:pages::organizer.events.ticket-types :event="$event" />

    <flux:separator />

    <div class="space-y-3">
        <flux:heading size="lg">イベントの削除</flux:heading>

        @can('delete', $event)
            <flux:button
                variant="danger"
                wire:click="deleteEvent"
                wire:confirm="「{{ $event->title }}」を削除しますか？券種も一緒に削除されます。元に戻せません。"
                data-test="delete-event-button"
            >削除する</flux:button>
        @else
            <flux:text>注文があるイベントは削除できません。</flux:text>
        @endcan
    </div>
</section>
