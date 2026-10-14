<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Create an order of the event with one item of the ticket type.
 *
 * @param  array<string, mixed>  $orderState
 */
function orderWithItem(Event $event, TicketType $ticketType, int $quantity, ?int $unitPrice = null, string $status = 'paid', array $orderState = []): Order
{
    $factory = Order::factory()->for($event);
    $order = ($status === 'pending' ? $factory : $factory->{$status}())->create($orderState);

    OrderItem::factory()->for($order)->for($ticketType)->create(array_filter([
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
    ], fn ($value) => $value !== null));

    return $order;
}

// --- アクセス制御 ---

test('the owner and admins can open the sales page', function (string $role) {
    $event = Event::factory()->create();
    $user = $role === 'owner' ? $event->organizer : User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route('organizer.events.orders', $event))
        ->assertOk();
})->with(['owner', 'admin']);

test('other organizers and customers are forbidden from the sales page', function (string $role) {
    $event = Event::factory()->create();
    $user = $role === 'organizer' ? User::factory()->organizer()->create() : User::factory()->create();

    $this->actingAs($user)
        ->get(route('organizer.events.orders', $event))
        ->assertForbidden();
})->with(['organizer', 'customer']);

test('guests are redirected to the login page from the sales page', function () {
    $event = Event::factory()->create();

    $this->get(route('organizer.events.orders', $event))
        ->assertRedirect(route('login'));
});

test('changing the page is forbidden for a user who is not allowed to see the sales', function () {
    $event = Event::factory()->create();
    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event]);

    $this->actingAs(User::factory()->organizer()->create());

    $component->call('gotoPage', 2)->assertForbidden();
});

// --- 集計 ---

test('only paid orders are counted and listed', function () {
    $event = Event::factory()->create();
    $general = TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 3000]);
    $free = TicketType::factory()->for($event)->free()->create();

    $paidGeneral = orderWithItem($event, $general, 2);
    $paidFree = orderWithItem($event, $free, 1);
    $others = collect(['pending', 'cancelled', 'refunded'])
        ->map(fn (string $status) => orderWithItem($event, $general, 5, status: $status));

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event]);

    $component
        ->assertSeeHtml('data-test="paid-order-'.$paidGeneral->id.'"')
        ->assertSeeHtml('data-test="paid-order-'.$paidFree->id.'"');

    foreach ($others as $order) {
        $component->assertDontSeeHtml('data-test="paid-order-'.$order->id.'"');
    }

    $rows = $component->instance()->ticketTypes->keyBy('id');
    expect((int) $rows[$general->id]->sold_quantity)->toBe(2)
        ->and((int) $rows[$general->id]->revenue)->toBe(6000)
        ->and((int) $rows[$free->id]->sold_quantity)->toBe(1)
        ->and((int) $rows[$free->id]->revenue)->toBe(0);

    $html = $component->html();
    expect($html)->toContain('data-test="sales-row-'.$general->id.'"');
    $component->assertSee('2 枚')->assertSee('6,000円');

    expect(salesTotalText($html))->toContain('3 枚')->toContain('6,000円');
});

/**
 * Get the text of the total row from rendered HTML.
 */
function salesTotalText(string $html): string
{
    preg_match('/data-test="sales-total"(.*?)<\/(?:tr|flux:table-row)>/s', $html, $matches);

    return trim(preg_replace('/\s+/', ' ', strip_tags($matches[1] ?? '')));
}

test('a ticket type with no paid sales shows zero', function () {
    $event = Event::factory()->create();
    $ticketType = TicketType::factory()->for($event)->create();
    orderWithItem($event, $ticketType, 2, status: 'pending');

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event]);

    $row = $component->instance()->ticketTypes->sole();
    expect((int) $row->sold_quantity)->toBe(0)
        ->and((int) $row->revenue)->toBe(0);
    expect(salesTotalText($component->html()))->toContain('0 枚')->toContain('0円');
});

test('revenue uses the unit price at purchase, not the current price', function () {
    $event = Event::factory()->create();
    $ticketType = TicketType::factory()->for($event)->create(['price' => 3000]);
    orderWithItem($event, $ticketType, 2, unitPrice: 3000);

    $ticketType->update(['price' => 5000]);

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event]);

    expect((int) $component->instance()->ticketTypes->sole()->revenue)->toBe(6000);
    expect(salesTotalText($component->html()))->toContain('6,000円');
});

test('other events orders are not counted', function () {
    $event = Event::factory()->create();
    $ticketType = TicketType::factory()->for($event)->create();
    $otherEvent = Event::factory()->create();
    $otherType = TicketType::factory()->for($otherEvent)->create();
    orderWithItem($event, $ticketType, 1);
    $otherOrder = orderWithItem($otherEvent, $otherType, 4);

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event]);

    expect((int) $component->instance()->ticketTypes->sole()->sold_quantity)->toBe(1);
    $component->assertDontSeeHtml('data-test="paid-order-'.$otherOrder->id.'"');
});

