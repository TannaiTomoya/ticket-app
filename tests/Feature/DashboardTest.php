<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users are redirected by role', function (Closure $makeUser, string $expectedPath) {
    $this->actingAs($makeUser());

    $response = $this->get(route('dashboard'));
    $response->assertRedirect($expectedPath);
})->with([
    'admin' => [fn () => User::factory()->admin()->create(), '/'],
    'organizer' => [fn () => User::factory()->organizer()->create(), '/organizer/events'],
    'customer' => [fn () => User::factory()->create(), '/orders'],
]);

test('admins land on the event list instead of a missing page', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->followingRedirects()
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('イベント一覧');
});
