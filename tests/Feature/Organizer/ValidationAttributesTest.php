<?php

use App\Models\Event;
use App\Models\User;
use Livewire\Livewire;

test('the edit screen names the venue in Japanese when it is empty', function () {
    $event = Event::factory()->create();

    $errors = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->set('form.venue', '')
        ->call('save')
        ->errors()->get('form.venue');

    expect($errors)->toContain('会場は必須項目です。');
});

test('the edit screen names the end and start in Japanese when the end is before the start', function () {
    $event = Event::factory()->create();

    $errors = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->set('form.ends_at', $event->starts_at->copy()->subHour()->format('Y-m-d\TH:i'))
        ->call('save')
        ->errors()->get('form.ends_at');

    expect($errors)->toContain('終了日時には、開始日時より後の日付を指定してください。');
});

test('the ticket type form names the ticket type name in Japanese when it is empty', function () {
    $event = Event::factory()->create();

    $errors = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->call('create')
        ->set('form.name', '')
        ->set('form.price', '1500')
        ->set('form.capacity', '30')
        ->call('save')
        ->errors()->get('form.name');

    expect($errors)->toContain('券種名は必須項目です。');
});

test('the ticket type form names the capacity in Japanese when it is zero', function () {
    $event = Event::factory()->create();

    $errors = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->call('create')
        ->set('form.name', '学生')
        ->set('form.price', '1500')
        ->set('form.capacity', '0')
        ->call('save')
        ->errors()->get('form.capacity');

    expect($errors)->toContain('定員には、1以上の数値を指定してください。');
});

test('the create screen names the title in Japanese when it is empty', function () {
    $errors = Livewire::actingAs(User::factory()->organizer()->create())
        ->test('pages::organizer.events.create')
        ->set('form.title', '')
        ->call('save')
        ->errors()->get('form.title');

    expect($errors)->toContain('タイトルは必須項目です。');
});
