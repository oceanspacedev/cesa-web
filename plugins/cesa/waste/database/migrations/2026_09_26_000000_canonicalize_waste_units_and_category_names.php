<?php

use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('waste_units')->get(['id', 'code']) as $unit) {
            $canonical = WasteUnit::normalizeCode($unit->code);

            if ($canonical === null || $canonical === $unit->code) {
                continue;
            }

            $canonicalId = DB::table('waste_units')->where('code', $canonical)->value('id');

            if (! $canonicalId) {
                DB::table('waste_units')->where('id', $unit->id)->update(['code' => $canonical]);

                continue;
            }

            DB::table('waste_items')->where('unit', $unit->code)->update(['unit' => $canonical]);
            DB::table('waste_event_lines')->where('unit', $unit->code)->update(['unit' => $canonical]);
            DB::table('waste_events')->where('pip_unit', $unit->code)->update(['pip_unit' => $canonical]);
            $this->mergeAlternateUnits((int) $unit->id, (int) $canonicalId);
            DB::table('waste_units')->where('id', $unit->id)->delete();
        }

        DB::table('waste_events')->select(['id', 'category_name'])
            ->chunkById(500, function ($events): void {
                foreach ($events as $event) {
                    $normalized = WasteCategory::normalizedName($event->category_name);
                    if ($normalized !== $event->category_name) {
                        DB::table('waste_events')->where('id', $event->id)->update(['category_name' => $normalized]);
                    }
                }
            });

        DB::table('waste_categories')->where('name', 'Discontinued')->update(['is_active' => false]);
        DB::table('waste_sections')->where('name', 'DINING')->update(['is_active' => false]);
    }

    public function down(): void {}

    protected function mergeAlternateUnits(int $aliasUnitId, int $canonicalUnitId): void
    {
        foreach (DB::table('waste_item_alternate_units')->where('unit_id', $aliasUnitId)->get(['id', 'item_id']) as $pivot) {
            $alreadyLinked = DB::table('waste_item_alternate_units')
                ->where('item_id', $pivot->item_id)
                ->where('unit_id', $canonicalUnitId)
                ->exists();

            if ($alreadyLinked) {
                DB::table('waste_item_alternate_units')->where('id', $pivot->id)->delete();

                continue;
            }

            DB::table('waste_item_alternate_units')->where('id', $pivot->id)->update(['unit_id' => $canonicalUnitId]);
        }
    }
};
