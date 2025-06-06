<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test1@ivz.pl',
            'password' => bcrypt('#Marcin123'),
            'ws_token' => \Str::random(64),
        ]);

        User::create([
            'name' => 'Test 2',
            'email' => 'test2@ivz.pl',
            'password' => bcrypt('#Marcin123'),
            'ws_token' => \Str::random(64),
        ]);
    }
}
