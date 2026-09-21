<?php

namespace Cesa\IdCard\Http\Controllers;

use App\Http\Controllers\Controller;
use Cesa\IdCard\Models\IdCardRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\PluginManager\Package;

class IdCardPhotoController extends Controller
{
    public function __invoke(IdCardRequest $idCardRequest): StreamedResponse
    {
        abort_unless(Package::isPluginInstalled('id-card'), 404);
        Gate::authorize('view', $idCardRequest);

        $path = $idCardRequest->photo;
        abort_unless(is_string($path) && str_starts_with($path, 'id-card/photos/'), 404);
        abort_if(str_contains($path, '..'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
