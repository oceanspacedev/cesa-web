<?php

namespace Cesa\Waste\Services;

use Cesa\Waste\Enums\WasteApprovalStatus;
use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Models\WasteApproval;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteEvent;
use Cesa\Waste\Models\WasteEventLine;
use Cesa\Waste\Models\WasteEvidence;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteUnit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WasteReportService
{
    public function __construct(protected WasteWorkflowService $workflowService) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<int, UploadedFile>>  $photos
     * @return array{report: WasteReport, progress_token: string, manage_token: string, approval_tokens: array<int, string>}
     */
    public function submit(WasteBrand $brand, WasteOutlet $outlet, array $data, array $photos = []): array
    {
        return $this->persist($brand, $outlet, $data, $photos, null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<int, UploadedFile>>  $photos
     * @return array{report: WasteReport, progress_token: string, manage_token: string, approval_tokens: array<int, string>}
     */
    public function revise(WasteReport $report, array $data, array $photos = []): array
    {
        if ($report->status !== WasteReportStatus::Rejected) {
            throw ValidationException::withMessages([
                'report' => 'Laporan hanya dapat direvisi setelah ditolak.',
            ]);
        }

        return $this->persist($report->brand, $report->outlet, $data, $photos, $report);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveByAdmin(array $data, ?WasteReport $report, ?object $user): WasteReport
    {
        $brand = WasteBrand::query()->find($data['brand_id'] ?? null);
        $outlet = WasteOutlet::query()->find($data['outlet_id'] ?? null);

        if (! $brand || ! $outlet || (int) $outlet->brand_id !== (int) $brand->getKey()) {
            throw ValidationException::withMessages([
                'outlet_id' => 'Brand atau outlet tidak sesuai.',
            ]);
        }

        if (! $report && (! $brand->is_active || ! $outlet->is_active)) {
            throw ValidationException::withMessages([
                'outlet_id' => 'Brand atau outlet tidak aktif.',
            ]);
        }

        if ($user && ! app(WasteAccessService::class)->canManageOutlet($user, $outlet)) {
            throw ValidationException::withMessages([
                'outlet_id' => 'Outlet ini tidak dapat dikelola.',
            ]);
        }

        $canMarkReviewFlags = ! $user || app(WasteAccessService::class)->canManageBrand($user, $brand);

        $eventDate = $data['event_date'] ?? null;
        if ($eventDate instanceof \DateTimeInterface) {
            $eventDate = $eventDate->format('Y-m-d');
        }

        validator([
            'event_date'     => $eventDate,
            'reporter_name'  => $data['reporter_name'] ?? null,
            'reporter_phone' => $data['reporter_phone'] ?? null,
            'reporter_email' => $data['reporter_email'] ?? null,
            'events'         => $data['events'] ?? null,
        ], [
            'event_date'     => ['required', 'date_format:Y-m-d'],
            'reporter_name'  => ['required', 'string', 'max:255'],
            'reporter_phone' => ['required', 'string', 'max:40'],
            'reporter_email' => ['nullable', 'email', 'max:255'],
            'events'         => ['required', 'array', 'min:1'],
        ])->validate();

        $eventData = $this->validateEventData($brand, $data['events']);
        $creating = ! $report?->exists;

        return DB::transaction(function () use ($brand, $outlet, $data, $report, $user, $eventDate, $eventData, $creating, $canMarkReviewFlags): WasteReport {
            $report = $report?->exists
                ? WasteReport::query()->whereKey($report->getKey())->lockForUpdate()->firstOrFail()
                : new WasteReport;
            if ($report->exists && (int) $report->brand_id !== (int) $brand->getKey()) {
                throw ValidationException::withMessages([
                    'brand_id' => 'Brand laporan yang sudah dikirim tidak dapat diubah.',
                ]);
            }

            if ($report->exists && $user && ! app(WasteAccessService::class)->canManageOutlet($user, $report->outlet)) {
                throw ValidationException::withMessages([
                    'outlet_id' => 'Laporan ini tidak dapat dikelola.',
                ]);
            }

            $previousVersion = $report->exists ? $report->latestVersion : null;
            $requiresEvidence = $report->exists && $report->activityLogs()->where('event', 'submitted')->exists();
            if ($previousVersion?->approvals()->exists()) {
                throw ValidationException::withMessages([
                    'report' => 'Laporan dengan alur approval eksternal tidak dapat diubah dari panel admin.',
                ]);
            }

            $reopening = $report->exists && $report->status !== WasteReportStatus::Pending;
            $reportContextChanged = $report->exists && (
                (int) $report->outlet_id !== (int) $outlet->getKey()
                || $report->event_date?->format('Y-m-d') !== $eventDate
                || $report->reporter_name !== trim((string) $data['reporter_name'])
                || $report->reporter_phone !== trim((string) $data['reporter_phone'])
                || (string) $report->reporter_email !== trim((string) ($data['reporter_email'] ?? ''))
            );
            $status = WasteReportStatus::Pending;

            $report->fill([
                'brand_id'          => $brand->getKey(),
                'outlet_id'         => $outlet->getKey(),
                'event_date'        => $eventDate,
                'reporter_name'     => trim((string) $data['reporter_name']),
                'reporter_phone'    => trim((string) $data['reporter_phone']),
                'reporter_email'    => filled($data['reporter_email'] ?? null) ? trim((string) $data['reporter_email']) : null,
                'status'            => $status,
                'submitted_at'      => $report->submitted_at ?? now(),
                'approved_at'       => null,
                'rejected_at'       => null,
                'manage_token_hash' => $reopening ? null : $report->manage_token_hash,
            ]);

            if (! $report->exists) {
                $report->uid = (string) Str::uuid();
                $report->token_version = 1;
            }

            $report->save();

            $version = $previousVersion;
            if (! $version || $reopening) {
                $version = $report->versions()->create([
                    'version_number'    => ((int) $report->versions()->max('version_number')) + 1,
                    'status'            => $status,
                    'workflow_snapshot' => [],
                ]);
                $report->forceFill(['latest_version_id' => $version->getKey()])->save();
            } else {
                $version->forceFill(['status' => $status, 'rejection_reason' => null])->save();
            }

            $existingEvents = ($reopening ? $previousVersion : $version)?->events()->with(['lines', 'evidences'])->get()->keyBy('id') ?? collect();
            $submittedEvents = array_values($data['events']);
            $matchedEvents = [];
            $matchedLines = [];
            $usedEventIds = [];

            foreach ($eventData as $sequence => $event) {
                $sourceEventId = $this->adminSourceId($submittedEvents[$sequence]['id'] ?? null, $existingEvents, $usedEventIds, "events.{$sequence}.id");
                $sourceEvent = $sourceEventId ? $existingEvents->get($sourceEventId) : null;
                $matchedEvents[$sequence] = $sourceEvent;
                $existingLines = $sourceEvent?->lines->keyBy('id') ?? collect();
                $usedLineIds = [];

                foreach ($event['lines'] as $lineIndex => $line) {
                    $submittedLine = array_values($submittedEvents[$sequence]['lines'] ?? [])[$lineIndex] ?? [];
                    $sourceLineId = $this->adminSourceId($submittedLine['id'] ?? null, $existingLines, $usedLineIds, "events.{$sequence}.lines.{$lineIndex}.id");
                    $matchedLines[$sequence][$lineIndex] = $sourceLineId ? $existingLines->get($sourceLineId) : null;
                }
            }

            if (! $reopening && $existingEvents->isNotEmpty()) {
                $temporarySequence = ((int) $existingEvents->max('sequence')) + count($eventData) + 1;
                foreach ($existingEvents as $existingEvent) {
                    $existingEvent->update(['sequence' => $temporarySequence++]);
                }
            }

            $keptEventIds = [];
            foreach ($eventData as $sequence => $event) {
                $sourceEvent = $matchedEvents[$sequence];
                $eventModel = $reopening ? null : $sourceEvent;
                $eventContextChanged = false;
                if ($sourceEvent) {
                    foreach (['section', 'category_id', 'category_name', 'reason', 'pip_item_id', 'pip_item_code', 'pip_item_name', 'pip_unit', 'pip_quantity'] as $field) {
                        if ((string) $sourceEvent->{$field} !== (string) ($event['event'][$field] ?? null)) {
                            $eventContextChanged = true;

                            break;
                        }
                    }
                }

                if ($eventModel) {
                    $eventModel->update($event['event']);
                } else {
                    $eventModel = $version->events()->create($event['event']);

                    if ($reopening && $sourceEvent) {
                        foreach ($sourceEvent->evidences as $evidence) {
                            $eventModel->evidences()->create($evidence->only([
                                'path', 'original_name', 'mime_type', 'size', 'sha256',
                            ]));
                        }
                    }
                }

                $keptLineIds = [];
                foreach ($event['lines'] as $lineIndex => $line) {
                    $submittedLine = array_values($submittedEvents[$sequence]['lines'] ?? [])[$lineIndex] ?? [];
                    $existingLine = $matchedLines[$sequence][$lineIndex];
                    $sameItem = $existingLine && (int) $existingLine->item_id === (int) $line['item_id'];
                    $sameLine = $sameItem
                        && $existingLine->unit === $line['unit']
                        && (string) $existingLine->quantity === $line['quantity'];
                    $requiresNewReview = $reopening || $reportContextChanged || $eventContextChanged || ($existingLine && ! $sameLine);

                    if (! $canMarkReviewFlags && (array_key_exists('sm_checked', $submittedLine) || array_key_exists('audit_checked', $submittedLine))) {
                        throw ValidationException::withMessages([
                            "events.{$sequence}.lines.{$lineIndex}" => 'Hanya pengelola brand yang dapat menandai pemeriksaan MIS.',
                        ]);
                    }

                    if (strtoupper($brand->code) === 'JCHICKEN') {
                        $line['sm_checked'] = $requiresNewReview ? null : (array_key_exists('sm_checked', $submittedLine)
                            ? $this->reviewFlag($submittedLine['sm_checked'], "events.{$sequence}.lines.{$lineIndex}.sm_checked")
                            : ($sameItem ? $existingLine->sm_checked : null));
                    }

                    if (in_array(strtoupper($brand->code), ['JCHICKEN', 'LUUCA'], true)) {
                        $line['audit_checked'] = $requiresNewReview ? null : (array_key_exists('audit_checked', $submittedLine)
                            ? $this->reviewFlag($submittedLine['audit_checked'], "events.{$sequence}.lines.{$lineIndex}.audit_checked")
                            : ($sameItem ? $existingLine->audit_checked : null));
                    }

                    if ($sameItem && $existingLine->unit === $line['unit'] && filled($existingLine->unit_label)) {
                        $line['unit_label'] = $existingLine->unit_label;
                    }

                    $lineModel = $reopening ? null : $existingLine;
                    if ($lineModel) {
                        $lineModel->update($line);
                    } else {
                        $lineModel = $eventModel->lines()->create($line);
                    }

                    $keptLineIds[] = $lineModel->getKey();
                }

                $eventModel->lines()->whereNotIn('id', $keptLineIds)->delete();

                if ($requiresEvidence && $eventModel->evidences()->doesntExist()) {
                    throw ValidationException::withMessages([
                        "events.{$sequence}" => 'Kejadian dari form publik wajib tetap memiliki foto bukti.',
                    ]);
                }

                $keptEventIds[] = $eventModel->getKey();
            }

            $removedEvidencePaths = $reopening ? [] : $existingEvents
                ->except($keptEventIds)
                ->flatMap(fn (WasteEvent $event): Collection => $event->evidences->pluck('path'))
                ->all();
            $version->events()->whereNotIn('id', $keptEventIds)->delete();
            app(WasteEvidenceCleanupService::class)->deleteUnreferencedAfterCommit($removedEvidencePaths);

            $report->activityLogs()->create([
                'version_id' => $version->getKey(),
                'event'      => $creating ? 'admin_created' : 'admin_updated',
                'actor_type' => 'admin',
                'actor_id'   => $user?->getKey(),
            ]);

            return $report->fresh(['latestVersion.events.lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<int, UploadedFile>>  $photos
     * @return array{report: WasteReport, progress_token: string, manage_token: string, approval_tokens: array<int, string>}
     */
    protected function persist(
        WasteBrand $brand,
        WasteOutlet $outlet,
        array $data,
        array $photos,
        ?WasteReport $existingReport,
    ): array {
        if (! $brand->is_active || ! $outlet->is_active || (int) $outlet->brand_id !== (int) $brand->getKey()) {
            throw ValidationException::withMessages([
                'outlet' => 'Brand atau outlet tidak aktif atau tidak sesuai.',
            ]);
        }

        validator($data, [
            'event_date'     => ['required', 'date_format:Y-m-d'],
            'reporter_name'  => ['required', 'string', 'max:255'],
            'reporter_phone' => ['required', 'string', 'max:40'],
            'reporter_email' => ['nullable', 'email', 'max:255'],
            'events'         => ['required', 'array', 'min:1'],
        ])->validate();

        $workflowSnapshot = $this->workflowService->snapshotOrEmpty(
            $this->workflowService->resolve($brand, $outlet),
        );
        $requiresApproval = true;
        $progressToken = Str::random(64);
        $manageToken = Str::random(64);
        $approvalTokens = [];

        $result = DB::transaction(function () use (
            $brand,
            $outlet,
            $data,
            $photos,
            $existingReport,
            $workflowSnapshot,
            $requiresApproval,
            $progressToken,
            $manageToken,
            &$approvalTokens,
        ): array {
            $report = $existingReport
                ? WasteReport::query()->whereKey($existingReport->getKey())->lockForUpdate()->firstOrFail()
                : new WasteReport;

            if ($existingReport && $report->status !== WasteReportStatus::Rejected) {
                throw ValidationException::withMessages([
                    'report' => 'Laporan sudah diproses dan tidak dapat direvisi lagi.',
                ]);
            }

            if (! $existingReport && filled($data['submission_key'] ?? null) && WasteReport::query()->where('submission_key', $data['submission_key'])->exists()) {
                throw ValidationException::withMessages([
                    'data' => 'Pengiriman ini sudah diproses. Gunakan tautan progress yang sudah diterima.',
                ]);
            }

            $versionNumber = $report->exists
                ? ((int) $report->versions()->max('version_number')) + 1
                : 1;

            $report->fill([
                'brand_id'            => $brand->getKey(),
                'outlet_id'           => $outlet->getKey(),
                'submission_key'      => $existingReport ? $report->submission_key : ($data['submission_key'] ?? null),
                'event_date'          => $data['event_date'] ?? null,
                'reporter_name'       => trim((string) ($data['reporter_name'] ?? '')),
                'reporter_phone'      => trim((string) ($data['reporter_phone'] ?? '')),
                'reporter_email'      => filled($data['reporter_email'] ?? null) ? trim((string) $data['reporter_email']) : null,
                'status'              => $requiresApproval ? WasteReportStatus::Pending : WasteReportStatus::Approved,
                'manage_token_hash'   => $this->tokenHash($manageToken),
                'progress_token_hash' => $this->tokenHash($progressToken),
                'token_version'       => ((int) ($report->token_version ?? 0)) + 1,
                'submitted_at'        => now(),
                'rejected_at'         => null,
                'approved_at'         => $requiresApproval ? null : now(),
            ]);

            if (! $report->exists) {
                $report->uid = (string) Str::uuid();
            }

            $report->save();

            $version = $report->versions()->create([
                'version_number'    => $versionNumber,
                'status'            => $requiresApproval ? WasteReportStatus::Pending : WasteReportStatus::Approved,
                'workflow_snapshot' => $workflowSnapshot,
            ]);

            $oldEvents = $existingReport?->latestVersion?->events()->with('evidences')->get()->keyBy('id') ?? collect();
            $usedSourceEventIds = [];
            $submittedEvents = array_values($data['events'] ?? []);
            $eventData = $this->validateEventData($brand, $submittedEvents);

            foreach ($eventData as $sequence => $event) {
                $sourceEventId = $submittedEvents[$sequence]['source_event_id'] ?? null;
                $oldEvent = null;

                if (filled($sourceEventId)) {
                    $sourceEventId = (string) $sourceEventId;

                    if (! ctype_digit($sourceEventId)) {
                        throw ValidationException::withMessages([
                            "photos.{$sequence}" => 'Foto lama tidak sesuai dengan kejadian ini. Ambil foto baru.',
                        ]);
                    }

                    $sourceEventId = (int) $sourceEventId;

                    if (! $oldEvents->has($sourceEventId) || isset($usedSourceEventIds[$sourceEventId])) {
                        throw ValidationException::withMessages([
                            "photos.{$sequence}" => 'Foto lama tidak sesuai dengan kejadian ini. Ambil foto baru.',
                        ]);
                    }

                    $oldEvent = $oldEvents->get($sourceEventId);
                    $usedSourceEventIds[$sourceEventId] = true;
                }

                $eventModel = $version->events()->create($event['event']);

                foreach ($event['lines'] as $line) {
                    $eventModel->lines()->create($line);
                }

                $eventPhotos = array_values($photos[$sequence] ?? []);
                if ($eventPhotos === [] && $oldEvent) {
                    foreach ($oldEvent->evidences as $oldEvidence) {
                        $eventModel->evidences()->create($oldEvidence->only([
                            'path', 'original_name', 'mime_type', 'size', 'sha256',
                        ]));
                    }
                } else {
                    foreach ($eventPhotos as $photo) {
                        $this->storeEvidence($eventModel, $photo);
                    }
                }

                if ($eventModel->evidences()->count() < 1 || $eventModel->evidences()->count() > 5) {
                    throw ValidationException::withMessages([
                        "photos.{$sequence}" => 'Setiap kejadian wajib memiliki 1 sampai 5 foto kamera.',
                    ]);
                }
            }

            $report->forceFill(['latest_version_id' => $version->getKey()])->save();

            foreach ($workflowSnapshot as $index => $step) {
                $approvalToken = $index === 0 ? Str::random(64) : null;
                if ($approvalToken) {
                    $approvalTokens[$index] = $approvalToken;
                }
                $version->approvals()->create([
                    'step_order'     => $step['sort_order'],
                    'label'          => $step['label'],
                    'approver_name'  => $step['name'],
                    'approver_email' => $step['email'],
                    'approver_phone' => $step['phone'],
                    'token_hash'     => $approvalToken ? $this->tokenHash($approvalToken) : null,
                    'status'         => $index === 0 ? WasteApprovalStatus::Pending : WasteApprovalStatus::Waiting,
                ]);
            }

            $report->activityLogs()->create([
                'version_id' => $version->getKey(),
                'event'      => $existingReport ? 'revised' : 'submitted',
                'actor_type' => 'public',
                'metadata'   => ['version' => $versionNumber],
            ]);

            return ['report' => $report->fresh(['brand', 'outlet', 'latestVersion.approvals'])];
        });

        return [
            'report'          => $result['report'],
            'progress_token'  => $progressToken,
            'manage_token'    => $manageToken,
            'approval_tokens' => $approvalTokens,
        ];
    }

    /**
     * @param  array<int, mixed>  $events
     * @return array<int, array{event: array<string, mixed>, lines: array<int, array<string, mixed>>}>
     */
    protected function validateEventData(WasteBrand $brand, array $events): array
    {
        if ($events === []) {
            throw ValidationException::withMessages(['data.events' => 'Tambahkan minimal satu kejadian.']);
        }

        return collect($events)->values()->map(function (mixed $event, int $sequence) use ($brand): array {
            if (! is_array($event)) {
                throw ValidationException::withMessages(["data.events.{$sequence}" => 'Format kejadian tidak valid.']);
            }

            $section = $this->section($brand, $event['section'] ?? null, "data.events.{$sequence}.section");
            $category = $this->category($brand, $event['category_id'] ?? null, "data.events.{$sequence}.category_id");

            $pipItem = null;
            if (filled($event['pip_item_id'] ?? null)) {
                $pipItem = WasteItem::query()
                    ->where('brand_id', $brand->getKey())
                    ->whereKey((int) $event['pip_item_id'])
                    ->where('is_active', true)
                    ->first();

                if (! $pipItem || blank($pipItem->unit)) {
                    throw ValidationException::withMessages(["data.events.{$sequence}.pip_item_id" => 'Barang referensi tidak tersedia atau belum memiliki satuan.']);
                }

                if (Str::upper($brand->code) !== 'MOMOYO' && Str::upper(trim((string) $pipItem->item_type)) !== 'PIP') {
                    throw ValidationException::withMessages(["data.events.{$sequence}.pip_item_id" => 'Barang referensi hanya tersedia untuk Momoyo.']);
                }
            }

            $pipQuantity = filled($event['pip_quantity'] ?? null)
                ? $this->positiveDecimal($event['pip_quantity'], "data.events.{$sequence}.pip_quantity")
                : null;
            if ($pipItem && $pipQuantity === null) {
                throw ValidationException::withMessages(["data.events.{$sequence}.pip_quantity" => 'Jumlah referensi wajib diisi.']);
            }

            if (! $pipItem && $pipQuantity !== null && Str::upper($brand->code) !== 'MOMOYO') {
                throw ValidationException::withMessages(["data.events.{$sequence}.pip_quantity" => 'Jumlah tanpa referensi hanya tersedia untuk Momoyo.']);
            }

            $isPipReference = $pipItem && Str::upper(trim((string) $pipItem->item_type)) === 'PIP';

            $lines = collect($event['lines'] ?? [])->values()->map(function (mixed $line, int $lineIndex) use ($brand, $sequence, $isPipReference): array {
                if (! is_array($line)) {
                    throw ValidationException::withMessages(["data.events.{$sequence}.lines.{$lineIndex}" => 'Format barang tidak valid.']);
                }

                $item = WasteItem::query()
                    ->where('brand_id', $brand->getKey())
                    ->whereKey((int) ($line['item_id'] ?? 0))
                    ->where('is_active', true)
                    ->first();

                if (! $item || blank($item->unit)) {
                    throw ValidationException::withMessages(["data.events.{$sequence}.lines.{$lineIndex}.item_id" => 'Barang tidak tersedia atau belum memiliki satuan.']);
                }

                $quantity = $this->positiveDecimal($line['quantity'] ?? null, "data.events.{$sequence}.lines.{$lineIndex}.quantity");

                $unit = $line['unit'] ?? $item->unit;
                if (! is_string($unit) || trim($unit) === '') {
                    throw ValidationException::withMessages(["data.events.{$sequence}.lines.{$lineIndex}.unit" => 'Satuan barang wajib dipilih.']);
                }

                $unit = trim($unit);
                if ($unit !== $item->unit && ! $item->alternateUnits()->where('code', $unit)->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(["data.events.{$sequence}.lines.{$lineIndex}.unit" => 'Satuan ini belum disetujui untuk barang yang dipilih.']);
                }

                return [
                    'item_id'    => $item->getKey(),
                    'item_code'  => $item->code,
                    'item_name'  => $item->name,
                    'item_type'  => $item->item_type,
                    'unit'       => $unit,
                    'unit_label' => $unit === $item->unit && WasteUnit::normalizeCode($item->source_unit_label) === $unit
                        ? trim((string) $item->source_unit_label)
                        : $unit,
                    'quantity'  => $quantity,
                    'line_role' => $isPipReference ? 'component' : 'direct',
                ];
            })->all();

            if ($lines === []) {
                throw ValidationException::withMessages(["data.events.{$sequence}.lines" => 'Tambahkan minimal satu barang.']);
            }

            $reason = trim((string) ($event['reason'] ?? ''));
            if ($reason === '' && Str::upper($brand->code) === 'MOMOYO') {
                $reason = $category?->name ?? 'Adjustment';
            }

            if ($reason === '') {
                throw ValidationException::withMessages(["data.events.{$sequence}.reason" => 'Alasan wajib diisi.']);
            }

            return [
                'event' => [
                    'sequence'      => $sequence,
                    'section'       => $section,
                    'category_id'   => $category?->getKey(),
                    'category_name' => $category?->name ?? WasteCategory::normalizedName($event['category_name'] ?? 'Adjustment'),
                    'reason'        => $reason,
                    'pip_item_id'   => $pipItem?->getKey(),
                    'pip_item_code' => $pipItem?->code,
                    'pip_item_name' => $pipItem?->name,
                    'pip_unit'      => $pipItem?->unit,
                    'pip_quantity'  => $pipQuantity,
                ],
                'lines' => $lines,
            ];
        })->all();
    }

    protected function section(WasteBrand $brand, mixed $value, string $field): ?string
    {
        $section = trim((string) $value);
        if ($section === '') {
            return null;
        }

        $allowedSections = $brand->sections()
            ->where('is_active', true)
            ->pluck('name')
            ->all();

        if (! in_array($section, $allowedSections, true)) {
            throw ValidationException::withMessages([$field => 'Section tidak tersedia untuk brand ini.']);
        }

        return $section;
    }

    protected function category(WasteBrand $brand, mixed $categoryId, string $field): WasteCategory
    {
        if (! filled($categoryId)) {
            throw ValidationException::withMessages([$field => 'Kategori wajib dipilih.']);
        }

        $category = WasteCategory::query()
            ->where('is_active', true)
            ->where(function ($query) use ($brand): void {
                $query->where('brand_id', $brand->getKey())->orWhereNull('brand_id');
            })
            ->whereKey((int) $categoryId)
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([$field => 'Kategori tidak tersedia.']);
        }

        return $category;
    }

    protected function reviewFlag(mixed $value, string $field): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($value, [true, 1, '1'], true)) {
            return true;
        }

        if (in_array($value, [false, 0, '0'], true)) {
            return false;
        }

        throw ValidationException::withMessages([$field => 'Pilih TRUE, FALSE, atau biarkan kosong.']);
    }

    /**
     * @param  Collection<int, WasteEvent|WasteEventLine>  $existing
     * @param  array<int, bool>  $usedIds
     */
    protected function adminSourceId(mixed $value, Collection $existing, array &$usedIds, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ((! is_int($value) && ! is_string($value)) || ! ctype_digit((string) $value)) {
            throw ValidationException::withMessages([$field => 'ID kejadian atau barang tidak valid.']);
        }

        $id = (int) $value;
        if ($id < 1 || ! $existing->has($id) || isset($usedIds[$id])) {
            throw ValidationException::withMessages([$field => 'ID kejadian atau barang tidak sesuai dengan laporan ini.']);
        }

        $usedIds[$id] = true;

        return $id;
    }

    protected function positiveDecimal(mixed $value, string $field): string
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $normalized)) {
            throw ValidationException::withMessages([$field => 'Jumlah harus berupa angka dengan maksimal 4 angka desimal.']);
        }

        [$integer, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = str_pad($fraction, 4, '0');

        if (strlen($integer) > 14) {
            throw ValidationException::withMessages([$field => 'Jumlah terlalu besar. Maksimal 14 angka sebelum desimal.']);
        }

        if ($integer === '0' && $fraction === '0000') {
            throw ValidationException::withMessages([$field => 'Jumlah harus lebih besar dari nol.']);
        }

        return $integer.'.'.$fraction;
    }

    protected function storeEvidence(WasteEvent $event, UploadedFile $photo): WasteEvidence
    {
        validator(['photo' => $photo], [
            'photo' => ['required', 'image', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:'.config('waste.attachments.max_size', 5120)],
        ])->validate();

        $disk = (string) config('waste.attachments.disk', 'local');
        $path = $photo->store((string) config('waste.attachments.directory', 'waste/evidence'), $disk);

        return $event->evidences()->create([
            'path'          => $path,
            'original_name' => $photo->getClientOriginalName(),
            'mime_type'     => $photo->getMimeType(),
            'size'          => $photo->getSize(),
            'sha256'        => hash_file('sha256', $photo->getRealPath()),
        ]);
    }

    public function reportForProgressToken(string $token): WasteReport
    {
        return WasteReport::query()
            ->with(['brand', 'outlet', 'latestVersion.events.lines', 'latestVersion.events.evidences', 'latestVersion.approvals'])
            ->where('progress_token_hash', $this->tokenHash($token))
            ->firstOrFail();
    }

    public function reportForManageToken(string $token): WasteReport
    {
        return WasteReport::query()
            ->with(['brand', 'outlet', 'latestVersion.events.lines', 'latestVersion.events.evidences'])
            ->where('manage_token_hash', $this->tokenHash($token))
            ->firstOrFail();
    }

    public function approvalForToken(string $token)
    {
        return WasteApproval::query()
            ->with(['version.report.brand', 'version.report.outlet', 'version.events.lines', 'version.events.evidences', 'version.approvals'])
            ->where('token_hash', $this->tokenHash($token))
            ->firstOrFail();
    }

    public function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }
}
