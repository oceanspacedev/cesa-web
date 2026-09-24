<?php

namespace Cesa\Waste\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Panel;
use Webkul\PluginManager\Package;

class Configurations extends Cluster
{
    public static function getSlug(?Panel $panel = null): string
    {
        return 'waste/configurations';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Package::isPluginInstalled('waste');
    }

    public static function getNavigationLabel(): string
    {
        return __('waste::waste.admin.configurations');
    }

    public static function getNavigationGroup(): string
    {
        return __('admin.navigation.waste');
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
