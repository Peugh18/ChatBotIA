<?php

namespace Database\Seeders;

use App\Models\DeliveryZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DeliveryZoneSeeder extends Seeder
{
    public function run(): void
    {
        // Costo de motorizado en Lima Metropolitana (S/.).
        // Fuente: tabla oficial provista por Roma Store.
        // Shalom Lima fijo en 10, provincia 12 promedio (ver region).
        $zones = [
            // ── Lima Centro / Top zonas ────────────────────────────────────
            ['Cercado de Lima',         12],
            ['Breña',                   14],
            ['Jesús María',             14],
            ['La Victoria',             11],
            ['Lince',                   14],
            ['Magdalena del Mar',       15],
            ['Miraflores',              15],
            ['Pueblo Libre',            15],
            ['Rímac',                   11],
            ['San Borja',               11],
            ['San Isidro',              14],
            ['San Miguel',              15],
            ['Santiago de Surco',       12],
            ['Surquillo',               13],

            // ── Lima Norte ─────────────────────────────────────────────────
            ['Carabayllo',              20],
            ['Comas',                   16],
            ['Independencia',           15],
            ['Los Olivos',              15],
            ['Puente Piedra',           20],
            ['San Martín de Porres',    15],
            ['Santa Rosa',              35],
            ['Ancón',                   35],

            // ── Lima Este ──────────────────────────────────────────────────
            ['Ate',                     10],
            ['El Agustino',             10],
            ['Lurigancho-Chosica',      16],
            ['San Juan de Lurigancho',  14],
            ['Santa Anita',             10],
            ['Chaclacayo',              16],
            ['Cieneguilla',             30],

            // ── Lima Sur ───────────────────────────────────────────────────
            // ⚠️ "Barranco" venía como 1 sol en la fuente original (probable
            // typo). Se ajusta a 15 por consistencia con su zona; editable.
            ['Barranco',                15],
            ['Chorrillos',              15],
            ['Lurín',                   30],
            ['Pachacámac',              30],
            ['Pucusana',                35],
            ['Punta Hermosa',           35],
            ['Punta Negra',             35],
            ['San Bartolo',             35],
            ['San Juan de Miraflores',  16],
            ['Santa María del Mar',     35],
            ['Villa El Salvador',       16],
            ['Villa María del Triunfo', 16],
        ];

        foreach ($zones as [$district, $cost]) {
            DeliveryZone::updateOrCreate(
                ['slug' => Str::slug($district)],
                [
                    'district'        => $district,
                    'motorizado_cost' => $cost,
                    'shalom_cost'     => 10,
                    'region'          => 'Lima',
                    'active'          => true,
                ]
            );
        }
    }
}
