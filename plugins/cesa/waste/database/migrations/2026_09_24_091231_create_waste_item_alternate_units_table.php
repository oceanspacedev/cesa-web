<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_item_alternate_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('waste_items')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('waste_units')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['item_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_item_alternate_units');
    }
};
