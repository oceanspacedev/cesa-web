<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_items', function (Blueprint $table): void {
            $table->string('source_unit_label')->nullable()->after('unit');
        });

        Schema::table('waste_event_lines', function (Blueprint $table): void {
            $table->string('unit_label')->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('waste_event_lines', function (Blueprint $table): void {
            $table->dropColumn('unit_label');
        });

        Schema::table('waste_items', function (Blueprint $table): void {
            $table->dropColumn('source_unit_label');
        });
    }
};
