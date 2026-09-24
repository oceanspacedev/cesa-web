<?php

namespace Cesa\Waste\Http\Controllers;

use Cesa\Waste\Services\WasteReportService;
use Illuminate\Http\RedirectResponse;

class PublicWasteSubmittedController
{
    public function __invoke(string $token, string $revision, WasteReportService $service): RedirectResponse
    {
        $report = $service->reportForProgressToken($token);
        abort_unless(
            is_string($report->manage_token_hash) && hash_equals($report->manage_token_hash, $service->tokenHash($revision)),
            404,
        );

        return redirect()->route('waste.public.progress', ['token' => $token]);
    }
}
