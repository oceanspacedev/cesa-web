<?php

namespace Cesa\Waste\Http\Controllers;

use Cesa\Waste\Models\WasteEvidence;
use Cesa\Waste\Services\WasteReportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class WasteEvidenceController
{
    public function admin(int $evidence): Response
    {
        $evidenceModel = WasteEvidence::query()->with('event.version.report')->findOrFail($evidence);
        $report = $evidenceModel->event->version->report;

        Gate::authorize('view', $report);
        abort_unless((int) $evidenceModel->event->version_id === (int) $report->latest_version_id, 404);

        return $this->response($evidenceModel);
    }

    public function __invoke(int $evidence, string $token, WasteReportService $service): Response
    {
        try {
            $report = $service->reportForProgressToken($token);
            $versionId = $report->latest_version_id;
        } catch (ModelNotFoundException) {
            try {
                $report = $service->reportForManageToken($token);
                $versionId = $report->latest_version_id;
            } catch (ModelNotFoundException) {
                $approval = $service->approvalForToken($token);
                $versionId = $approval->version_id;
            }
        }

        $evidenceModel = WasteEvidence::query()
            ->whereKey($evidence)
            ->whereHas('event.version', fn ($query) => $query->whereKey($versionId))
            ->firstOrFail();

        return $this->response($evidenceModel);
    }

    protected function response(WasteEvidence $evidence): Response
    {
        $disk = Storage::disk(config('waste.attachments.disk', 'local'));
        abort_unless($disk->exists($evidence->path), 404);

        return response($disk->get($evidence->path), 200, [
            'Content-Type'        => $evidence->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($evidence->original_name ?: 'evidence').'"',
            'Cache-Control'       => 'private, max-age=60',
        ]);
    }
}
