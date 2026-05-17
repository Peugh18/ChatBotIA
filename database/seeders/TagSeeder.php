<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['name' => 'VIP',         'color' => '#f5b400'],
            ['name' => 'Mayorista',   'color' => '#9333ea'],
            ['name' => 'Lima',        'color' => '#00a884'],
            ['name' => 'Provincia',   'color' => '#3b82f6'],
            ['name' => 'Recurrente',  'color' => '#ec4899'],
            ['name' => 'Difícil',     'color' => '#f15c6d'],
            ['name' => 'TikTok Live', 'color' => '#06cf9c'],
        ];

        foreach ($defaults as $data) {
            Tag::firstOrCreate(['name' => $data['name']], $data);
        }
    }
}
