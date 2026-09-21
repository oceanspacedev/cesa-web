<?php

namespace App\Http\Controllers;

use Barryvdh\Debugbar\Facades\Debugbar;
use Illuminate\View\View;
use Webkul\PluginManager\Package;

class CesaHomeController extends Controller
{
    /**
     * All registered CESA internal applications.
     *
     * @var array<int, array{key: string, name: string, description: string, url: string, color: string, icon: string, always_show: bool}>
     */
    protected array $apps = [
        [
            'key'         => 'form-transfer',
            'name'        => 'Form Transfer',
            'description' => 'Pengajuan transfer dana',
            'url'         => '/form',
            'color'       => 'blue',
            'icon'        => 'transfer',
            'always_show' => false,
        ],
        [
            'key'         => 'lead',
            'name'        => 'LEAD',
            'description' => 'Pengelolaan prospek toko',
            'url'         => '/lead',
            'color'       => 'teal',
            'icon'        => 'lead',
            'always_show' => false,
        ],
        [
            'key'         => 'rekrutmen',
            'name'        => 'Man Power',
            'description' => 'Permintaan tenaga kerja',
            'url'         => '/man-power',
            'color'       => 'purple',
            'icon'        => 'manpower',
            'always_show' => false,
        ],
        [
            'key'         => 'helpdesk',
            'name'        => 'Helpdesk',
            'description' => 'Tiket & dukungan internal',
            'url'         => 'https://helpdesk.completeselular.com/',
            'color'       => 'red',
            'icon'        => 'helpdesk',
            'always_show' => true,
        ],
        [
            'key'         => 'dnd',
            'name'        => 'DND',
            'description' => 'Aplikasi internal DND',
            'url'         => 'https://dnd.completeselular.com/',
            'color'       => 'navy',
            'icon'        => 'dnd',
            'always_show' => true,
        ],
        [
            'key'         => 'sumo',
            'name'        => 'SUMO',
            'description' => 'Aplikasi internal SUMO',
            'url'         => 'https://sumo.completeselular.com/',
            'color'       => 'pink',
            'icon'        => 'sumo',
            'always_show' => true,
        ],
        [
            'key'         => 'exit-clearance',
            'name'        => 'Exit Clearance',
            'description' => 'Proses offboarding karyawan',
            'url'         => '/exit-clearance',
            'color'       => 'amber',
            'icon'        => 'exit',
            'always_show' => false,
        ],
        [
            'key'         => 'shelf',
            'name'        => 'Shelf',
            'description' => 'Pengelolaan rak & inventori',
            'url'         => 'https://shelf.completeselular.com/',
            'color'       => 'indigo',
            'icon'        => 'shelf',
            'always_show' => true,
        ],
        [
            'key'         => 'odoo',
            'name'        => 'Odoo',
            'description' => 'Sistem ERP Odoo',
            'url'         => 'https://odoo.completeselular.com/',
            'color'       => 'purple',
            'icon'        => 'odoo',
            'always_show' => true,
        ],
        [
            'key'         => 'sam',
            'name'        => 'SAM',
            'description' => 'Sistem Aktivasi & Monitoring',
            'url'         => 'https://sam.mediaselularindonesia.com/',
            'color'       => 'orange',
            'icon'        => 'sam',
            'always_show' => true,
        ],
    ];

    /**
     * Display the CESA app launcher home page.
     */
    public function index(): View
    {
        if (class_exists(Debugbar::class)) {
            Debugbar::disable();
        }

        $visibleApps = collect($this->apps)
            ->filter(fn (array $app) => $app['always_show'] || ! str_starts_with($app['url'], 'http') || Package::isPluginInstalled($app['key']))
            ->values()
            ->toArray();

        if (Package::isPluginInstalled('id-card')) {
            $visibleApps[] = [
                'key'         => 'id-card',
                'name'        => 'ID Card',
                'description' => 'Pembuatan ID card Sales dan Kurir',
                'url'         => route('id-card.public.form'),
                'color'       => 'blue',
                'icon'        => 'employee',
                'always_show' => false,
            ];
        }

        return view('cesa-home', [
            'apps' => $visibleApps,
        ]);
    }
}
