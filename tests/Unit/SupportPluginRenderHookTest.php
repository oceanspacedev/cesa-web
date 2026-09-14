<?php

use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\Support\View\ViewManager;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Webkul\Support\SupportPlugin;

afterEach(function (): void {
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
});

it('guards sidebar scrolling on pages without a sidebar', function (): void {
    $container = new Container;
    $container->instance(ViewManager::class, new ViewManager);

    Container::setInstance($container);
    Facade::setFacadeApplication($container);

    (new SupportPlugin)->boot(new Panel);

    $script = FilamentView::renderHook('panels::scripts.before')->toHtml();

    expect($script)
        ->toContain('if (! activeSidebarItem || ! sidebarWrapper) {')
        ->toContain('sidebarWrapper.scrollTo(0, activeSidebarItem.offsetTop - 250);');
});
