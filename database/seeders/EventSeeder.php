<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;

class EventSeeder extends Seeder
{
    /**
     * Create six Hachimantai events: four published (one already held), one draft and one unpublished.
     */
    public function run(): void
    {
        $outdoorClub = User::where('email', 'organizer1@example.com')->firstOrFail();
        $onsenTown = User::where('email', 'organizer2@example.com')->firstOrFail();

        $this->createEvent($outdoorClub, EventStatus::Published, now()->addDays(10)->setTime(6, 0), [
            'title' => '八幡平アスピーテラインの朝歩き',
            'description' => '日の出前に八幡平山頂レストハウスを出発し、八幡沼をめぐる約2時間の山歩きです。ガイドが高山植物と火山の地形を案内します。',
            'venue' => '八幡平山頂レストハウス',
        ], [
            ['name' => '一般', 'price' => 3000, 'capacity' => 20],
            ['name' => '学生', 'price' => 1500, 'capacity' => 10],
        ]);

        $this->createEvent($onsenTown, EventStatus::Published, now()->addDays(17)->setTime(11, 0), [
            'title' => '松川温泉の地熱蒸し料理教室',
            'description' => '温泉の蒸気で野菜や卵を蒸し上げる、地熱の台所を使った料理教室です。できた料理はその場で味わえます。',
            'venue' => '松川温泉 地熱の台所',
        ], [
            ['name' => '一般', 'price' => 3000, 'capacity' => 12],
            ['name' => '見学のみ', 'price' => 0, 'capacity' => 5],
        ]);

        $this->createEvent($outdoorClub, EventStatus::Published, now()->addDays(45)->setTime(9, 30), [
            'title' => '雪原スノーシュー体験',
            'description' => 'スノーシューを履いて、樹氷の森を歩きます。道具の貸し出しつきで、はじめての人でも参加できます。',
            'venue' => '八幡平リゾート パノラマゲレンデ前',
        ], [
            ['name' => '一般', 'price' => 3000, 'capacity' => 15],
            ['name' => '学生', 'price' => 1500, 'capacity' => 10],
            ['name' => '小学生（無料）', 'price' => 0, 'capacity' => 5],
        ]);

        $this->createEvent($outdoorClub, EventStatus::Published, now()->subDays(7)->setTime(17, 0), [
            'title' => '紅葉の八幡沼ナイトハイク',
            'description' => '紅葉に染まった八幡沼のほとりを、夕暮れから夜にかけて歩きます。星空の解説つきです。',
            'venue' => '八幡平山頂レストハウス',
        ], [
            ['name' => '一般', 'price' => 3000, 'capacity' => 20],
        ]);

        $this->createEvent($onsenTown, EventStatus::Draft, now()->addDays(30)->setTime(13, 0), [
            'title' => '湯けむりの写真教室',
            'description' => '藤七温泉の湯けむりと山並みを撮る、カメラ初心者向けの写真教室です。',
            'venue' => '藤七温泉 彩雲荘',
        ], [
            ['name' => '一般', 'price' => 3000, 'capacity' => 8],
        ]);

        $this->createEvent($onsenTown, EventStatus::Unpublished, now()->addDays(20)->setTime(19, 0), [
            'title' => '安比高原の星空観察会',
            'description' => '安比高原の草原で、望遠鏡を使って秋の星座を観察します。',
            'venue' => '安比高原 中のまきば',
        ], [
            ['name' => '一般', 'price' => 3000, 'capacity' => 30],
        ]);
    }

    /**
     * Create an event lasting three hours from the start. Its ticket types go on sale 30 days before
     * (or yesterday, if that is earlier) and stop the day before.
     *
     * @param  array{title: string, description: string, venue: string}  $details
     * @param  list<array{name: string, price: int, capacity: int}>  $ticketTypes
     */
    private function createEvent(User $organizer, EventStatus $status, CarbonInterface $startsAt, array $details, array $ticketTypes): void
    {
        $event = Event::factory()->for($organizer, 'organizer')->create([
            ...$details,
            'status' => $status,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(3),
        ]);

        foreach ($ticketTypes as $ticketType) {
            $event->ticketTypes()->create([
                ...$ticketType,
                'sale_starts_at' => $startsAt->copy()->subDays(30)->min(now()->subDay()),
                'sale_ends_at' => $startsAt->copy()->subDay(),
            ]);
        }
    }
}
