<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_item_unit_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('waste_items')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('waste_units')->cascadeOnDelete();
            $table->string('source_file');
            $table->string('source_sheet', 100);
            $table->unsignedInteger('example_row');
            $table->unsignedInteger('source_row_count');
            $table->json('source_unit_labels');
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_item_unit_candidates');
    }
};
