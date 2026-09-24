<?php

use Cesa\Waste\Filament\Pages\WasteDashboard;
use Cesa\Waste\Filament\Widgets\WasteDashboardCountTable;
use Cesa\Waste\Filament\Widgets\WasteDashboardDailyTable;
use Cesa\Waste\Filament\Widgets\WasteDashboardSummaryTable;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Services\WasteDashboardStats;
use Database\Factories\UserFactory;
use Filament\Forms\Components\Select;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function (): void {
    Route::get('/_test/waste-reports', fn (): string => '')->name('filament.admin.resources.waste-reports.index');
});

it('groups separate submissions by calendar day and month within the manager access scope', function (): void {
    $user = UserFactory::new()->createQuietly();
    $momoyo = WasteBrand::query()->create(['name' => 'Momoyo', 'code' => 'MOMOYO', 'is_active' => true]);
    $jchicken = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $momoyo->users()->attach($user);
    $momoyoOutlet = WasteOutlet::query()->create(['brand_id' => $momoyo->id, 'name' => 'Ciledug', 'code' => 'CILEDUG', 'slug' => 'momoyo-ciledug']);
    $jchickenOutlet = WasteOutlet::query()->create(['brand_id' => $jchicken->id, 'name' => 'Ciledug', 'code' => 'CILEDUG', 'slug' => 'jchicken-ciledug']);

    createDashboardReport($momoyo, $momoyoOutlet, '2026-09-01', 'pending');
    createDashboardReport($momoyo, $momoyoOutlet, '2026-09-01', 'pending');
    createDashboardReport($momoyo, $momoyoOutlet, '2026-09-30', 'approved');
    createDashboardReport($momoyo, $momoyoOutlet, '2026-10-01', 'rejected');
    createDashboardReport($jchicken, $jchickenOutlet, '2026-09-01', 'approved');

    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $dashboard = Livewire::test(WasteDashboard::class)
        ->set('filters', ['month' => '9', 'year' => '2026'])
        ->assertSee(__('waste::waste.admin.dashboard_page.month'))
        ->assertDontSee('Laporan dari form publik menunggu pemeriksaan MIS')
        ->assertDontSee('Antrean approval aktif');
    $stats = $dashboard->instance()->getStatsProperty();
    $firstDay = $stats['daily'][0];
    $lastDay = $stats['daily'][29];

    expect($stats['from'])->toBe('2026-09-01')
        ->and($stats['to'])->toBe('2026-09-30')
        ->and($stats['total'])->toBe(3)
        ->and($stats['approval_queue'])->toBe(2)
        ->and($stats['statuses'])->toBe(['pending' => 2, 'rejected' => 0, 'approved' => 1])
        ->and($stats['daily'])->toHaveCount(30)
        ->and($firstDay)->toMatchArray(['date' => '2026-09-01', 'total' => 2, 'pending' => 2, 'approved' => 0, 'rejected' => 0])
        ->and($stats['daily'][1]['total'])->toBe(0)
        ->and($lastDay)->toMatchArray(['date' => '2026-09-30', 'total' => 1, 'pending' => 0, 'approved' => 1, 'rejected' => 0]);

    parse_str((string) parse_url($dashboard->instance()->reportUrl('pending'), PHP_URL_QUERY), $filters);
    expect($filters['tableFilters'])->toMatchArray([
        'month'  => ['month' => '9', 'year' => '2026'],
        'status' => ['value' => 'pending'],
    ]);

    Livewire::test(WasteDashboardDailyTable::class, [
        'pageFilters' => ['month' => '9', 'year' => '2026'],
    ])->assertSee(__('waste::waste.admin.dashboard_page.daily', ['period' => 'September 2026']));

    Livewire::test(WasteDashboardSummaryTable::class, [
        'pageFilters' => ['month' => '9', 'year' => '2026'],
    ])->assertSee(__('waste::waste.admin.dashboard_page.empty_summary'));

    $dashboard->set('filters', ['month' => '10', 'year' => '2026']);
    expect($dashboard->instance()->getStatsProperty()['total'])->toBe(1);
});

