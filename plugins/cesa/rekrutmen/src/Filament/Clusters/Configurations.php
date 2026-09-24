<?php

namespace Cesa\Rekrutmen\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Panel;

class Configurations extends Cluster
{
    public static function getSlug(?Panel $panel = null): string
    {
        return 'rekrutmen-shield/configurations';
    }

    public static function getNavigationLabel(): string
    {
        return __('rekrutmen::filament/clusters/configurations.navigation.label');
    }

    public static function getNavigationGroup(): string
    {
        return __('admin.navigation.rekrutmen');
    }

    public static function getNavigationIcon(): ?string
    {
        return null;
    }

    public static function getNavigationSort(): ?int
    {
        return 1000;
    }
}
