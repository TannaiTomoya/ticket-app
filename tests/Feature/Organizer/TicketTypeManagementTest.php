<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Build valid form input for a ticket type of the given event.
 *
 * @return array<string, string>
 */
function validTicketTypeInput(Event $event): array
{
    return [
        'form.name' => '学生',
        'form.price' => '1500',
        'form.capacity' => '30',
        'form.sale_starts_at' => now()->addDay()->setTime(9, 0)->format('Y-m-d\TH:i'),
        'form.sale_ends_at' => $event->starts_at->format('Y-m-d\TH:i'),
    ];
}

test('owners can add a ticket type to their event', function () {
    $event = Event::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->call('create')
        ->set(validTicketTypeInput($event))
        ->call('save')
        ->assertHasNoErrors();

    $ticketType = TicketType::sole();
    expect($ticketType->event_id)->toBe($event->id)
        ->and($ticketType->name)->toBe('学生')
        ->and($ticketType->price)->toBe(1500)
        ->and($ticketType->capacity)->toBe(30)
        ->and($ticketType->sale_starts_at->format('Y-m-d H:i'))->toBe(now()->addDay()->format('Y-m-d').' 09:00')
        ->and($ticketType->sale_ends_at->equalTo($event->starts_at))->toBeTrue();
});

test('owners can add a free ticket type', function () {
    $event = Event::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->set(validTicketTypeInput($event))
        ->set('form.price', '0')
        ->call('save')
        ->assertHasNoErrors();

    expect(TicketType::sole()->price)->toBe(0);
});

test('owners can edit a ticket type of their event', function () {
    $ticketType = TicketType::factory()->create(['name' => '一般', 'price' => 3000, 'capacity' => 20]);
    $event = $ticketType->event;

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->call('edit', $ticketType->id)
        ->assertSet('editingId', $ticketType->id)
        ->assertSet('form.name', '一般')
        ->assertSet('form.price', '3000')
        ->assertSet('form.capacity', '20')
        ->assertSet('form.sale_starts_at', $ticketType->sale_starts_at->format('Y-m-d\TH:i'))
        ->assertSet('form.sale_ends_at', $ticketType->sale_ends_at->format('Y-m-d\TH:i'))
        ->set('form.name', '一般（前売）')
        ->set('form.price', '2500')
        ->set('form.capacity', '50')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    $ticketType->refresh();
    expect(TicketType::count())->toBe(1)
        ->and($ticketType->name)->toBe('一般（前売）')
        ->and($ticketType->price)->toBe(2500)
        ->and($ticketType->capacity)->toBe(50);
});

test('owners can delete a ticket type without order items', function () {
    $ticketType = TicketType::factory()->create();

    Livewire::actingAs($ticketType->event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $ticketType->event])
        ->call('delete', $ticketType->id)
        ->assertOk();

    $this->assertModelMissing($ticketType);
});

test('saving a ticket type requires all fields', function () {
    $event = Event::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->call('save')
        ->assertHasErrors([
            'form.name' => 'required',
            'form.price' => 'required',
            'form.capacity' => 'required',
            'form.sale_starts_at' => 'required',
            'form.sale_ends_at' => 'required',
        ]);

    expect(TicketType::count())->toBe(0);
});

test('saving a ticket type rejects invalid values', function (string $field, Closure $makeValue, string $rule) {
    $event = Event::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->set(validTicketTypeInput($event))
        ->set("form.{$field}", $makeValue($event))
        ->call('save')
        ->assertHasErrors(["form.{$field}" => $rule]);

    expect(TicketType::count())->toBe(0);
})->with([
    'name over 50 characters' => ['name', fn () => str_repeat('あ', 51), 'max'],
    'negative price' => ['price', fn () => '-1', 'min'],
    'decimal price' => ['price', fn () => '1500.5', 'integer'],
    'non-numeric price' => ['price', fn () => 'abc', 'integer'],
    'zero capacity' => ['capacity', fn () => '0', 'min'],
    'sale end equal to sale start' => ['sale_ends_at', fn () => now()->addDay()->setTime(9, 0)->format('Y-m-d\TH:i'), 'after'],
    'sale end before sale start' => ['sale_ends_at', fn () => now()->addDay()->setTime(8, 0)->format('Y-m-d\TH:i'), 'after'],
    'sale end after the event starts' => ['sale_ends_at', fn (Event $event) => $event->starts_at->copy()->addMinute()->format('Y-m-d\TH:i'), 'before_or_equal'],
]);

