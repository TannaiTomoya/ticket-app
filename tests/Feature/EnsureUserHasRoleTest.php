<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'role:organizer'])
        ->get('/_test/organizer-only', fn () => 'ok');

    Route::middleware(['web', 'auth', 'role:organizer,admin'])
        ->get('/_test/organizer-or-admin', fn () => 'ok');
});

test('guests are redirected to the login page', function () {
    $response = $this->get('/_test/organizer-only');

    $response->assertRedirect(route('login'));
});

test('organizers can access organizer-only routes', function () {
    $this->actingAs(User::factory()->organizer()->create());

    $response = $this->get('/_test/organizer-only');

    $response->assertOk()->assertSee('ok');
});

test('users without the role are forbidden from organizer-only routes', function (Closure $makeUser) {
    $this->actingAs($makeUser());

    $response = $this->get('/_test/organizer-only');

    $response->assertForbidden();
})->with([
    'customer' => [fn () => User::factory()->create()],
    'admin' => [fn () => User::factory()->admin()->create()],
]);

test('any of the listed roles can access routes with multiple roles', function (Closure $makeUser) {
    $this->actingAs($makeUser());

    $response = $this->get('/_test/organizer-or-admin');

    $response->assertOk();
})->with([
    'organizer' => [fn () => User::factory()->organizer()->create()],
    'admin' => [fn () => User::factory()->admin()->create()],
]);

test('customers are forbidden from routes with multiple roles', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get('/_test/organizer-or-admin');

    $response->assertForbidden();
});
