<?php

use App\Models\Event;
use App\Models\TicketType;
use App\Services\OrderService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::public')] class extends Component {
    public Event $event;

    /**
     * The selected quantity per ticket type (ticket type id => quantity).
     *
     * @var array<int, int|string>
     */
    public array $quantities = [];

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
     * Get the largest quantity that can be selected for the ticket type.
     */
    public function maxSelectable(TicketType $ticketType): int
    {
        return min(OrderService::MAX_QUANTITY, $ticketType->remaining());
    }

    /**
     * Keep the selected quantities in the session and go to the purchase confirmation.
     * Guests are sent to the login page and come back to the confirmation afterwards.
     */
    public function proceed(): void
    {
        $this->authorize('view', $this->event);

        $selected = $this->ticketTypes
            ->filter(fn (TicketType $ticketType): bool => $ticketType->unavailableReason() === null)
            ->mapWithKeys(fn (TicketType $ticketType): array => [$ticketType->id => (int) ($this->quantities[$ticketType->id] ?? 0)])
            ->filter(fn (int $quantity): bool => $quantity > 0)
            ->all();

        if ($selected === []) {
            $this->addError('purchase', '枚数を選んでください。');

            return;
        }

        session()->put("purchase.{$this->event->id}", $selected);

        if (auth()->guest()) {
            session()->put('url.intended', route('events.purchase', $this->event));

            $this->redirectRoute('login');

            return;
        }

        $this->redirectRoute('events.purchase', $this->event, navigate: true);
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

        @php($isPurchasable = $event->isPublished() && ! $event->isFinished())

        @error('purchase')
            <flux:callout icon="exclamation-triangle" color="red" :heading="$message" />
        @enderror

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
                    @if ($isPurchasable)
                        <flux:table.column align="end">枚数</flux:table.column>
                    @endif
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
                            @if ($isPurchasable)
                                <flux:table.cell align="end">
                                    @if ($ticketType->unavailableReason() === null)
                                        <flux:select wire:model="quantities.{{ $ticketType->id }}" size="sm" class="w-20" aria-label="{{ $ticketType->name }}の枚数" data-test="quantity-select-{{ $ticketType->id }}">
                                            @foreach (range(0, $this->maxSelectable($ticketType)) as $quantity)
                                                <flux:select.option :value="$quantity">{{ $quantity }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    @endif
                                </flux:table.cell>
                            @endif
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            @if ($isPurchasable && $this->ticketTypes->contains(fn ($ticketType) => $ticketType->unavailableReason() === null))
                <div class="flex flex-wrap items-center justify-end gap-4">
                    @guest
                        <flux:text>ログインすると購入手続きに進めます。</flux:text>
                    @endguest
                    <flux:button variant="primary" wire:click="proceed" data-test="proceed-to-purchase-button">購入手続きへ進む</flux:button>
                </div>
            @endif
        @endif
    </div>
</section>
