<?php

namespace Cesa\Rekrutmen;

use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobApplicationHistory;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RequestManPower;
use Filament\Contracts\Plugin;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
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
                $panel->discoverResources(
                    in: $this->getPluginBasePath('/Filament/Resources'),
                    for: 'Cesa\\Rekrutmen\\Filament\\Resources',
                );

                // Vue SPA owns the visible Rekrutmen menu. Resources stay registered so Shield can list their permissions.
                $panel->navigationItems([
                    NavigationItem::make('Manpower Requests')
                        ->url('/admin/request-man-powers')
                        ->group('Rekrutmen')
                        ->sort(1)
                        ->visible(fn (): bool => Gate::allows('viewAny', RequestManPower::class)),
                    NavigationItem::make('Job Postings')
                        ->url('/admin/job-postings')
                        ->group('Rekrutmen')
                        ->sort(2)
                        ->visible(fn (): bool => Gate::allows('viewAny', JobPosting::class)),
                    NavigationItem::make('Job Applications')
                        ->url('/admin/job-applications')
                        ->group('Rekrutmen')
                        ->sort(3)
                        ->visible(fn (): bool => Gate::allows('viewAny', JobApplication::class)),
                    NavigationItem::make('Recruitment Progress')
                        ->url('/admin/recruitment-progress')
                        ->group('Rekrutmen')
                        ->sort(4)
                        ->visible(fn (): bool => Gate::allows('viewAny', JobApplicationHistory::class)),
                    NavigationItem::make('Configurations')
                        ->url('/admin/configurations')
                        ->group('Rekrutmen')
                        ->sort(5)
                        ->visible(fn (): bool => Gate::allows('access_rekrutmen_configurations')),
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
