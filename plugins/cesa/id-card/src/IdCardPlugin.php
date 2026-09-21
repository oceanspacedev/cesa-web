<?php

namespace Cesa\IdCard;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Webkul\PluginManager\Package;

class IdCardPlugin implements Plugin
{
    public function getId(): string
    {
        return 'id-card';
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

        $panel->when($panel->getId() === 'admin', function (Panel $panel): void {
            $panel->discoverResources(
                in: __DIR__.'/Filament/Resources',
                for: 'Cesa\\IdCard\\Filament\\Resources',
            );
        });
    }

    public function boot(Panel $panel): void {}
}
