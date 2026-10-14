<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    /**
     * Create paid orders with their tickets, and pending orders older than the hold period
     * (they no longer hold seats). Orders are built directly, so no purchase mail is sent.
     * Demo data only: it uses Faker, which is not installed in production.
     */
    public function run(): void
    {
        $hanako = User::where('email', 'customer1@example.com')->firstOrFail();
        $taro = User::where('email', 'customer2@example.com')->firstOrFail();
        $sakura = User::where('email', 'customer3@example.com')->firstOrFail();

        $morningWalk = Event::where('title', '八幡平アスピーテラインの朝歩き')->firstOrFail();
        $cooking = Event::where('title', '松川温泉の地熱蒸し料理教室')->firstOrFail();
        $snowshoe = Event::where('title', '雪原スノーシュー体験')->firstOrFail();
        $nightHike = Event::where('title', '紅葉の八幡沼ナイトハイク')->firstOrFail();

        $this->createOrder($hanako, $morningWalk, ['一般' => 2], OrderStatus::Paid, now()->subDays(3));
        $this->createOrder($taro, $morningWalk, ['一般' => 1, '学生' => 1], OrderStatus::Paid, now()->subDays(2));
        $this->createOrder($sakura, $cooking, ['一般' => 2, '見学のみ' => 1], OrderStatus::Paid, now()->subDay());
        $this->createOrder($hanako, $nightHike, ['一般' => 1], OrderStatus::Paid, now()->subDays(12));

        $this->createOrder($taro, $snowshoe, ['一般' => 2], OrderStatus::Pending, now()->subMinutes(Order::HOLD_MINUTES + 15));
        $this->createOrder($sakura, $morningWalk, ['一般' => 3], OrderStatus::Pending, now()->subHours(2));
    }

    /**
     * Create an order with items at the current prices, and one ticket per seat when it is paid.
     *
     * @param  array<string, int>  $quantities  ticket type name => quantity
     */
    private function createOrder(User $user, Event $event, array $quantities, OrderStatus $status, CarbonInterface $createdAt): void
    {
        $ticketTypes = $event->ticketTypes()->whereIn('name', array_keys($quantities))->get()->keyBy('name');
        $isPaid = $status === OrderStatus::Paid;

        $order = Order::factory()->for($user)->for($event)->create([
            'status' => $status,
            'total_amount' => collect($quantities)->sum(fn (int $quantity, string $name) => $quantity * $ticketTypes[$name]->price),
            'stripe_checkout_session_id' => 'cs_test_seed_'.fake()->unique()->regexify('[A-Za-z0-9]{16}'),
            'paid_at' => $isPaid ? $createdAt->copy()->addMinutes(3) : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        foreach ($quantities as $name => $quantity) {
            /** @var TicketType $ticketType */
            $ticketType = $ticketTypes[$name];

            $item = OrderItem::factory()->for($order)->for($ticketType)->create([
                'quantity' => $quantity,
                'unit_price' => $ticketType->price,
            ]);

            if ($isPaid) {
                Ticket::factory()->count($quantity)->for($item)->create();
            }
        }
    }
}
