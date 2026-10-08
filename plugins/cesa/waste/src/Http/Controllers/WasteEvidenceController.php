<?php

namespace Cesa\Waste\Http\Controllers;

use Cesa\Waste\Models\WasteEvidence;
use Cesa\Waste\Services\WasteReportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        $versionId = $this->versionIdForPublicToken($token, $service);

        $evidenceModel = WasteEvidence::query()
            ->whereKey($evidence)
            ->whereHas('event.version', fn ($query) => $query->whereKey($versionId))
            ->firstOrFail();

        return $this->response($evidenceModel);
    }

    protected function versionIdForPublicToken(string $token, WasteReportService $service): int|string
    {
        try {
            return $service->reportForProgressToken($token)->latest_version_id;
        } catch (ModelNotFoundException|HttpException) {
            // Progress lookup may abort(404); keep falling through to manage/approval tokens.
        }

        try {
            return $service->reportForManageToken($token)->latest_version_id;
        } catch (ModelNotFoundException|HttpException) {
            // Continue to approval token lookup.
        }

        try {
            return $service->approvalForToken($token)->version_id;
        } catch (ModelNotFoundException|HttpException) {
            abort(404);
        }
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
