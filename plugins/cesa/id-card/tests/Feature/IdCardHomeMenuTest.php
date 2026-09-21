<?php

use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;

it('shows the installed id card public form in the cesa home menu', function (): void {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertViewIs('cesa-home')
        ->assertViewHas('apps', function (array $apps): bool {
            $idCardApp = collect($apps)->firstWhere('key', 'id-card');

            return $idCardApp !== null
                && $idCardApp['url'] === route('id-card.public.form')
                && $idCardApp['name'] === 'ID Card';
        })
        ->assertSee('id="app-id-card"', false)
        ->assertSee('href="'.route('id-card.public.form').'"', false)
        ->assertSee('src="'.asset('svg/id-card.svg').'"', false)
        ->assertSee('id="app-odoo"', false);
});

it('hides the id card home menu when the plugin is not installed', function (): void {
    Plugin::query()->where('name', 'id-card')->update(['is_installed' => false]);
    Package::$plugins = [];

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertViewHas('apps', fn (array $apps): bool => ! collect($apps)->contains('key', 'id-card'))
        ->assertDontSee('id="app-id-card"', false)
        ->assertSee('id="app-odoo"', false);
});