test('other organizers cannot mount the ticket type component', function () {
    $event = Event::factory()->create();

    Livewire::actingAs(User::factory()->organizer()->create())
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->assertForbidden();
});

test('other organizers cannot add a ticket type through the component', function () {
    $event = Event::factory()->create();

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->set(validTicketTypeInput($event));

    $this->actingAs(User::factory()->organizer()->create());

    $component->call('save')->assertForbidden();

    expect(TicketType::count())->toBe(0);
});

test('other organizers cannot change a ticket type through the component', function (Closure $action) {
    $ticketType = TicketType::factory()->create();
    $original = $ticketType->fresh()->getAttributes();

    $component = Livewire::actingAs($ticketType->event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $ticketType->event])
        ->call('edit', $ticketType->id)
        ->set('form.name', '書き換えた券種');

    $this->actingAs(User::factory()->organizer()->create());

    $action($component, $ticketType)->assertForbidden();

    $this->assertModelExists($ticketType);
    expect($ticketType->fresh()->getAttributes())->toBe($original);
})->with([
    'edit' => [fn (Testable $component, TicketType $ticketType) => $component->call('edit', $ticketType->id)],
    'save while editing' => [fn (Testable $component, TicketType $ticketType) => $component->call('save')],
    'delete' => [fn (Testable $component, TicketType $ticketType) => $component->call('delete', $ticketType->id)],
]);

test('owners cannot touch ticket types of other events', function (Closure $action) {
    $event = Event::factory()->create();
    $otherTicketType = TicketType::factory()->create(['name' => '他のイベントの券種']);
    $original = $otherTicketType->fresh()->getAttributes();

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event]);

    $action($component, $otherTicketType)->assertNotFound();

    $this->assertModelExists($otherTicketType);
    expect($otherTicketType->fresh()->getAttributes())->toBe($original);
})->with([
    'edit' => [fn (Testable $component, TicketType $ticketType) => $component->call('edit', $ticketType->id)],
    'delete' => [fn (Testable $component, TicketType $ticketType) => $component->call('delete', $ticketType->id)],
    'save with a tampered editingId' => [fn (Testable $component, TicketType $ticketType) => $component
        ->set(validTicketTypeInput($component->get('event')))
        ->set('form.name', '書き換えた券種')
        ->set('editingId', $ticketType->id)
        ->call('save')],
]);

test('owners cannot delete the last ticket type of a published event', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->call('delete', $ticketType->id);

    $this->assertModelExists($ticketType);
});

test('owners can delete a ticket type of a published event while others remain', function () {
    $event = Event::factory()->published()->create();
    [$first, $second] = TicketType::factory()->for($event)->count(2)->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->call('delete', $first->id);

    $this->assertModelMissing($first);
    $this->assertModelExists($second);
});

test('owners cannot delete a ticket type that has order items', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create();
    OrderItem::factory()
        ->for(Order::factory()->paid()->for($event))
        ->for($ticketType)
        ->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->assertDontSeeHtml('wire:click="delete('.$ticketType->id.')"')
        ->call('delete', $ticketType->id)
        ->assertForbidden();

    $this->assertModelExists($ticketType);
});

test('the list shows ticket types with formatted prices and delete buttons', function () {
    $event = Event::factory()->create();
    $ticketType = TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 3000]);

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->assertSee('一般')
        ->assertSee('3,000円')
        ->assertSeeHtml('wire:click="delete('.$ticketType->id.')"');
});
