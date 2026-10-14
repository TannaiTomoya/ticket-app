<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use Livewire\Livewire;

test('only ticket types on sale get a quantity select', function () {
    $event = Event::factory()->published()->create();
    $onSale = TicketType::factory()->for($event)->create();
    $saleEnded = TicketType::factory()->for($event)->saleEnded()->create();

    $response = $this->get(route('events.show', $event));

    $response->assertOk()
        ->assertSee('data-test="quantity-select-'.$onSale->id.'"', false)
        ->assertDontSee('data-test="quantity-select-'.$saleEnded->id.'"', false)
        ->assertSee('購入手続きへ進む');
});

test('the quantity select is capped by the remaining seats', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 5]);
    OrderItem::factory()->for(Order::factory()->paid()->for($event))->for($ticketType)->create(['quantity' => 2]);

    $response = $this->get(route('events.show', $event));

    $response->assertOk()
        ->assertSee('>3</option>', false)
        ->assertDontSee('>4</option>', false);
});

test('the quantity select is capped at ten per ticket type', function () {
    $event = Event::factory()->published()->create();
    TicketType::factory()->for($event)->create(['capacity' => 50]);

    $response = $this->get(route('events.show', $event));

    $response->assertOk()
        ->assertSee('>10</option>', false)
        ->assertDontSee('>11</option>', false);
});

test('proceeding keeps the selection in the session and opens the confirmation', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.show', ['event' => $event])
        ->set("quantities.{$ticketType->id}", '2')
        ->call('proceed')
        ->assertHasNoErrors()
        ->assertRedirect(route('events.purchase', $event));

    expect(session("purchase.{$event->id}"))->toBe([$ticketType->id => 2]);
});

test('guests are sent to the login page and come back to the confirmation', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create();

    Livewire::test('pages::events.show', ['event' => $event])
        ->set("quantities.{$ticketType->id}", '1')
        ->call('proceed')
        ->assertRedirect(route('login'));

    expect(session('url.intended'))->toBe(route('events.purchase', $event))
        ->and(session("purchase.{$event->id}"))->toBe([$ticketType->id => 1]);
});

test('proceeding without any quantity shows an error', function () {
    $event = Event::factory()->published()->create();
    TicketType::factory()->for($event)->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.show', ['event' => $event])
        ->call('proceed')
        ->assertHasErrors('purchase')
        ->assertNoRedirect();
});

test('the confirmation shows the lines and total with database prices', function () {
    $event = Event::factory()->published()->create();
    $general = TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 3000]);
    $student = TicketType::factory()->for($event)->create(['name' => '学生', 'price' => 1500]);
    session()->put("purchase.{$event->id}", [$general->id => 2, $student->id => 1]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $event])
        ->assertSee('一般')
        ->assertSee('6,000円')
        ->assertSee('学生')
        ->assertSee('合計 7,500円');
});

test('opening the confirmation without a selection goes back to the event', function () {
    $event = Event::factory()->published()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $event])
        ->assertRedirect(route('events.show', $event));
});

test('paying creates a pending order and sends the buyer to the Stripe payment page', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['price' => 3000]);
    $user = User::factory()->create();
    session()->put("purchase.{$event->id}", [$ticketType->id => 2]);

    $this->mock(CheckoutService::class)
        ->shouldReceive('createSession')
        ->once()
        ->withArgs(fn (Order $order): bool => $order->total_amount === 6000)
        ->andReturn('https://checkout.stripe.com/c/pay/cs_test_123');

    Livewire::actingAs($user)
        ->test('pages::events.purchase', ['event' => $event])
        ->call('pay')
        ->assertHasNoErrors()
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

    $order = Order::sole();

    expect($order->user_id)->toBe($user->id)
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->total_amount)->toBe(6000)
        ->and(session()->has("purchase.{$event->id}"))->toBeFalse();
});

test('a purchase error is shown and no order is created', function (Closure $breakPurchase) {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 2]);
    session()->put("purchase.{$event->id}", [$ticketType->id => 2]);

    $component = Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $event]);

    $breakPurchase($component, $ticketType);

    $component->call('pay')
        ->assertHasErrors('purchase')
        ->assertNoRedirect();

    expect(Order::where('status', OrderStatus::Pending)->count())->toBe(0);
})->with([
    'sold out meanwhile' => [function ($component, TicketType $ticketType) {
        OrderItem::factory()->for(Order::factory()->paid()->for($ticketType->event))->for($ticketType)->create(['quantity' => 2]);
    }],
    'quantity tampered above the limit' => [function ($component, TicketType $ticketType) {
        $ticketType->update(['capacity' => 50]);
        $component->set("quantities.{$ticketType->id}", 11);
    }],
]);

test('the complete page is visible to the buyer, the organizer, and admins', function (Closure $makeUser) {
    $order = Order::factory()->create();

    Livewire::actingAs($makeUser($order))
        ->test('pages::orders.complete', ['order' => $order])
        ->assertOk()
        ->assertSee('注文番号');
})->with([
    'buyer' => [fn (Order $order) => $order->user],
    'organizer' => [fn (Order $order) => $order->event->organizer],
    'admin' => [fn () => User::factory()->admin()->create()],
]);

test('other users cannot open the complete page', function () {
    $order = Order::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('orders.complete', $order))
        ->assertForbidden();
});

test('guests are sent to the login page from the purchase pages', function (Closure $makeUrl) {
    $this->get($makeUrl())->assertRedirect(route('login'));
})->with([
    'confirmation' => [fn () => route('events.purchase', Event::factory()->published()->create())],
    'complete' => [fn () => route('orders.complete', Order::factory()->create())],
]);
