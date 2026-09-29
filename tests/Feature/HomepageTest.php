<?php

test('homepage renders successfully and opens external links in a new tab', function () {
    $response = $this->get('/');

    $response->assertStatus(200);

    // External links should have target="_blank" and rel="noopener noreferrer"
    $response->assertSee('href="https://helpdesk.completeselular.com/"', false);
    $response->assertSee('href="https://sam.mediaselularindonesia.com/"', false);
    $response->assertSee('href="http://csa1.completeselular.com/owncloud/"', false);
    $response->assertSee('id="app-cloud-busdev"', false);
    $response->assertSee('Cloud Busdev', false);
    $response->assertSee('href="https://n8n.completeselular.com/"', false);
    $response->assertSee('id="app-n8n"', false);
    $response->assertSee('>n8n<', false);
    $response->assertSee('href="https://iams.completeselular.com/"', false);
    $response->assertSee('id="app-iams"', false);
    $response->assertSee('IAMS', false);
    $response->assertSee('Internal Audit Management System', false);
    expect($response->getContent())->not->toMatch('/id="app-iams"[^>]*data-local-only/');
    expect($response->getContent())->not->toMatch('/id="app-n8n"[^>]*data-local-only/');
    expect($response->getContent())->not->toMatch('/id="app-cloud-busdev"[^>]*data-local-only/');
    $response->assertSee('href="http://30.30.30.49:8069/"', false);
    $response->assertDontSee('https://odoo.completeselular.com/', false);
    $response->assertSee('id="app-odoo"', false);
    $response->assertSee('Odoo hanya terbuka lewat jaringan WiFi kantor.', false);
    expect($response->getContent())->toMatch('/\.local-network-dialog\s*\{[^}]*margin:\s*auto/s');
    expect($response->getContent())
        ->toMatch('/id="app-odoo"[^>]*data-local-only/')
        ->not->toMatch('/id="app-helpdesk"[^>]*data-local-only/');
    $response->assertSee('target="_blank"', false);
    $response->assertSee('rel="noopener noreferrer"', false);

    // Internal links should also be visible
    $response->assertSee('href="/form"', false);
});
