<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Create the demo admin, organizers and customers. Every password is "password".
     */
    public function run(): void
    {
        User::factory()->admin()->create(['name' => '管理者', 'email' => 'admin@example.com']);

        User::factory()->organizer()->create(['name' => '八幡平アウトドア倶楽部', 'email' => 'organizer1@example.com']);
        User::factory()->organizer()->create(['name' => '松川温泉まちづくり会', 'email' => 'organizer2@example.com']);

        User::factory()->create(['name' => '岩手 花子', 'email' => 'customer1@example.com']);
        User::factory()->create(['name' => '盛岡 太郎', 'email' => 'customer2@example.com']);
        User::factory()->create(['name' => '雫石 さくら', 'email' => 'customer3@example.com']);
    }
}
