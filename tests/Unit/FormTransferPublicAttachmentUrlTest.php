<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

uses(TestCase::class);

it('builds public attachment urls without signature or expiry', function (): void {
    if (! Route::has('form-transfer.public.attachments.download')) {
        require base_path('plugins/cesa/form-transfer/routes/web.php');
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    $url = URL::route('form-transfer.public.attachments.download', [
        'statusResponseId' => 'token-demo',
        'attachment'       => 'invoice',
        'file'             => 0,
    ]);

    expect($url)
        ->toContain('/transfer-requests/files/token-demo/invoice')
        ->not->toContain('signature=')
        ->not->toContain('expires=');
});
