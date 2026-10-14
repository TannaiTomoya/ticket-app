<?php

namespace App\Livewire\Forms;

use App\Models\Event;
use App\Models\TicketType;
use Livewire\Form;

class TicketTypeForm extends Form
{
    public string $name = '';

    public int|string $price = '';

    public int|string $capacity = '';

    public string $sale_starts_at = '';

    public string $sale_ends_at = '';

    /**
     * Get the validation rules for a ticket type of the given event.
     * When editing, the capacity cannot go below the seats already held (paid + recent pending).
     *
     * @return array<string, array<int, string>>
     */
    public function rulesFor(Event $event, ?TicketType $ticketType = null): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'price' => ['required', 'integer', 'min:0', 'max:1000000'],
            'capacity' => ['required', 'integer', 'min:'.max(1, $this->heldQuantity($ticketType)), 'max:10000'],
            'sale_starts_at' => ['required', 'date'],
            'sale_ends_at' => [
                'required',
                'date',
                'after:sale_starts_at',
                'before_or_equal:'.$event->starts_at->format('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * Get the Japanese names of the fields used in validation messages, matching the labels on the screen.
     * Livewire reads this on every validation, including validate() without arguments.
     *
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'name' => '券種名',
            'price' => '価格',
            'capacity' => '定員',
            'sale_starts_at' => '販売開始',
            'sale_ends_at' => '販売終了',
        ];
    }

    /**
     * Get the custom validation messages for a ticket type of the given event.
     *
     * @return array<string, string>
     */
    public function messagesFor(Event $event, ?TicketType $ticketType = null): array
    {
        $messages = [
            'sale_ends_at.before_or_equal' => '販売終了はイベントの開始日時（'.$event->starts_at->isoFormat('M月D日(ddd) HH:mm').'）以前にしてください。',
        ];

        $heldQuantity = $this->heldQuantity($ticketType);

        if ($heldQuantity > 0) {
            $messages['capacity.min'] = "定員は、支払い済みと支払い待ちの枚数（{$heldQuantity}枚）より少なくできません。";
        }

        return $messages;
    }

    /**
     * Get the seats held by paid and recent pending orders for the ticket type (0 for a new one).
     */
    private function heldQuantity(?TicketType $ticketType): int
    {
        if ($ticketType === null) {
            return 0;
        }

        return (int) TicketType::query()->withHeldQuantity()->findOrFail($ticketType->id)->held_quantity;
    }

    /**
     * Fill the form with an existing ticket type.
     */
    public function setTicketType(TicketType $ticketType): void
    {
        $this->name = $ticketType->name;
        $this->price = $ticketType->price;
        $this->capacity = $ticketType->capacity;
        $this->sale_starts_at = $ticketType->sale_starts_at->format('Y-m-d\TH:i');
        $this->sale_ends_at = $ticketType->sale_ends_at->format('Y-m-d\TH:i');
    }
}
