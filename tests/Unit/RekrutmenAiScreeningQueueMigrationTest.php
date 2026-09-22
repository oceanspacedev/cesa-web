<?php

it('registers the ai screening queue fields migration with the rekrutmen plugin', function (): void {
    $root = dirname(__DIR__, 2);
    $provider = file_get_contents($root.'/plugins/cesa/rekrutmen/src/RekrutmenServiceProvider.php');
    $migration = file_get_contents($root.'/plugins/cesa/rekrutmen/database/migrations/2026_09_18_233749_rekrutmen_add_ai_screening_queue_fields.php');

    expect($provider)->toContain('2026_09_18_233749_rekrutmen_add_ai_screening_queue_fields')
        ->and($migration)->toContain('ai_screening_status')
        ->and($migration)->toContain('ai_screening_token')
        ->and($migration)->toContain('ai_screening_error');
});

it('dispatches ai screening jobs on a dedicated queue the legacy worker does not consume', function (): void {
    $root = dirname(__DIR__, 2);
    $config = file_get_contents($root.'/plugins/cesa/rekrutmen/config/rekrutmen.php');
    $service = file_get_contents($root.'/plugins/cesa/rekrutmen/src/Services/AiScreeningService.php');
    $controller = file_get_contents($root.'/plugins/cesa/rekrutmen/src/Http/Controllers/RekrutmenSpaController.php');

    expect($config)->toContain("env('REKRUTMEN_AI_QUEUE', 'rekrutmen-ai')")
        ->and($service)->toContain('function queueName')
        ->and($service)->toContain('onQueue($this->queueName())')
        ->and($controller)->toContain('onQueue(app(AiScreeningService::class)->queueName())');
});
