<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_event_lines', function (Blueprint $table): void {
            $table->string('item_type')->nullable()->after('item_name');
        });

        DB::table('waste_event_lines')
            ->whereNotNull('item_id')
            ->select(['id', 'item_id'])
            ->chunkById(500, function ($lines): void {
                $itemTypes = DB::table('waste_items')
                    ->whereIn('id', $lines->pluck('item_id')->unique())
                    ->pluck('item_type', 'id');

                foreach ($lines->groupBy('item_id') as $itemId => $itemLines) {
                    $itemType = $itemTypes->get($itemId);
                    if ($itemType === null) {
                        continue;
                    }

                    DB::table('waste_event_lines')
                        ->whereIn('id', $itemLines->pluck('id'))
                        ->update(['item_type' => $itemType]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('waste_event_lines', function (Blueprint $table): void {
            $table->dropColumn('item_type');
        });
    }
};
