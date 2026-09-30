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
            $table->text('reason')->nullable()->after('quantity');
        });

        DB::table('waste_event_lines')
            ->select(['id', 'event_id'])
            ->orderBy('id')
            ->chunkById(500, function ($lines): void {
                $reasons = DB::table('waste_events')
                    ->whereIn('id', $lines->pluck('event_id')->unique())
                    ->pluck('reason', 'id');

                foreach ($lines->groupBy('event_id') as $eventId => $eventLines) {
                    $reason = $reasons->get($eventId);
                    if ($reason === null || $reason === '') {
                        continue;
                    }

                    DB::table('waste_event_lines')
                        ->whereIn('id', $eventLines->pluck('id'))
                        ->update(['reason' => $reason]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('waste_event_lines', function (Blueprint $table): void {
            $table->dropColumn('reason');
        });
    }
};
