<?php

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::events.index')->name('home');
Route::permanentRedirect('events', '/');
Route::livewire('events/{event}', 'pages::events.show')->name('events.show');

Route::middleware('auth')->group(function () {
    Route::livewire('events/{event}/purchase', 'pages::events.purchase')->name('events.purchase');
    Route::livewire('orders/{order}/complete', 'pages::orders.complete')->name('orders.complete');
    Route::livewire('orders/{order}/cancel', 'pages::orders.cancel')->name('orders.cancel');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn (Request $request) => redirect(match ($request->user()->role) {
        UserRole::Admin => '/admin/events',
        UserRole::Organizer => route('organizer.events.index'),
        UserRole::Customer => '/orders',
    }))->name('dashboard');
});

Route::redirect('organizer', 'organizer/events');

Route::middleware(['auth', 'verified', 'role:organizer'])
    ->prefix('organizer/events')
    ->name('organizer.events.')
    ->group(function () {
        Route::livewire('/', 'pages::organizer.events.index')->name('index');
        Route::livewire('create', 'pages::organizer.events.create')->name('create');
        Route::livewire('{event}/edit', 'pages::organizer.events.edit')->name('edit');
    });

require __DIR__.'/settings.php';
