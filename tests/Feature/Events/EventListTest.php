<?php

use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;

test('only published upcoming events are listed', function () {
    $this->freezeSecond();
    Event::factory()->published()->create(['title' => '公開中のイベント']);
    Event::factory()->create(['title' => '下書きのイベント']);
    Event::factory()->unpublished()->create(['title' => '非公開のイベント']);
    Event::factory()->published()->past()->create(['title' => '開催済みのイベント']);

    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('公開中のイベント')
        ->assertDontSee('下書きのイベント')
        ->assertDontSee('非公開のイベント')
        ->assertDontSee('開催済みのイベント');
});

test('events are listed nearest first', function () {
    $this->freezeSecond();
    Event::factory()->published()->create(['title' => '3週間後', 'starts_at' => now()->addDays(21), 'ends_at' => now()->addDays(21)->addHours(2)]);
    Event::factory()->published()->create(['title' => '明日', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2)]);
    Event::factory()->published()->create(['title' => '1週間後', 'starts_at' => now()->addDays(7), 'ends_at' => now()->addDays(7)->addHours(2)]);

    $response = $this->get(route('home'));

    $response->assertOk()->assertSeeInOrder(['明日', '1週間後', '3週間後']);
});

test('each event shows its lowest price', function () {
    $event = Event::factory()->published()->create();
    TicketType::factory()->for($event)->create(['price' => 3000]);
    TicketType::factory()->for($event)->create(['price' => 1500]);

    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('1,500円〜')
        ->assertDontSee('3,000円〜');
});

test('an event with a free ticket type shows free as its lowest price', function () {
    $event = Event::factory()->published()->create();
    TicketType::factory()->for($event)->create(['price' => 3000]);
    TicketType::factory()->for($event)->free()->create();

    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('無料〜')
        ->assertDontSee('3,000円〜');
});

test('a message is shown when there are no events', function () {
    Event::factory()->create();

    $response = $this->get(route('home'));

    $response->assertOk()->assertSee('いま募集中のイベントはありません。');
});

test('events are paginated twelve per page', function () {
    $this->freezeSecond();
    foreach (range(1, 13) as $day) {
        Event::factory()->published()->create([
            'title' => sprintf('イベント%02d', $day),
            'starts_at' => now()->addDays($day),
            'ends_at' => now()->addDays($day)->addHours(2),
        ]);
    }

    $firstPage = $this->get(route('home'));
    $secondPage = $this->get(route('home', ['page' => 2]));

    $firstPage->assertOk()
        ->assertSee('イベント12')
        ->assertDontSee('イベント13');
    $secondPage->assertOk()
        ->assertSee('イベント13')
        ->assertDontSee('イベント12');
});

test('guests can view the list and see login and register links', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('ログイン')
        ->assertSee('会員登録')
        ->assertDontSee('data-test="public-user-menu"', false);
});

test('signed in users see the user menu, and only organizers see the organizer menu', function (Closure $makeUser, bool $seesOrganizerMenu) {
    $this->actingAs($makeUser());

    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('data-test="public-user-menu"', false)
        ->assertDontSee('会員登録');
    $seesOrganizerMenu
        ? $response->assertSee('主催者メニュー')
        : $response->assertDontSee('主催者メニュー');
})->with([
    'customer' => [fn () => User::factory()->create(), false],
    'organizer' => [fn () => User::factory()->organizer()->create(), true],
]);
