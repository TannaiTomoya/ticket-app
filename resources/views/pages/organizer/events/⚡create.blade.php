<?php

use App\Enums\EventStatus;
use App\Livewire\Forms\EventForm;
use App\Models\Event;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベントの登録')] class extends Component {
    public EventForm $form;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('create', Event::class);
    }

    /**
     * Create the event as a draft owned by the current user.
     */
    public function save(): void
    {
        $this->authorize('create', Event::class);

        $validated = $this->form->validate();

        $event = new Event($validated);
        $event->organizer_id = Auth::id();
        $event->status = EventStatus::Draft;
        $event->save();

        Flux::toast(variant: 'success', text: 'イベントを下書きで登録しました。');

        $this->redirectRoute('organizer.events.edit', $event, navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">イベントの登録</flux:heading>
        <flux:subheading>登録したイベントは下書きになります。公開は編集画面で切り替えます</flux:subheading>
    </div>

    <form wire:submit="save" class="space-y-6">
        @include('partials.event-form-fields')

        <div class="flex items-center gap-4">
            <flux:button variant="primary" type="submit" data-test="save-event-button">下書きで登録</flux:button>
            <flux:link :href="route('organizer.events.index')" wire:navigate>一覧に戻る</flux:link>
        </div>
    </form>
</section>
