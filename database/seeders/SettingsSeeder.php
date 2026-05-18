<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'yape_number' => '912 874 650',
            'yape_holder' => 'Solange Llantoy',
            'business_hours' => 'Lunes a Sábado de 10:00 a.m. a 8:00 p.m.',
            'motorizado_window' => 'Lunes a Sábado de 5 p.m. a 9 p.m.',
            'shalom_lima' => '10',
            'shalom_provincia' => '12',
            'followup_enabled' => '1',
            'max_followups' => '3',
            'store_name' => 'Roma Store',
            'store_signature' => 'Roma Store ✨',
        ];

        foreach ($defaults as $key => $value) {
            Setting::set($key, $value);
        }
    }
}
