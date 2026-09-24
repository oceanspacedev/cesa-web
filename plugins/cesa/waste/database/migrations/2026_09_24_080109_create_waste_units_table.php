<?php

use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_units', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        WasteItem::query()
            ->whereNotNull('unit')
            ->distinct()
            ->pluck('unit')
            ->each(function (mixed $value): void {
                $code = trim((string) $value);
                if ($code !== '') {
                    WasteUnit::query()->firstOrCreate(['code' => $code], ['name' => $code]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_units');
    }
};
