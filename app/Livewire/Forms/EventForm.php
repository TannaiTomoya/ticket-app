<?php

namespace App\Livewire\Forms;

use App\Models\Event;
use Illuminate\Support\Carbon;
use Livewire\Form;

class EventForm extends Form
{
    public string $title = '';

    public string $description = '';

    public string $venue = '';

    public string $starts_at = '';

    public string $ends_at = '';

    /**
     * Get the validation rules used to create and update events.
     *
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string'],
            'venue' => ['required', 'string', 'max:100'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
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
            'title' => 'タイトル',
            'description' => '説明',
            'venue' => '会場',
            'starts_at' => '開始日時',
            'ends_at' => '終了日時',
        ];
    }

    /**
     * Get the validation rules for editing the event.
     * The start cannot move before the latest sale end of its ticket types.
     *
     * @return array<string, array<int, string>>
     */
    public function rulesFor(Event $event): array
    {
        $rules = $this->rules();
        $latestSaleEnd = $this->latestSaleEnd($event);

        if ($latestSaleEnd !== null) {
            $rules['starts_at'][] = 'after_or_equal:'.$latestSaleEnd->format('Y-m-d H:i:s');
        }

        return $rules;
    }

    /**
     * Get the custom validation messages for editing the event.
     *
     * @return array<string, string>
     */
    public function messagesFor(Event $event): array
    {
        $latestSaleEnd = $this->latestSaleEnd($event);

        return $latestSaleEnd === null ? [] : [
            'starts_at.after_or_equal' => '開始日時は、券種の販売終了（'.$latestSaleEnd->isoFormat('M月D日(ddd) HH:mm').'）以降にしてください。',
        ];
    }

    /**
     * Get the latest sale end among the event's ticket types, or null when it has none.
     */
    private function latestSaleEnd(Event $event): ?Carbon
    {
        $latestSaleEnd = $event->ticketTypes()->max('sale_ends_at');

        return $latestSaleEnd === null ? null : Carbon::parse($latestSaleEnd);
    }

    /**
     * Fill the form with an existing event.
     */
    public function setEvent(Event $event): void
    {
        $this->title = $event->title;
        $this->description = $event->description;
        $this->venue = $event->venue;
        $this->starts_at = $event->starts_at->format('Y-m-d\TH:i');
        $this->ends_at = $event->ends_at->format('Y-m-d\TH:i');
    }
}
