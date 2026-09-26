<?php

namespace Cesa\Waste\Console\Commands;

use Cesa\Waste\Livewire\PublicWasteReportForm;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Services\WasteNotificationService;
use Cesa\Waste\Services\WasteQaSilentNotificationService;
use Cesa\Waste\Services\WasteWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

class ReplayWasteSeptemberQa extends Command
{
    protected $signature = 'waste:qa-replay-september
        {--source-dir= : Folder workbook Excel sumber untuk membaca kolom USER}
        {--brand= : JCHICKEN, LUUCA, atau MOMOYO; default ketiganya}
        {--limit= : Batasi jumlah baris sumber yang diperiksa}
        {--dry-run : Periksa master dan laporan tanpa menyimpan transaksi}';

    protected $description = 'Kirim baris Excel September 2026 lewat form publik ke database lokal untuk QA.';

    private const PERIOD = '2026-09';

    private const QA_PHONE = '0000000000';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('Replay QA hanya tersedia di lingkungan local atau testing.');

            return self::FAILURE;
        }

        try {
            $source = json_decode(
                file_get_contents(dirname(__DIR__, 3).'/tests/Fixtures/september_2026_source_replay.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            if (($source['period'] ?? null) !== self::PERIOD) {
                throw new RuntimeException('Periode fixture QA tidak sesuai.');
            }

            $selected = $this->selectRows($source['brands'] ?? []);
            $reporterNames = $this->reporterNames($selected);
            [$new, $existing, $blocked] = $this->preflight($selected, $reporterNames);

            $this->components->info(sprintf(
                'Preflight: %d baris sumber, %d siap dikirim, %d sudah ada, %d terhalang.',
                count($selected), count($new), count($existing), count($blocked),
            ));
            foreach ($blocked as $message) {
                $this->components->warn($message);
            }
            if ($blocked !== []) {
                $this->components->error('Perbaiki master atau setujui kandidat satuan satu per satu. Tidak ada baris baru yang dikirim.');

                return self::FAILURE;
            }
            if ($this->option('dry-run')) {
                $this->components->info('Dry run selesai. Database transaksi tidak diubah.');

                return self::SUCCESS;
            }

            $originalNotifications = app(WasteNotificationService::class);
            app()->instance(WasteNotificationService::class, new WasteQaSilentNotificationService);

            try {
                config(['waste.submissions.max_attempts' => max(10000, count($new) + 100)]);

                foreach ($new as $plan) {
                    $this->submitPlan($plan);
                }
                foreach ($existing as $plan) {
                    $this->markExisting($plan);
                }
            } finally {
                app()->instance(WasteNotificationService::class, $originalNotifications);
            }

            $this->components->info(sprintf(
                'Selesai: %d laporan TPS baru, %d laporan QA dilewati. Laporan baru menunggu review MIS.',
                count($new), count($existing),
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $fixtures
     * @return array<int, array{brand_code: string, fixture: array<string, mixed>, row: array<string, mixed>}>
     */
    private function selectRows(array $fixtures): array
    {
        $brandOption = strtoupper(trim((string) $this->option('brand')));
        if ($brandOption !== '' && ! in_array($brandOption, ['JCHICKEN', 'LUUCA', 'MOMOYO'], true)) {
            throw new RuntimeException('--brand harus JCHICKEN, LUUCA, atau MOMOYO.');
        }

        $limitOption = $this->option('limit');
        if ($limitOption !== null && (! ctype_digit((string) $limitOption) || (int) $limitOption < 1)) {
            throw new RuntimeException('--limit harus bilangan bulat positif.');
        }
        $limit = $limitOption === null ? null : (int) $limitOption;

        $selected = [];
        foreach ($brandOption !== '' ? [$brandOption] : ['JCHICKEN', 'LUUCA', 'MOMOYO'] as $brandCode) {
            $fixture = $fixtures[$brandCode] ?? null;
            if (! is_array($fixture)) {
                throw new RuntimeException("Fixture {$brandCode} tidak tersedia.");
            }
            foreach ($fixture['rows'] ?? [] as $row) {
                $selected[] = ['brand_code' => $brandCode, 'fixture' => $fixture, 'row' => $row];
                if ($limit !== null && count($selected) >= $limit) {
                    return $selected;
                }
            }
        }
        if ($selected === []) {
            throw new RuntimeException('Fixture QA tidak memiliki baris sumber.');
        }

        return $selected;
    }

    /**
     * @param  array<int, array{brand_code: string, fixture: array<string, mixed>, row: array<string, mixed>}>  $selected
     * @return array<string, array<int, string>>
     */
    private function reporterNames(array $selected): array
    {
        $needed = [];
        foreach ($selected as $entry) {
            if ($entry['brand_code'] !== 'MOMOYO') {
                $needed[$entry['brand_code']]['file'] = $entry['fixture']['source_file'];
                $needed[$entry['brand_code']]['rows'][] = (int) $entry['row']['source_row'];
            }
        }

        $sourceDirectory = trim((string) $this->option('source-dir'));
        if ($needed !== [] && ($sourceDirectory === '' || ! is_dir($sourceDirectory))) {
            throw new RuntimeException('Isi --source-dir dengan folder workbook Excel sumber yang dapat dibaca.');
        }

        $names = [];
        foreach ($needed as $brandCode => $source) {
            $file = rtrim($sourceDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$source['file'];
            if (! is_file($file)) {
                throw new RuntimeException("Workbook sumber {$brandCode} tidak ditemukan dalam --source-dir.");
            }

            $reader = IOFactory::createReaderForFile($file);
            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly('SEPTEMBER 26');
            $workbook = $reader->load($file);
            try {
                $sheet = $workbook->getSheetByName('SEPTEMBER 26');
                if (! $sheet) {
                    throw new RuntimeException("Sheet SEPTEMBER 26 tidak ditemukan untuk {$brandCode}.");
                }
                foreach (array_unique($source['rows']) as $sourceRow) {
                    $name = trim((string) $sheet->getCell("H{$sourceRow}")->getValue());
                    $names[$brandCode][$sourceRow] = $name !== '' ? $name : 'Petugas QA TPS (USER Excel kosong)';
                }
            } finally {
                $workbook->disconnectWorksheets();
            }
        }
        foreach ($selected as $entry) {
            if ($entry['brand_code'] === 'MOMOYO') {
                $names['MOMOYO'][(int) $entry['row']['source_row']] = 'Petugas QA TPS (tanpa kolom USER)';
            }
        }

        return $names;
    }

    /**
     * @param  array<int, array{brand_code: string, fixture: array<string, mixed>, row: array<string, mixed>}>  $selected
     * @param  array<string, array<int, string>>  $reporterNames
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<int, string>}
     */
    private function preflight(array $selected, array $reporterNames): array
    {
        $catalogs = [];
        $blocked = [];
        foreach (array_unique(array_column($selected, 'brand_code')) as $brandCode) {
            $brand = WasteBrand::query()->where('code', $brandCode)->where('is_active', true)->first();
            $outlet = $brand?->outlets()
                ->where('slug', strtolower($brandCode).'-ciledug')
                ->where('is_active', true)
                ->first();
            if (! $brand || ! $outlet) {
                $blocked[] = "{$brandCode}: brand atau outlet Ciledug aktif tidak ditemukan.";

                continue;
            }
            if (app(WasteWorkflowService::class)->resolve($brand, $outlet)) {
                $blocked[] = "{$brandCode}: workflow eksternal aktif; replay QA hanya untuk alur TPS ke MIS.";

                continue;
            }
            $catalogs[$brandCode] = [
                'brand'      => $brand,
                'outlet'     => $outlet,
                'items'      => $brand->items()->with('alternateUnits')->get()->keyBy('code'),
                'categories' => $brand->categories()->get()->keyBy(fn (WasteCategory $category): string => WasteCategory::normalizedName($category->name)),
                'sections'   => $brand->sections()->where('is_active', true)->pluck('name')->all(),
            ];
        }

        $new = [];
        $existing = [];
        foreach ($selected as $entry) {
            $brandCode = $entry['brand_code'];
            if (! isset($catalogs[$brandCode])) {
                continue;
            }
            $catalog = $catalogs[$brandCode];
            $row = $entry['row'];
            $label = "{$brandCode} baris {$row['source_row']}";
            $item = $catalog['items']->get($row['item_code']);
            $pipItem = isset($row['pip_code']) ? $catalog['items']->get($row['pip_code']) : null;
            $category = $catalog['categories']->get($row['category']);
            if (! $item || ! $item->is_active || blank($item->unit)) {
                $blocked[] = "{$label}: barang {$row['item_code']} tidak aktif atau tanpa satuan.";

                continue;
            }
            if (! $category || ! $category->is_active) {
                $blocked[] = "{$label}: kategori sumber tidak aktif atau tidak ditemukan.";

                continue;
            }
            if (isset($row['pip_code']) && (! $pipItem || ! $pipItem->is_active || blank($pipItem->unit))) {
                $blocked[] = "{$label}: barang referensi tidak aktif atau tidak ditemukan.";

                continue;
            }
            if (filled($row['section'] ?? null) && ! in_array($row['section'], $catalog['sections'], true)) {
                $blocked[] = "{$label}: section sumber tidak aktif atau tidak ditemukan.";

                continue;
            }
            if ($row['unit'] !== $item->unit && ! $item->alternateUnits->contains(
                fn ($unit): bool => $unit->code === $row['unit'] && $unit->is_active,
            )) {
                $blocked[] = "{$label}: satuan {$row['unit']} untuk {$row['item_code']} belum disetujui admin.";

                continue;
            }

            $sourceRow = (int) $row['source_row'];
            $plan = [
                'brand_code'    => $brandCode,
                'source_file'   => $entry['fixture']['source_file'],
                'row'           => $row,
                'brand'         => $catalog['brand'],
                'outlet'        => $catalog['outlet'],
                'item'          => $item,
                'pip_item'      => $pipItem,
                'category'      => $category,
                'reporter_name' => $reporterNames[$brandCode][$sourceRow],
                'email'         => self::reporterEmail($brandCode, $sourceRow),
                'key'           => self::submissionKey($brandCode, $sourceRow),
            ];

            $matching = WasteReport::query()
                ->where('submission_key', $plan['key'])
                ->orWhere('reporter_email', $plan['email'])
                ->with(['latestVersion.events.lines', 'latestVersion.events.evidences'])
                ->get();
            if ($matching->count() > 1) {
                $blocked[] = "{$label}: penanda QA ditemukan pada beberapa laporan.";

                continue;
            }

            if ($report = $matching->first()) {
                try {
                    $this->verifyExisting($report, $plan);
                    $plan['report'] = $report;
                    $existing[] = $plan;
                } catch (RuntimeException $exception) {
                    $blocked[] = "{$label}: {$exception->getMessage()}";
                }
            } else {
                $new[] = $plan;
            }
        }

        return [$new, $existing, $blocked];
    }

    /** @param array<string, mixed> $plan */
    private function submitPlan(array $plan): void
    {
        $row = $plan['row'];
        $sourceRow = (int) $row['source_row'];
        $brandCode = $plan['brand_code'];
        $photo = $this->createQaPhoto($brandCode, $sourceRow);
        try {
            $event = [
                'source_event_id' => null,
                'section'         => $row['section'] ?? '',
                'category_id'     => $plan['category']->getKey(),
                'category_name'   => '',
                'reason'          => $row['reason'] ?? '',
                'pip_item_id'     => $plan['pip_item']?->getKey(),
                'pip_quantity'    => $row['pip_quantity'] ?? null,
                'lines'           => [[
                    'item_id'  => $plan['item']->getKey(),
                    'quantity' => $row['quantity'],
                    'unit'     => $row['unit'],
                ]],
            ];
            $component = Livewire::test(PublicWasteReportForm::class, [
                'brand'  => strtolower($brandCode),
                'outlet' => $plan['outlet']->slug,
            ])
                ->set('data.event_date', $row['date'])
                ->set('data.reporter_name', $plan['reporter_name'])
                ->set('data.reporter_phone', self::QA_PHONE)
                ->set('data.reporter_email', $plan['email'])
                ->call('nextStep')
                ->assertHasNoErrors()
                ->set('data.events', [$event])
                ->set('photos.0.0', UploadedFile::fake()->createWithContent(
                    "QA-SIMULASI-{$brandCode}-baris-{$sourceRow}.png",
                    file_get_contents($photo),
                ));
            $publicKey = $component->get('submissionKey');
            $component->call('submit')->assertHasNoErrors()->assertRedirect();

            $report = WasteReport::query()->where('submission_key', $publicKey)
                ->with(['latestVersion.events.lines', 'latestVersion.events.evidences'])
                ->firstOrFail();
            $this->verifyExisting($report, $plan, hash_file('sha256', $photo));
            $report->forceFill(['submission_key' => $plan['key']])->save();
            $this->addQaActivity($report, $plan);
        } finally {
            @unlink($photo);
        }
    }

    /** @param array<string, mixed> $plan */
    private function markExisting(array $plan): void
    {
        $report = $plan['report'];
        if ($report->submission_key !== $plan['key']) {
            $report->forceFill(['submission_key' => $plan['key']])->save();
        }
        $this->addQaActivity($report, $plan);
    }

    /** @param array<string, mixed> $plan */
    private function verifyExisting(WasteReport $report, array $plan, ?string $expectedPhotoHash = null): void
    {
        $row = $plan['row'];
        $version = $report->latestVersion;
        $event = $version && $version->events->count() === 1 ? $version->events->first() : null;
        $line = $event && $event->lines->count() === 1 ? $event->lines->first() : null;
        $evidence = $event && $event->evidences->count() === 1 ? $event->evidences->first() : null;
        $expectedPhotoHash ??= $this->qaPhotoHash($plan['brand_code'], (int) $row['source_row']);
        $photoDisk = Storage::disk((string) config('waste.attachments.disk', 'local'));

        if ((int) $report->brand_id !== (int) $plan['brand']->getKey()
            || (int) $report->outlet_id !== (int) $plan['outlet']->getKey()
            || $report->event_date?->format('Y-m-d') !== $row['date']
            || $report->reporter_name !== trim($plan['reporter_name'])
            || $report->reporter_phone !== self::QA_PHONE
            || $report->reporter_email !== $plan['email']
            || ! $version || (int) $version->version_number !== 1
            || ! $event || ! $line || ! $evidence
            || (int) $event->category_id !== (int) $plan['category']->getKey()
            || (string) $event->section !== (string) ($row['section'] ?? '')
            || $event->reason !== ($row['reason'] ?? $row['category'])
            || (int) $event->pip_item_id !== (int) ($plan['pip_item']?->getKey() ?? 0)
            || $this->decimal($event->pip_quantity) !== $this->decimal($row['pip_quantity'] ?? null)
            || (int) $line->item_id !== (int) $plan['item']->getKey()
            || $line->unit !== $row['unit']
            || $this->decimal($line->quantity) !== $this->decimal($row['quantity'])
            || $evidence->original_name !== "QA-SIMULASI-{$plan['brand_code']}-baris-{$row['source_row']}.png"
            || $evidence->sha256 !== $expectedPhotoHash
            || ! $photoDisk->exists($evidence->path)
            || hash('sha256', $photoDisk->get($evidence->path)) !== $expectedPhotoHash) {
            throw new RuntimeException('penanda QA terpakai pada laporan berbeda atau foto hilang.');
        }
    }

    private function qaPhotoHash(string $brandCode, int $sourceRow): string
    {
        $path = $this->createQaPhoto($brandCode, $sourceRow);
        try {
            return hash_file('sha256', $path);
        } finally {
            @unlink($path);
        }
    }

    /** @param array<string, mixed> $plan */
    private function addQaActivity(WasteReport $report, array $plan): void
    {
        if ($report->activityLogs()->where('event', 'qa_source_replay')->exists()) {
            return;
        }
        $report->activityLogs()->create([
            'version_id' => $report->latest_version_id,
            'event'      => 'qa_source_replay',
            'actor_type' => 'system',
            'metadata'   => [
                'period'      => self::PERIOD,
                'brand'       => $plan['brand_code'],
                'source_row'  => (int) $plan['row']['source_row'],
                'source_file' => $plan['source_file'],
            ],
        ]);
    }

    private function createQaPhoto(string $brandCode, int $sourceRow): string
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('Ekstensi GD diperlukan untuk membuat foto penanda QA.');
        }
        $path = tempnam(sys_get_temp_dir(), 'waste-qa-photo-');
        if ($path === false) {
            throw new RuntimeException('Gagal membuat foto QA sementara.');
        }
        $image = imagecreatetruecolor(1000, 500);
        $background = imagecolorallocate($image, 255, 246, 238);
        $red = imagecolorallocate($image, 170, 28, 28);
        $black = imagecolorallocate($image, 28, 31, 38);
        imagefill($image, 0, 0, $background);
        imagerectangle($image, 20, 20, 979, 479, $red);
        imagestring($image, 5, 60, 90, 'QA SIMULASI', $red);
        imagestring($image, 5, 60, 170, 'BUKAN FOTO BUKTI ASLI', $red);
        imagestring($image, 5, 60, 260, "{$brandCode} / BARIS EXCEL {$sourceRow}", $black);
        imagestring($image, 4, 60, 335, 'Dibuat otomatis untuk menguji alur TPS ke MIS ke Excel.', $black);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function decimal(mixed $value): ?string
    {
        return $value === null || $value === ''
            ? null
            : number_format((float) $value, 4, '.', '');
    }

    public static function submissionKey(string $brandCode, int $sourceRow): string
    {
        return hash('sha256', 'waste-qa-tps-september-2026|'.strtoupper($brandCode).'|'.$sourceRow);
    }

    public static function reporterEmail(string $brandCode, int $sourceRow): string
    {
        return 'qa-waste-202609-'.strtolower($brandCode).'-row'.$sourceRow.'@example.test';
    }
}
