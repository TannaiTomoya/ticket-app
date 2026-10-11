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
    'admin' => [fn () => User::factory()->admin()->create(), '/admin/events'],
    'organizer' => [fn () => User::factory()->organizer()->create(), '/organizer/events'],
    'customer' => [fn () => User::factory()->create(), '/orders'],
]);
