<?php

namespace Cesa\Waste\Console\Commands;

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Services\WasteMisReviewService;
use Cesa\Waste\Services\WasteNotificationService;
use Cesa\Waste\Services\WasteQaReviewerService;
use Cesa\Waste\Services\WasteQaSilentNotificationService;
use Cesa\Waste\Services\WasteReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

class QaReviewSeptember extends Command
{
    protected $signature = 'waste:qa-review-september
        {--brand= : JCHICKEN, LUUCA, atau MOMOYO}
        {--limit= : Batasi jumlah baris sumber untuk uji coba}
        {--dry-run : Periksa kesiapan tanpa menulis data}';

    protected $description = 'Tandai SM/AUDIT dan setujui laporan TPS QA September melalui alur MIS aplikasi.';

    public function handle(WasteQaReviewerService $reviewers, WasteReportService $reports): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->components->error('Perintah QA hanya boleh dijalankan di lingkungan lokal atau pengujian.');

            return self::FAILURE;
        }

        $selectedBrand = strtoupper(trim((string) $this->option('brand')));
        if ($selectedBrand !== '' && ! in_array($selectedBrand, ['JCHICKEN', 'LUUCA', 'MOMOYO'], true)) {
            $this->components->error('Brand harus JCHICKEN, LUUCA, atau MOMOYO.');

            return self::FAILURE;
        }

        $limitOption = $this->option('limit');
        if ($limitOption !== null && (! ctype_digit((string) $limitOption) || (int) $limitOption < 1)) {
            $this->components->error('Limit harus bilangan bulat positif.');

            return self::FAILURE;
        }

        $fixturePath = dirname(__DIR__, 3).'/tests/Fixtures/september_2026_source_replay.json';
        if (! is_readable($fixturePath)) {
            $this->components->error('Fixture QA September tidak ditemukan.');

            return self::FAILURE;
        }

        try {
            $fixture = json_decode(
                file_get_contents($fixturePath),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            $this->components->error('Fixture September tidak dapat dibaca: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (($fixture['period'] ?? null) !== '2026-09') {
            $this->components->error('Fixture QA bukan periode September 2026.');

            return self::FAILURE;
        }

        $selectedRows = [];
        foreach ($fixture['brands'] as $brandCode => $brandFixture) {
            if ($selectedBrand !== '' && $selectedBrand !== $brandCode) {
                continue;
            }

            foreach ($brandFixture['rows'] as $row) {
                $selectedRows[] = [$brandCode, $brandFixture['source_file'], $row];
                if ($limitOption !== null && count($selectedRows) >= (int) $limitOption) {
                    break 2;
                }
            }
        }

        $ready = [];
        $approved = 0;
        $errors = [];

        foreach ($selectedRows as [$brandCode, $sourceFile, $row]) {
            $report = WasteReport::query()
                ->with(['brand', 'outlet', 'latestVersion.events.lines', 'latestVersion.events.evidences'])
                ->where('submission_key', $this->submissionKey($brandCode, (int) $row['source_row']))
                ->first();

            if (! $report) {
                $errors[] = "{$brandCode} baris {$row['source_row']}: laporan QA belum ditemukan.";

                continue;
            }

            $error = $this->validateReport($report, $brandCode, $sourceFile, $row);
            if ($error !== null) {
                $errors[] = "{$brandCode} baris {$row['source_row']}: {$error}";

                continue;
            }

            if ($report->status === WasteReportStatus::Approved) {
                if (! $this->flagsMatch($report, $brandCode, $row)) {
                    $errors[] = "{$brandCode} baris {$row['source_row']}: laporan disetujui dengan penanda MIS berbeda dari sumber.";
                } else {
                    $approved++;
                }

                continue;
            }

            if ($report->status !== WasteReportStatus::Pending) {
                $errors[] = "{$brandCode} baris {$row['source_row']}: status laporan bukan pending atau approved.";

                continue;
            }

            $ready[] = [$report, $brandCode, $sourceFile, $row];
        }

        if ($errors !== []) {
            foreach (array_slice($errors, 0, 20) as $error) {
                $this->components->error($error);
            }
            $this->components->error(sprintf('%d baris bermasalah. Tidak ada laporan yang disetujui.', count($errors)));

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->components->info(sprintf('Simulasi MIS: %d siap disetujui, %d sudah disetujui, 0 perubahan.', count($ready), $approved));

            return self::SUCCESS;
        }

        if ($ready === []) {
            $this->components->info(sprintf('Tidak ada laporan yang perlu diproses; %d sudah disetujui.', $approved));

            return self::SUCCESS;
        }

        $originalNotifications = app(WasteNotificationService::class);
        app()->instance(WasteNotificationService::class, new WasteQaSilentNotificationService);

        $completed = 0;

        try {
            $reviewer = $reviewers->ensure();
            $mis = app(WasteMisReviewService::class);

            foreach ($ready as [$report, $brandCode, $sourceFile, $row]) {
                DB::transaction(function () use ($report, $brandCode, $sourceFile, $row, $reviewer, $reports, $mis): void {
                    $report = WasteReport::query()
                        ->whereKey($report->getKey())
                        ->lockForUpdate()
                        ->with(['brand', 'outlet', 'latestVersion.events.lines', 'latestVersion.events.evidences'])
                        ->firstOrFail();

                    if ($report->status !== WasteReportStatus::Pending
                        || $this->validateReport($report, $brandCode, $sourceFile, $row) !== null) {
                        throw new \RuntimeException("Laporan {$brandCode} baris {$row['source_row']} berubah setelah pemeriksaan awal.");
                    }

                    if ($brandCode !== 'MOMOYO') {
                        $report = $reports->saveByAdmin($this->reviewPayload($report, $brandCode, $row), $report, $reviewer);
                    }

                    $mis->approve($report, $reviewer);
                });
                $completed++;
            }
        } catch (Throwable $exception) {
            $this->components->error("Review MIS QA berhenti setelah {$completed} laporan: ".$exception->getMessage());

            return self::FAILURE;
        } finally {
            app()->instance(WasteNotificationService::class, $originalNotifications);
        }

        $this->components->info(sprintf('%d laporan disetujui MIS, %d sudah disetujui sebelumnya. Notifikasi keluar tidak dikirim.', $completed, $approved));

        return self::SUCCESS;
    }

    protected function submissionKey(string $brandCode, int $sourceRow): string
    {
        return hash('sha256', 'waste-qa-tps-september-2026|'.$brandCode.'|'.$sourceRow);
    }

    /** @param array<string, mixed> $row */
    protected function validateReport(WasteReport $report, string $brandCode, string $sourceFile, array $row): ?string
    {
        $version = $report->latestVersion;
        $event = $version?->events->first();
        $line = $event?->lines->first();
        $expectedEmail = 'qa-waste-202609-'.strtolower($brandCode).'-row'.$row['source_row'].'@example.test';

        if ($report->brand?->code !== $brandCode
            || $report->outlet?->slug !== strtolower($brandCode).'-ciledug'
            || $report->event_date?->format('Y-m-d') !== $row['date']
            || $report->reporter_email !== $expectedEmail
            || ! $version
            || $report->versions()->count() !== 1
            || $version->status !== $report->status
            || $version->events->count() !== 1
            || ! $event
            || $event->lines->count() !== 1
            || ! $line
            || $line->item_code !== $row['item_code']
            || $line->unit !== $row['unit']
            || number_format((float) $line->quantity, 4, '.', '') !== number_format((float) $row['quantity'], 4, '.', '')
            || $event->category_name !== $row['category']
            || (string) $event->section !== (string) ($row['section'] ?? '')
            || (isset($row['reason']) && $event->reason !== $row['reason'])
            || (string) $event->pip_item_code !== (string) ($row['pip_code'] ?? '')
            || (isset($row['pip_quantity']) && number_format((float) $event->pip_quantity, 4, '.', '') !== number_format((float) $row['pip_quantity'], 4, '.', ''))
        ) {
            return 'data laporan berbeda dari baris sumber QA.';
        }

        $sourceLog = $report->activityLogs()
            ->where('version_id', $version->getKey())
            ->where('event', 'qa_source_replay')
            ->where('actor_type', 'system')
            ->get()
            ->first(fn ($log): bool => ($log->metadata['period'] ?? null) === '2026-09'
                && ($log->metadata['brand'] ?? null) === $brandCode
                && (int) ($log->metadata['source_row'] ?? 0) === (int) $row['source_row']
                && ($log->metadata['source_file'] ?? null) === $sourceFile);

        if (! $sourceLog || ! $report->activityLogs()->where('event', 'submitted')->exists()) {
            return 'penanda asal TPS QA tidak lengkap.';
        }

        if ($event->evidences->count() !== 1
            || $event->evidences->contains(fn ($evidence): bool => blank($evidence->path)
                || ! Storage::disk((string) config('waste.attachments.disk', 'local'))->exists($evidence->path))) {
            return 'foto bukti TPS QA tidak tersedia di penyimpanan.';
        }

        if ($version->approvals()->exists()) {
            return 'laporan memakai approval eksternal; review QA otomatis dihentikan.';
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    protected function flagsMatch(WasteReport $report, string $brandCode, array $row): bool
    {
        $line = $report->latestVersion->events->sole()->lines->sole();

        return match ($brandCode) {
            'JCHICKEN' => $line->sm_checked === $row['sm'] && $line->audit_checked === $row['audit'],
            'LUUCA'    => $line->audit_checked === $row['audit'],
            default    => true,
        };
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function reviewPayload(WasteReport $report, string $brandCode, array $row): array
    {
        $event = $report->latestVersion->events->sole();
        $line = $event->lines->sole();
        $lineData = [
            'id'            => $line->getKey(),
            'item_id'       => $line->item_id,
            'quantity'      => $line->quantity,
            'unit'          => $line->unit,
            'audit_checked' => $row['audit'],
        ];

        if ($brandCode === 'JCHICKEN') {
            $lineData['sm_checked'] = $row['sm'];
        }

        return [
            'brand_id'       => $report->brand_id,
            'outlet_id'      => $report->outlet_id,
            'event_date'     => $report->event_date->format('Y-m-d'),
            'reporter_name'  => $report->reporter_name,
            'reporter_phone' => $report->reporter_phone,
            'reporter_email' => $report->reporter_email,
            'events'         => [[
                'id'           => $event->getKey(),
                'section'      => $event->section,
                'category_id'  => $event->category_id,
                'reason'       => $event->reason,
                'pip_item_id'  => $event->pip_item_id,
                'pip_quantity' => $event->pip_quantity,
                'lines'        => [$lineData],
            ]],
        ];
    }
}
