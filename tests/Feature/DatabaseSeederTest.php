<?php

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Run the database seeder directly (db:seed asks for confirmation in production).
 */
function runDatabaseSeeder(): void
{
    app(DatabaseSeeder::class)->run();
}

/**
 * Pretend the app is running in production.
 */
function runAsProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
}

test('the demo users are created with their roles, verified and with the password "password"', function () {
    $this->seed();

    expect(User::where('role', UserRole::Admin)->pluck('email')->all())->toBe(['admin@example.com'])
        ->and(User::where('role', UserRole::Organizer)->orderBy('email')->pluck('email')->all())
        ->toBe(['organizer1@example.com', 'organizer2@example.com'])
        ->and(User::where('role', UserRole::Customer)->orderBy('email')->pluck('email')->all())
        ->toBe(['customer1@example.com', 'customer2@example.com', 'customer3@example.com']);

    User::all()->each(function (User $user) {
        expect(Hash::check('password', $user->password))->toBeTrue()
            ->and($user->email_verified_at)->not->toBeNull();
    });
});

test('six events are created with the expected statuses', function () {
    $this->seed();

    $events = Event::with('ticketTypes')->get();

    expect($events)->toHaveCount(6)
        ->and($events->where('status', EventStatus::Published))->toHaveCount(4)
        ->and($events->where('status', EventStatus::Draft))->toHaveCount(1)
        ->and($events->where('status', EventStatus::Unpublished))->toHaveCount(1)
        ->and($events->where('status', EventStatus::Published)->filter->isFinished())->toHaveCount(1);
});

test('every event has one to three valid ticket types', function () {
    $this->seed();

    Event::with('ticketTypes')->get()->each(function (Event $event) {
        expect($event->ticketTypes->count())->toBeBetween(1, 3);

        $event->ticketTypes->each(function ($ticketType) use ($event) {
            expect($ticketType->price)->toBeIn([0, 1500, 3000])
                ->and($ticketType->capacity)->toBeBetween(5, 30)
                ->and($ticketType->sale_ends_at->lte($event->starts_at))->toBeTrue();
        });
    });
});

test('the ticket types of upcoming published events are on sale', function () {
    $this->seed();

    $upcoming = Event::with('ticketTypes')
        ->where('status', EventStatus::Published)
        ->where('starts_at', '>', now())
        ->get();

    expect($upcoming)->toHaveCount(3);

    $upcoming->flatMap->ticketTypes->each(function ($ticketType) {
        expect($ticketType->isOnSale())->toBeTrue();
    });
});

test('paid orders have matching items, tickets and totals', function () {
    $this->seed();

    $paidOrders = Order::with('items.ticketType')->where('status', OrderStatus::Paid)->get();

    expect($paidOrders)->toHaveCount(4);

    $paidOrders->each(function (Order $order) {
        $ticketCount = Ticket::whereIn('order_item_id', $order->items->modelKeys())->count();

        expect($ticketCount)->toBe((int) $order->items->sum('quantity'))
            ->and($order->total_amount)->toBe((int) $order->items->sum(fn ($item) => $item->quantity * $item->unit_price));

        $order->items->each(fn ($item) => expect($item->unit_price)->toBe($item->ticketType->price));
    });
});

test('pending orders have no tickets and no longer hold seats', function () {
    $this->seed();

    $pendingOrders = Order::with('items')->where('status', OrderStatus::Pending)->get();

    expect($pendingOrders)->toHaveCount(2)
        ->and(Order::holdingSeats()->where('status', OrderStatus::Pending)->count())->toBe(0);

    $pendingOrders->each(function (Order $order) {
        expect($order->created_at->lt(now()->subMinutes(Order::HOLD_MINUTES)))->toBeTrue()
            ->and(Ticket::whereIn('order_item_id', $order->items->modelKeys())->count())->toBe(0);

        $order->items->each(function ($item) {
            $ticketType = $item->ticketType()->first();
            $paid = Ticket::whereIn('order_item_id', $ticketType->orderItems()->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Paid))->pluck('id'))->count();

            expect($ticketType->remaining())->toBe($ticketType->capacity - $paid);
        });
    });
});

test('seeding sends no mail', function () {
    Mail::fake();

    $this->seed();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

test('production seeds only the admin with the configured password', function () {
    runAsProduction();
    config(['app.seed_admin_password' => 'secret-pass']);

    runDatabaseSeeder();

    $admin = User::firstOrFail();

    expect(User::count())->toBe(1)
        ->and($admin->email)->toBe('admin@example.com')
        ->and($admin->role)->toBe(UserRole::Admin)
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('secret-pass', $admin->password))->toBeTrue()
        ->and(Hash::check('password', $admin->password))->toBeFalse()
        ->and(Event::count())->toBe(0);
});

test('production refuses to seed without an admin password', function (?string $password) {
    runAsProduction();
    config(['app.seed_admin_password' => $password]);

    expect(fn () => runDatabaseSeeder())->toThrow(RuntimeException::class);
    expect(User::count())->toBe(0);
})->with([
    'empty' => '',
    'not set' => null,
]);

test('seeding production twice keeps a single admin and does not reset its password', function () {
    runAsProduction();
    config(['app.seed_admin_password' => 'secret-pass']);
    runDatabaseSeeder();

    config(['app.seed_admin_password' => 'another-pass']);
    runDatabaseSeeder();

    $admin = User::sole();

    expect($admin->role)->toBe(UserRole::Admin)
        ->and(Hash::check('secret-pass', $admin->password))->toBeTrue()
        ->and(Hash::check('another-pass', $admin->password))->toBeFalse();
});

test('environments other than local and testing get only the admin', function () {
    app()->detectEnvironment(fn () => 'staging');
    config(['app.seed_admin_password' => 'secret-pass']);

    runDatabaseSeeder();

    expect(User::sole()->role)->toBe(UserRole::Admin)
        ->and(Event::count())->toBe(0);
});
