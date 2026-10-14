<?php

use App\Enums\UserRole;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::events.index')->name('home');
Route::permanentRedirect('events', '/');
Route::livewire('events/{event}', 'pages::events.show')->name('events.show');

Route::post('stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

Route::middleware('auth')->group(function () {
    Route::livewire('events/{event}/purchase', 'pages::events.purchase')->name('events.purchase');
    Route::livewire('orders', 'pages::orders.index')->name('orders.index');
    Route::livewire('orders/{order}', 'pages::orders.show')->name('orders.show');
    Route::livewire('orders/{order}/complete', 'pages::orders.complete')->name('orders.complete');
    Route::livewire('orders/{order}/cancel', 'pages::orders.cancel')->name('orders.cancel');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn (Request $request) => redirect(match ($request->user()->role) {
        UserRole::Admin => route('home'),
        UserRole::Organizer => route('organizer.events.index'),
        UserRole::Customer => route('orders.index'),
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

Route::middleware(['auth', 'verified', 'role:organizer,admin'])
    ->prefix('organizer/events')
    ->name('organizer.events.')
    ->group(function () {
        Route::livewire('{event}/orders', 'pages::organizer.events.orders')->name('orders');
    });

require __DIR__.'/settings.php';
