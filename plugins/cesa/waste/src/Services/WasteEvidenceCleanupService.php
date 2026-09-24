<?php

namespace Cesa\Waste\Services;

use Cesa\Waste\Models\WasteEvidence;
use Cesa\Waste\Models\WasteReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WasteEvidenceCleanupService
{
    /**
     * @return array<int, string>
     */
    public function pathsForReport(WasteReport $report): array
    {
        return WasteEvidence::query()
            ->whereHas('event.version', fn (Builder $query): Builder => $query->where('report_id', $report->getKey()))
            ->distinct()
            ->pluck('path')
            ->all();
    }

    /**
     * @param  array<int, string>  $paths
     */
    public function deleteUnreferencedAfterCommit(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        DB::afterCommit(function () use ($paths): void {
            $directory = trim((string) config('waste.attachments.directory', 'waste/evidence'), '/');
            if ($directory === '') {
                return;
            }

            $disk = (string) config('waste.attachments.disk', 'local');
            foreach (array_unique($paths) as $path) {
                if (! str_starts_with($path, $directory.'/') || str_contains($path, '..')) {
                    continue;
                }

                if (WasteEvidence::query()->where('path', $path)->exists()) {
                    continue;
                }

                Storage::disk($disk)->delete($path);
            }
        });
    }
}
