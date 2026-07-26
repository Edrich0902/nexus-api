<?php

namespace Database\Seeders;

use App\Models\Beer\BeerStyle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BeerStyleSeeder extends Seeder
{
    public function run(): void
    {
        $styles = [
            ['name' => 'IPA', 'family' => 'Ale'],
            ['name' => 'Double IPA', 'family' => 'Ale'],
            ['name' => 'Pale Ale', 'family' => 'Ale'],
            ['name' => 'Amber Ale', 'family' => 'Ale'],
            ['name' => 'Brown Ale', 'family' => 'Ale'],
            ['name' => 'Stout', 'family' => 'Ale'],
            ['name' => 'Imperial Stout', 'family' => 'Ale'],
            ['name' => 'Porter', 'family' => 'Ale'],
            ['name' => 'Wheat Beer', 'family' => 'Ale'],
            ['name' => 'Hefeweizen', 'family' => 'Ale'],
            ['name' => 'Saison', 'family' => 'Ale'],
            ['name' => 'Sour', 'family' => 'Ale'],
            ['name' => 'Gose', 'family' => 'Ale'],
            ['name' => 'Belgian Tripel', 'family' => 'Ale'],
            ['name' => 'Belgian Dubbel', 'family' => 'Ale'],
            ['name' => 'Pilsner', 'family' => 'Lager'],
            ['name' => 'Helles', 'family' => 'Lager'],
            ['name' => 'Dunkel', 'family' => 'Lager'],
            ['name' => 'Bock', 'family' => 'Lager'],
            ['name' => 'Lager', 'family' => 'Lager'],
            ['name' => 'Marzen / Festbier', 'family' => 'Lager'],
            ['name' => 'Rauchbier', 'family' => 'Lager'],
            ['name' => 'Cider', 'family' => 'Other'],
            ['name' => 'Other', 'family' => 'Other'],
        ];

        foreach ($styles as $style) {
            BeerStyle::query()->updateOrCreate(
                ['slug' => Str::slug($style['name'])],
                [
                    'name' => $style['name'],
                    'family' => $style['family'],
                    'description' => null,
                ],
            );
        }
    }
}
