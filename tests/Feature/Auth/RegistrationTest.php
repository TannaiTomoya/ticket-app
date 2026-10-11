<?php

use App\Enums\UserRole;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'customer',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    expect(User::firstWhere('email', 'test@example.com')->role)->toBe(UserRole::Customer);
});

test('new users can register as an organizer', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'organizer',
    ]);

    $response->assertSessionHasNoErrors();

    $this->assertAuthenticated();
    expect(User::firstWhere('email', 'test@example.com')->role)->toBe(UserRole::Organizer);
});

test('new users cannot register with an invalid role', function (?string $role) {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => $role,
    ]);

    $response->assertSessionHasErrors('role');

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
})->with([
    'admin' => 'admin',
    'missing' => null,
]);
