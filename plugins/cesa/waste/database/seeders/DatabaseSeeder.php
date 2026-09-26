<?php

namespace Cesa\Waste\Database\Seeders;

use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Models\WasteUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'GR'   => 'Gram', 'KG' => 'Kilogram', 'ML' => 'Mililiter', 'L' => 'Liter',
            'PCS'  => 'Pieces', 'PRS' => 'Porsi', 'SHT' => 'Sachet', 'PAI' => 'Pail',
            'SLC'  => 'Slice', 'CC' => 'Sentimeter Kubik', 'LBR' => 'Lembar', 'ROL' => 'Roll',
            'PCK'  => 'Pack', 'BTL' => 'Botol', 'PSG' => 'Porsi', 'GALON' => 'Galon',
            'BUAH' => 'Buah', 'BAL' => 'Bal',
        ] as $code => $name) {
            WasteUnit::query()->updateOrCreate(['code' => $code], [
                'name'      => $name,
                'is_active' => true,
            ]);
        }

        $brands = [
            ['name' => 'Jchicken', 'code' => 'JCHICKEN'],
            ['name' => 'Luuca', 'code' => 'LUUCA'],
            ['name' => 'Momoyo', 'code' => 'MOMOYO'],
        ];

        $categories = [
            'JCHICKEN' => ['Waste', 'Spoil', 'Training', 'Test food/Kalibrasi'],
            'LUUCA'    => ['Waste', 'Spoil', 'Training', 'Test food/Kalibrasi'],
            'MOMOYO'   => ['Waste', 'Spoil', 'Training/Trial', 'Pemotretan/Event/Talent'],
        ];

        foreach ($brands as $brandData) {
            $brand = WasteBrand::query()->updateOrCreate(['code' => $brandData['code']], [
                'name'      => $brandData['name'],
                'is_active' => true,
            ]);

            WasteOutlet::query()->updateOrCreate([
                'brand_id' => $brand->getKey(),
                'code'     => 'CILEDUG',
            ], [
                'name'      => 'Ciledug',
                'slug'      => Str::slug($brand->code.' Ciledug'),
                'timezone'  => 'Asia/Jakarta',
                'is_active' => true,
            ]);

            foreach ($categories[$brand->code] as $categoryName) {
                WasteCategory::query()->updateOrCreate([
                    'brand_id' => $brand->getKey(),
                    'code'     => Str::upper(Str::slug($categoryName, '_')),
                ], [
                    'name'      => $categoryName,
                    'is_active' => true,
                ]);
            }
        }

        WasteSection::seedDefaults();

        $this->call(WasteItemSeeder::class);
    }
}
