<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('organizer.events.index'));

    $response->assertRedirect(route('login'));
});

test('customers are forbidden from organizer event pages', function (string $routeName) {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route($routeName));

    $response->assertForbidden();
})->with([
    'index' => 'organizer.events.index',
    'create' => 'organizer.events.create',
]);

test('the index lists only the organizer\'s own events', function () {
    $organizer = User::factory()->organizer()->create();
    Event::factory()->for($organizer, 'organizer')->create(['title' => '自分のイベント']);
    Event::factory()->create(['title' => '他人のイベント']);

    $this->actingAs($organizer);

    $response = $this->get(route('organizer.events.index'));

    $response->assertOk()
        ->assertSee('自分のイベント')
        ->assertDontSee('他人のイベント');
});

test('organizers can create an event as a draft', function () {
    $organizer = User::factory()->organizer()->create();

    $component = Livewire::actingAs($organizer)
        ->test('pages::organizer.events.create')
        ->set('form.title', '秋の朗読会')
        ->set('form.description', '地域の図書館で開く朗読会です。')
        ->set('form.venue', '中央図書館')
        ->set('form.starts_at', '2026-11-01T10:00')
        ->set('form.ends_at', '2026-11-01T12:00')
        ->call('save');

    $component->assertHasNoErrors();

    $event = Event::sole();
    expect($event->organizer_id)->toBe($organizer->id)
        ->and($event->status)->toBe(EventStatus::Draft)
        ->and($event->title)->toBe('秋の朗読会')
        ->and($event->starts_at->format('Y-m-d H:i'))->toBe('2026-11-01 10:00')
        ->and($event->ends_at->format('Y-m-d H:i'))->toBe('2026-11-01 12:00');

    $component->assertRedirect(route('organizer.events.edit', $event));
});

test('creating an event requires all fields', function () {
    Livewire::actingAs(User::factory()->organizer()->create())
        ->test('pages::organizer.events.create')
        ->call('save')
        ->assertHasErrors([
            'form.title' => 'required',
            'form.description' => 'required',
            'form.venue' => 'required',
            'form.starts_at' => 'required',
            'form.ends_at' => 'required',
        ]);

    expect(Event::count())->toBe(0);
});

test('creating an event rejects invalid values', function (string $field, string $value, string $rule) {
    Livewire::actingAs(User::factory()->organizer()->create())
        ->test('pages::organizer.events.create')
        ->set('form.title', '秋の朗読会')
        ->set('form.description', '地域の図書館で開く朗読会です。')
        ->set('form.venue', '中央図書館')
        ->set('form.starts_at', '2026-11-01T10:00')
        ->set('form.ends_at', '2026-11-01T12:00')
        ->set("form.{$field}", $value)
        ->call('save')
        ->assertHasErrors(["form.{$field}" => $rule]);

    expect(Event::count())->toBe(0);
})->with([
    'title over 100 characters' => ['title', str_repeat('あ', 101), 'max'],
    'venue over 100 characters' => ['venue', str_repeat('あ', 101), 'max'],
    'ends_at equal to starts_at' => ['ends_at', '2026-11-01T10:00', 'after'],
    'ends_at before starts_at' => ['ends_at', '2026-11-01T09:00', 'after'],
]);

test('owners can update their event', function () {
    $event = Event::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->set('form.title', '更新後のタイトル')
        ->set('form.venue', '市民ホール')
        ->set('form.ends_at', $event->starts_at->copy()->addHours(3)->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertHasNoErrors();

    $event->refresh();
    expect($event->title)->toBe('更新後のタイトル')
        ->and($event->venue)->toBe('市民ホール')
        ->and($event->ends_at->format('H:i'))->toBe('13:00');
});

test('other organizers are forbidden from the edit page', function () {
    $event = Event::factory()->create();

    $this->actingAs(User::factory()->organizer()->create());

    $response = $this->get(route('organizer.events.edit', $event));

    $response->assertForbidden();
});

test('other organizers cannot change the event through component actions', function (string $method) {
    $event = Event::factory()->create(['title' => '元のタイトル']);
    $original = $event->fresh()->getAttributes();

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->set('form.title', '書き換えたタイトル');

    $this->actingAs(User::factory()->organizer()->create());

    $component->call($method)->assertForbidden();

    $this->assertModelExists($event);
    expect($event->fresh()->getAttributes())->toBe($original);
})->with(['save', 'togglePublish', 'deleteEvent']);

test('owners can toggle an event between draft and published', function () {
    $event = Event::factory()->create();

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event]);

    $component->call('togglePublish');
    expect($event->fresh()->status)->toBe(EventStatus::Published);

    $component->call('togglePublish');
    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});

test('owners cannot publish an event unpublished by an admin', function () {
    $event = Event::factory()->unpublished()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->call('togglePublish')
        ->assertForbidden();

    expect($event->fresh()->status)->toBe(EventStatus::Unpublished);
});

test('owners can delete an event without orders', function () {
    $event = Event::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->call('deleteEvent')
        ->assertRedirect(route('organizer.events.index'));

    $this->assertModelMissing($event);
});

test('owners cannot delete an event with paid orders', function () {
    $event = Event::factory()->published()->create();
    Order::factory()->paid()->for($event)->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->call('deleteEvent')
        ->assertForbidden();

    $this->assertModelExists($event);
});
