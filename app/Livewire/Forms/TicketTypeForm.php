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
     *
     * @return array<string, array<int, string>>
     */
    public function rulesFor(Event $event): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'price' => ['required', 'integer', 'min:0', 'max:1000000'],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
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
     * Get the custom validation messages for a ticket type of the given event.
     *
     * @return array<string, string>
     */
    public function messagesFor(Event $event): array
    {
        return [
            'sale_ends_at.before_or_equal' => '販売終了はイベントの開始日時（'.$event->starts_at->isoFormat('M月D日(ddd) HH:mm').'）以前にしてください。',
        ];
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