it('uses the actual month length including leap years', function (): void {
    $user = UserFactory::new()->createQuietly();
    $brand = WasteBrand::query()->create(['name' => 'Momoyo', 'code' => 'MOMOYO', 'is_active' => true]);
    $brand->users()->attach($user);
    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $dashboard = Livewire::test(WasteDashboard::class);
    $now = now('Asia/Jakarta');
    $years = collect(app(WasteDashboardStats::class)->yearOptions())->keys()->map(fn (string $year): int => (int) $year);
    $leapYear = $years->first(fn (int $year): bool => Carbon::create($year)->isLeapYear());
    $commonYear = $years->first(fn (int $year): bool => ! Carbon::create($year)->isLeapYear());

    expect((int) $dashboard->get('filters.month'))->toBe($now->month)
        ->and((int) $dashboard->get('filters.year'))->toBe($now->year)
        ->and(app(WasteDashboardStats::class)->yearOptions())->toHaveKey((string) $now->year)
        ->and(app(WasteDashboardStats::class)->yearOptions())->not->toHaveKey('2008')
        ->and($dashboard->instance()->getFiltersForm()->getComponent('year'))->toBeInstanceOf(Select::class)
        ->and($leapYear)->not->toBeNull()
        ->and($commonYear)->not->toBeNull();

    $dashboard->set('filters', ['month' => '2', 'year' => (string) $leapYear]);

    expect($dashboard->instance()->getStatsProperty()['daily'])->toHaveCount(29)
        ->and($dashboard->instance()->getStatsProperty()['to'])->toBe(sprintf('%d-02-29', $leapYear));

    $dashboard->set('filters', ['month' => '2', 'year' => (string) $commonYear]);

    expect($dashboard->instance()->getStatsProperty()['daily'])->toHaveCount(28)
        ->and($dashboard->instance()->getStatsProperty()['to'])->toBe(sprintf('%d-02-28', $commonYear));
});

it('replaces a year outside the select with the current year', function (): void {
    $user = UserFactory::new()->createQuietly();
    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $year = now('Asia/Jakarta')->year;
    $stats = app(WasteDashboardStats::class);

    expect($stats->normalizeFilters(['month' => '12', 'year' => '2008']))->toBe([
        'month' => '12',
        'year'  => (string) $year,
    ])->and($stats->period(12, 2008)[0]->toDateString())->toBe($year.'-12-01');
});

it('keeps same-named outlets separate across brands in the monthly summary', function (): void {
    $user = UserFactory::new()->createQuietly();
    $momoyo = WasteBrand::query()->create(['name' => 'Momoyo', 'code' => 'MOMOYO', 'is_active' => true]);
    $jchicken = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $momoyo->users()->attach($user);
    $jchicken->users()->attach($user);
    $momoyoOutlet = WasteOutlet::query()->create(['brand_id' => $momoyo->id, 'name' => 'Ciledug', 'code' => 'CILEDUG', 'slug' => 'momoyo-ciledug']);
    $jchickenOutlet = WasteOutlet::query()->create(['brand_id' => $jchicken->id, 'name' => 'Ciledug', 'code' => 'CILEDUG', 'slug' => 'jchicken-ciledug']);
    createDashboardReport($momoyo, $momoyoOutlet, '2026-09-01', 'approved');
    createDashboardReport($jchicken, $jchickenOutlet, '2026-09-02', 'approved');

    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $stats = Livewire::test(WasteDashboard::class)
        ->set('filters', ['month' => '9', 'year' => '2026'])
        ->instance()
        ->getStatsProperty();

    expect($stats['by_outlet'])->toEqualCanonicalizing([
        'Momoyo / Ciledug'   => 1,
        'Jchicken / Ciledug' => 1,
    ]);

    Livewire::test(WasteDashboardCountTable::class, [
        'group'       => 'outlet',
        'pageFilters' => ['month' => '9', 'year' => '2026'],
    ])->assertSee('Momoyo / Ciledug')
        ->assertSee('Jchicken / Ciledug');
});

function createDashboardReport(WasteBrand $brand, WasteOutlet $outlet, string $date, string $status): WasteReport
{
    return WasteReport::query()->create([
        'uid'            => (string) str()->uuid(),
        'brand_id'       => $brand->id,
        'outlet_id'      => $outlet->id,
        'event_date'     => $date,
        'reporter_name'  => 'Pelapor',
        'reporter_phone' => '081234567890',
        'status'         => $status,
    ]);
}
