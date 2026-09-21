<?php

namespace Cesa\IdCard\Database\Seeders;

use Cesa\IdCard\Models\IdCardRequest;
use Illuminate\Database\Seeder;

class IdCardRequestSeeder extends Seeder
{
    public function run(): void
    {
        IdCardRequest::factory()->count(10)->create();
    }
}
