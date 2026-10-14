<?php

use App\Enums\OrderStatus;
use App\Mail\OrderPaidMail;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
});

/**
 * Create a pending order with one item and a Checkout Session id.
 */
function mailOrder(int $quantity = 2, int $price = 3000): Order
{
    $event = Event::factory()->published()->create(['title' => '春の音楽会', 'venue' => '町民ホール']);
    $ticketType = TicketType::factory()->for($event)->create(['name' => '学生割引', 'price' => $price, 'capacity' => 10]);
    $order = Order::factory()->for($event)->create([
        'stripe_checkout_session_id' => 'cs_test_123',
        'total_amount' => $quantity * $price,
    ]);
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => $quantity]);

    return $order;
}

test('a signed completed notification sends one purchase mail to the buyer', function () {
    $order = mailOrder();

    postWebhook(webhookPayload($order))->assertOk();

    Mail::assertSent(OrderPaidMail::class, 1);
    Mail::assertSent(OrderPaidMail::class, fn (OrderPaidMail $mail) => $mail->hasTo($order->user->email));
});

test('the same notification twice sends the mail once', function () {
    $order = mailOrder();
    $payload = webhookPayload($order);

    postWebhook($payload)->assertOk();
    postWebhook($payload)->assertOk();

    Mail::assertSent(OrderPaidMail::class, 1);
});

test('no mail is sent when nothing was confirmed', function (string $case) {
    $order = mailOrder();

    match ($case) {
        'bad signature' => postWebhook(webhookPayload($order), 'whsec_wrong')->assertStatus(400),
        'unpaid session' => postWebhook(webhookPayload($order, ['payment_status' => 'unpaid']))->assertOk(),
        'already paid order' => (function () use ($order) {
            $order->forceFill(['status' => OrderStatus::Paid])->save();
            expect(app(OrderService::class)->markAsPaid($order))->toBeFalse();
        })(),
    };

    Mail::assertNothingSent();
})->with(['bad signature', 'unpaid session', 'already paid order']);

test('markAsPaid returns true then false and sends the mail once', function () {
    $order = mailOrder();
    $service = app(OrderService::class);

    expect($service->markAsPaid($order))->toBeTrue()
        ->and($service->markAsPaid($order))->toBeFalse();

    Mail::assertSent(OrderPaidMail::class, 1);
});

test('a free order confirmed on the purchase page sends the mail', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['price' => 0]);
    session()->put("purchase.{$event->id}", [$ticketType->id => 1]);
    $user = User::factory()->create();

    $this->mock(CheckoutService::class)->shouldNotReceive('createSession');

    Livewire::actingAs($user)
        ->test('pages::events.purchase', ['event' => $event])
        ->call('pay')
        ->assertHasNoErrors();

    Mail::assertSent(OrderPaidMail::class, 1);
    Mail::assertSent(OrderPaidMail::class, fn (OrderPaidMail $mail) => $mail->hasTo($user->email));
});

test('the mail carries the subject, venue, ticket type, total and order link', function () {
    $order = mailOrder(quantity: 2, price: 3000);

    postWebhook(webhookPayload($order))->assertOk();

    Mail::assertSent(OrderPaidMail::class, function (OrderPaidMail $mail) use ($order) {
        $mail->assertHasSubject('【まちチケ】チケットの購入が完了しました（春の音楽会）');
        $mail->assertSeeInHtml('町民ホール');
        $mail->assertSeeInHtml('学生割引');
        $mail->assertSeeInHtml('6,000円');
        $mail->assertSeeInHtml(route('orders.show', $order));

        return true;
    });
});

test('a mail failure does not undo the payment', function () {
    $order = mailOrder();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down'));

    expect(app(OrderService::class)->markAsPaid($order))->toBeTrue()
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->tickets()->count())->toBe(2);
});
