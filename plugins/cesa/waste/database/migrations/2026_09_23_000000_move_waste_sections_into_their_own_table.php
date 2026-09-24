<?php

use Cesa\Waste\Models\WasteSection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('waste_sections')) {
            Schema::create('waste_sections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_id')->constrained('waste_brands')->cascadeOnDelete();
                $table->string('code', 100);
                $table->string('name');
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
                $table->unique(['brand_id', 'code']);
            });
        }

        if (Schema::hasColumn('waste_items', 'section')) {
            Schema::table('waste_items', function (Blueprint $table): void {
                $table->dropColumn('section');
            });
        }

        WasteSection::seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_sections');

        if (Schema::hasTable('waste_items') && ! Schema::hasColumn('waste_items', 'section')) {
            Schema::table('waste_items', function (Blueprint $table): void {
                $table->string('section', 64)->nullable();
            });
        }
    }
};
