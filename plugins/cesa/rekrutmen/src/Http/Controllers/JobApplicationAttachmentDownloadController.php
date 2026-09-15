<?php

namespace Cesa\Rekrutmen\Http\Controllers;

use Cesa\Rekrutmen\Models\JobApplication;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class JobApplicationAttachmentDownloadController extends Controller
{
    public function __invoke(Request $request, JobApplication $jobApplication, string $attachment): Response
    {
        abort_unless(in_array($attachment, ['resume', 'photo'], true), 404);
        abort_unless($request->user()?->can('view', $jobApplication), 403);

        $path = $jobApplication->resolveAttachmentPath($attachment);

        if (blank($path)) {
            abort(404);
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return redirect()->away($path);
        }

        $disk = $jobApplication->resolveAttachmentDisk($attachment);
        abort_if($disk === null, 404);

        return Storage::disk($disk)->response($path, basename($path));
    }
}
