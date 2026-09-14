<?php

namespace Cesa\Rekrutmen;

use Filament\Contracts\Plugin;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use ReflectionClass;
use Webkul\PluginManager\Package;

class RekrutmenPlugin implements Plugin
{
    public function getId(): string
    {
        return 'rekrutmen';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function register(Panel $panel): void
    {
        if (! Package::isPluginInstalled($this->getId())) {
            return;
        }

        $panel
            ->when($panel->getId() === 'admin', function (Panel $panel) {
                // Register high-level navigation items for Rekrutmen in Filament topbar pointing to Vue SPA
                $panel->navigationItems([
                    NavigationItem::make('Manpower Requests')
                        ->url('/admin/request-man-powers')
                        ->group('Rekrutmen')
                        ->sort(1),
                    NavigationItem::make('Job Postings')
                        ->url('/admin/job-postings')
                        ->group('Rekrutmen')
                        ->sort(2),
                    NavigationItem::make('Job Applications')
                        ->url('/admin/job-applications')
                        ->group('Rekrutmen')
                        ->sort(3),
                    NavigationItem::make('Recruitment Progress')
                        ->url('/admin/recruitment-progress')
                        ->group('Rekrutmen')
                        ->sort(4),
                    NavigationItem::make('Configurations')
                        ->url('/admin/configurations')
                        ->group('Rekrutmen')
                        ->sort(5),
                ]);
            });
    }

    public function boot(Panel $panel): void {}

    protected function getPluginBasePath($path = null): string
    {
        $reflector = new ReflectionClass(get_class($this));

        return dirname($reflector->getFileName()).($path ?? '');
    }
}
