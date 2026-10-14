<?php

use App\Livewire\Forms\TicketTypeForm;
use App\Models\Event;
use App\Models\TicketType;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Event $event;

    public TicketTypeForm $form;

    public ?int $editingId = null;

    /**
     * Mount the component.
     */
    public function mount(Event $event): void
    {
        $this->authorize('update', $event);

        $this->event = $event;
    }

    /**
     * Get the ticket types of the event.
     *
     * @return Collection<int, TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        return $this->event->ticketTypes()->withExists('orderItems')->orderBy('id')->get();
    }

    /**
     * Open the form for a new ticket type.
     */
    public function create(): void
    {
        $this->authorize('update', $this->event);

        $this->editingId = null;
        $this->form->reset();
        $this->form->resetErrorBag();
        $this->form->sale_starts_at = now()->format('Y-m-d\TH:i');
        $this->form->sale_ends_at = $this->event->starts_at->format('Y-m-d\TH:i');

        Flux::modal('ticket-type-form')->show();
    }

    /**
     * Open the form for an existing ticket type.
     */
    public function edit(int $id): void
    {
        $this->authorize('update', $this->event);

        $ticketType = $this->findTicketType($id);

        $this->editingId = $ticketType->id;
        $this->form->setTicketType($ticketType);
        $this->form->resetErrorBag();

        Flux::modal('ticket-type-form')->show();
    }

    /**
     * Create or update the ticket type.
     */
    public function save(): void
    {
        $this->authorize('update', $this->event);

        if ($this->editingId === null) {
            $this->event->ticketTypes()->create($this->validatedInput());

            Flux::toast(variant: 'success', text: '券種を追加しました。');
        } else {
            DB::transaction(function (): void {
                $ticketType = $this->event->ticketTypes()->lockForUpdate()->findOrFail($this->editingId);
                $ticketType->update($this->validatedInput($ticketType));
            });

            Flux::toast(variant: 'success', text: '券種を保存しました。');
        }

        $this->editingId = null;
        $this->form->reset();
        unset($this->ticketTypes);

        Flux::modal('ticket-type-form')->close();
    }

    /**
     * Delete the ticket type.
     */
    public function delete(int $id): void
    {
        $this->authorize('update', $this->event);

        $ticketType = $this->findTicketType($id);

        $this->authorize('delete', $ticketType);

        if ($this->event->isPublished() && $this->event->ticketTypes()->count() === 1) {
            Flux::toast(variant: 'danger', text: '公開中のイベントは、最後の券種を削除できません。先に下書きに戻してください。');

            return;
        }

        $ticketType->delete();
        unset($this->ticketTypes);

        Flux::toast(variant: 'success', text: '券種を削除しました。');
    }

    /**
     * Validate the form and return the values to save, with numbers as integers.
     * When editing, the ticket type row is locked by the caller so the held seats cannot change meanwhile.
     *
     * @return array<string, mixed>
     */
    private function validatedInput(?TicketType $ticketType = null): array
    {
        $validated = $this->form->validate($this->form->rulesFor($this->event, $ticketType), $this->form->messagesFor($this->event, $ticketType));

        return [...$validated, 'price' => (int) $validated['price'], 'capacity' => (int) $validated['capacity']];
    }

    /**
     * Find a ticket type that belongs to this event, so other events' ticket types cannot be touched.
     */
    private function findTicketType(int $id): TicketType
    {
        return $this->event->ticketTypes()->findOrFail($id);
    }
}; ?>

<div class="space-y-4">
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="lg">券種</flux:heading>
        <flux:button size="sm" icon="plus" wire:click="create" data-test="add-ticket-type-button">券種を追加</flux:button>
    </div>

    @if ($this->ticketTypes->isEmpty())
        <flux:text>券種がまだありません。公開するには券種を1つ以上追加してください。</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>券種</flux:table.column>
                <flux:table.column align="end">価格</flux:table.column>
                <flux:table.column align="end">定員</flux:table.column>
                <flux:table.column>販売期間</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->ticketTypes as $ticketType)
                    <flux:table.row :key="$ticketType->id">
                        <flux:table.cell variant="strong">{{ $ticketType->name }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($ticketType->price) }}円</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($ticketType->capacity) }}枚</flux:table.cell>
                        <flux:table.cell>
                            {{ $ticketType->sale_starts_at->isoFormat('M月D日(ddd) HH:mm') }}〜{{ $ticketType->sale_ends_at->isoFormat('M月D日(ddd) HH:mm') }}
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" wire:click="edit({{ $ticketType->id }})">編集</flux:button>
                                @unless ($ticketType->order_items_exists)
                                    <flux:button
                                        size="sm"
                                        variant="danger"
                                        wire:click="delete({{ $ticketType->id }})"
                                        wire:confirm="券種「{{ $ticketType->name }}」を削除しますか？"
                                    >削除</flux:button>
                                @endunless
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="ticket-type-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId === null ? '券種を追加' : '券種を編集' }}</flux:heading>

            <flux:input wire:model="form.name" label="券種名" placeholder="一般、学生 など" required maxlength="50" />

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="form.price" label="価格（円）" type="number" min="0" required description="0 円は無料券" />
                <flux:input wire:model="form.capacity" label="定員（枚）" type="number" min="1" required />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="form.sale_starts_at" label="販売開始" type="datetime-local" required />
                <flux:input wire:model="form.sale_ends_at" label="販売終了" type="datetime-local" required description="イベントの開始日時まで" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">キャンセル</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-ticket-type-button">保存</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
