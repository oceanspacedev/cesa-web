<?php

namespace Cesa\Waste\Http\Controllers;

use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteOutlet;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PublicWasteIndexController
{
    public function __invoke(): View
    {
        $brands = WasteBrand::query()
            ->where('is_active', true)
            ->with([
                'outlets' => fn (HasMany $query): HasMany => $query->where('is_active', true)->orderBy('name'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (WasteBrand $brand): array => [
                'name'    => $brand->name,
                'code'    => $brand->code,
                'outlets' => $brand->outlets->map(fn (WasteOutlet $outlet): array => [
                    'name' => $outlet->name,
                    'code' => $outlet->code,
                    'url'  => route('waste.public.form', [
                        'brand'  => Str::lower($brand->code),
                        'outlet' => $outlet->slug,
                    ]),
                ])->all(),
            ])->all();

        return view('waste::public.index', [
            'brands'     => $brands,
            'showSearch' => collect($brands)->sum(fn (array $brand): int => count($brand['outlets'])) >= 15,
        ]);
    }
}