test('the order list shows the buyer name but not the email address', function () {
    $event = Event::factory()->create();
    $ticketType = TicketType::factory()->for($event)->create();
    $buyer = User::factory()->create(['name' => '山田太郎', 'email' => 'yamada-secret@example.com']);
    orderWithItem($event, $ticketType, 1, orderState: ['user_id' => $buyer->id]);

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event])
        ->assertSee('山田太郎')
        ->assertDontSee('yamada-secret@example.com');
});

test('the order list is paginated by 20', function () {
    $event = Event::factory()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 100]);
    $orders = collect(range(1, 21))->map(fn (int $i) => orderWithItem($event, $ticketType, 1, orderState: ['paid_at' => now()->subMinutes($i)]));

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event])
        ->assertSeeHtml('data-test="paid-order-'.$orders->first()->id.'"')
        ->assertDontSeeHtml('data-test="paid-order-'.$orders->last()->id.'"')
        ->call('gotoPage', 2)
        ->assertSeeHtml('data-test="paid-order-'.$orders->last()->id.'"');
});

test('the number of queries does not grow with ticket types and orders', function () {
    $event = Event::factory()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 100]);
    orderWithItem($event, $ticketType, 1);

    $countQueries = function () use ($event): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($event->organizer)
            ->test('pages::organizer.events.orders', ['event' => $event]);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $countQueries();
    $before = $countQueries();

    foreach (range(1, 4) as $_) {
        $type = TicketType::factory()->for($event)->create(['capacity' => 100]);
        orderWithItem($event, $type, 2);
        orderWithItem($event, $type, 1);
    }

    expect($countQueries())->toBe($before);
});

// --- 定員 ---

test('the capacity cannot go below the paid and pending seats', function () {
    $this->freezeSecond();
    $event = Event::factory()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 20]);
    orderWithItem($event, $ticketType, 2);
    orderWithItem($event, $ticketType, 1, status: 'pending', orderState: ['created_at' => now()->subMinutes(Order::HOLD_MINUTES)]);

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->call('edit', $ticketType->id)
        ->set('form.capacity', '2')
        ->call('save')
        ->assertHasErrors(['form.capacity' => 'min'])
        ->assertSee('定員は、支払い済みと支払い待ちの枚数（3枚）より少なくできません。');

    expect($ticketType->fresh()->capacity)->toBe(20);

    $component->set('form.capacity', '3')
        ->call('save')
        ->assertHasNoErrors();

    expect($ticketType->fresh()->capacity)->toBe(3);
});

test('pending orders older than the hold time do not count toward the capacity limit', function () {
    $this->freezeSecond();
    $event = Event::factory()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 20]);
    orderWithItem($event, $ticketType, 2);
    orderWithItem($event, $ticketType, 1, status: 'pending', orderState: ['created_at' => now()->subMinutes(Order::HOLD_MINUTES + 1)]);

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->call('edit', $ticketType->id)
        ->set('form.capacity', '2')
        ->call('save')
        ->assertHasNoErrors();

    expect($ticketType->fresh()->capacity)->toBe(2);
});

test('a new ticket type still needs a capacity of at least 1', function () {
    $event = Event::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.ticket-types', ['event' => $event])
        ->call('create')
        ->set([
            'form.name' => '学生',
            'form.price' => '1500',
            'form.capacity' => '0',
            'form.sale_starts_at' => now()->format('Y-m-d\TH:i'),
            'form.sale_ends_at' => $event->starts_at->format('Y-m-d\TH:i'),
        ])
        ->call('save')
        ->assertHasErrors(['form.capacity' => 'min']);

    expect(TicketType::count())->toBe(0);
});

// --- 開始日時 ---

test('the event start cannot move before the latest ticket sale end', function () {
    $event = Event::factory()->create();
    $saleEnd = now()->addDays(10)->setTime(9, 0)->startOfMinute();
    TicketType::factory()->for($event)->create(['sale_ends_at' => $saleEnd->copy()->subDay()]);
    TicketType::factory()->for($event)->create(['sale_ends_at' => $saleEnd]);
    $originalStart = $event->starts_at;

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->set('form.starts_at', $saleEnd->copy()->subMinute()->format('Y-m-d\TH:i'))
        ->set('form.ends_at', $saleEnd->copy()->addHours(3)->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertHasErrors(['form.starts_at' => 'after_or_equal'])
        ->assertSee('開始日時は、券種の販売終了（'.$saleEnd->isoFormat('M月D日(ddd) HH:mm').'）以降にしてください。');

    expect($event->fresh()->starts_at->equalTo($originalStart))->toBeTrue();

    $component->set('form.starts_at', $saleEnd->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertHasNoErrors();

    expect($event->fresh()->starts_at->equalTo($saleEnd))->toBeTrue();
});

test('an event without ticket types has no limit on the start', function () {
    $event = Event::factory()->create();
    $start = now()->addDay()->setTime(9, 0)->startOfMinute();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->set('form.starts_at', $start->format('Y-m-d\TH:i'))
        ->set('form.ends_at', $start->copy()->addHours(2)->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertHasNoErrors();

    expect($event->fresh()->starts_at->equalTo($start))->toBeTrue();
});

// --- 編集画面のリンク ---

test('the edit page links to the sales page', function () {
    $event = Event::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->assertSeeHtml('data-test="sales-link"')
        ->assertSeeHtml('href="'.route('organizer.events.orders', $event).'"');
});
