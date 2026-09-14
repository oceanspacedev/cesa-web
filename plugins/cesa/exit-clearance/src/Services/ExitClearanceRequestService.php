<?php

namespace Cesa\ExitClearance\Services;

use Cesa\ExitClearance\Models\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ExitClearanceRequestService
{
    public const FORM_STATUS_PENDING = 'Pending';

    public const FORM_STATUS_APPROVED = 'Approved';

    public const FORM_STATUS_REJECTED = 'Rejected';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    public const APPROVAL_WAITING = 'waiting';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPublicRequest(array $payload): Request
    {
        return DB::transaction(function () use ($payload): Request {
            $payload['request_date'] = $payload['request_date'] ?? now()->toDateString();
            $payload['form_uid'] = $payload['form_uid'] ?? $this->generateFormUid();
            $payload['form_response_id'] = $payload['form_response_id'] ?? (string) Str::uuid();
            $payload['form_status'] = $payload['form_status'] ?? self::FORM_STATUS_PENDING;

            $request = Request::create($payload);
            $request->loadMissing('department.approvers');

            $approvers = $request->department?->approvers ?? collect();

            if ($approvers->isNotEmpty()) {
                $attachData = [];

                foreach ($approvers as $approver) {
                    $attachData[$approver->id] = [
                        'status'      => self::APPROVAL_PENDING,
                        'notes'       => null,
                        'approved_at' => null,
                    ];
                }

                $request->approvers()->sync($attachData);
            }

            return $request->refresh();
        });
    }

    public function generateFormUid(): string
    {
        return DB::transaction(function (): string {
            $latestUid = Request::query()
                ->withTrashed()
                ->whereNotNull('form_uid')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('form_uid');

            $number = 0;

            if (is_string($latestUid)) {
                $digits = preg_replace('/[^0-9]/', '', $latestUid);
                $number = is_string($digits) && $digits !== '' ? (int) $digits : 0;
            }

            $nextNumber = $number + 1;

            return sprintf('EXC-%05d', $nextNumber);
        });
    }

    /**
     * @return array<int, array{label: string, value: string|null, type?: string}>
     */
    public function buildSummary(Request $request): array
    {
        $request->loadMissing(['company', 'department']);

        $resignationUrl = $this->resolveResignationLetterUrl($request);

        return [
            ['label' => 'UID', 'value' => $request->form_uid],
            ['label' => 'Status', 'value' => $this->formatFormStatus($request->form_status)],
            ['label' => 'Nama Lengkap', 'value' => $request->name],
            ['label' => 'Email', 'value' => $request->email],
            ['label' => 'No HP', 'value' => $request->phone],
            ['label' => 'Posisi Pekerjaan', 'value' => $request->position],
            ['label' => __('exit-clearance::filament/resources/request.fields.company'), 'value' => $request->company?->name ?? '—'],
            ['label' => 'Divisi', 'value' => $request->department?->name],
            ['label' => 'Penempatan', 'value' => $request->placement],
            ['label' => 'Tanggal Masuk Kerja', 'value' => $this->formatDate($request->join_date)],
            ['label' => 'Tanggal Pengajuan', 'value' => $this->formatDate($request->request_date)],
            ['label' => 'Tanggal Keluar Kerja', 'value' => $this->formatDate($request->departure_date)],
            ['label' => '1. Alasan mengajukan pengunduran diri', 'value' => $request->reason],
            ['label' => '2. Beban pekerjaan', 'value' => $request->workload_feedback],
            ['label' => '3. Jenjang karir', 'value' => $request->career_growth_feedback],
            ['label' => '4. Fasilitas dan kesejahteraan', 'value' => $request->facility_welfare_feedback],
            ['label' => '5. Hubungan kerja', 'value' => $request->work_relationship_feedback],
            ['label' => '6. Imbalan yang diterima', 'value' => $request->compensation_feedback],
            ['label' => '7. Masukan untuk divisi', 'value' => $request->division_feedback],
            ['label' => '8. Masukan untuk perusahaan', 'value' => $request->company_feedback],
            ['label' => '1. Kartu Halo dan tagihan', 'value' => $request->clearance_kartu_halo],
            ['label' => '2. Hutang karyawan', 'value' => $request->clearance_employee_debt],
            ['label' => '3. Pengembalian seragam dan nametag', 'value' => $request->clearance_uniform_return],
            ['label' => '4. Pengembalian kendaraan', 'value' => $request->clearance_vehicle_return],
            ['label' => '5. Pengembalian inventaris', 'value' => $request->clearance_inventory_return],
            ['label' => '6. Penonaktifan akun', 'value' => $request->clearance_account_deactivation],
            ['label' => '7. Data tagihan/piutang', 'value' => $request->clearance_receivable_data],
            ['label' => '8. Promotor internal', 'value' => $request->clearance_promotor_internal],
            ['label' => '9. Nota pending', 'value' => $request->clearance_nota_pending],
            ['label' => '10. Stock opname', 'value' => $request->clearance_stock_opname],
            ['label' => 'Surat resign', 'value' => $resignationUrl, 'type' => 'link'],
        ];
    }

    /**
     * @return array{
     *     data_diri: array<int, array{label: string, value: string|null, type?: string}>,
     *     kuesioner: array<int, array{label: string, value: string|null, type?: string}>,
     *     clearance: array<int, array{label: string, value: string|null, type?: string}>
     * }
     */
    public function buildCategorizedSummary(Request $request): array
    {
        $request->loadMissing(['company', 'department']);

        $resignationUrl = $this->resolveResignationLetterUrl($request);

        return [
            'data_diri' => [
                ['label' => 'UID', 'value' => $request->form_uid],
                ['label' => 'Status', 'value' => $this->formatFormStatus($request->form_status)],
                ['label' => 'Nama Lengkap', 'value' => $request->name],
                ['label' => 'Email', 'value' => $request->email],
                ['label' => 'No HP', 'value' => $request->phone],
                ['label' => 'Posisi Pekerjaan', 'value' => $request->position],
                ['label' => __('exit-clearance::filament/resources/request.fields.company'), 'value' => $request->company?->name ?? '—'],
                ['label' => 'Divisi', 'value' => $request->department?->name],
                ['label' => 'Penempatan', 'value' => $request->placement],
                ['label' => 'Tanggal Masuk Kerja', 'value' => $this->formatDate($request->join_date)],
                ['label' => 'Tanggal Pengajuan', 'value' => $this->formatDate($request->request_date)],
                ['label' => 'Tanggal Keluar Kerja', 'value' => $this->formatDate($request->departure_date)],
                ['label' => 'Surat resign', 'value' => $resignationUrl, 'type' => 'link'],
            ],
            'kuesioner' => [
                ['label' => '1. Alasan mengajukan pengunduran diri', 'value' => $request->reason],
                ['label' => '2. Beban pekerjaan', 'value' => $request->workload_feedback],
                ['label' => '3. Jenjang karir', 'value' => $request->career_growth_feedback],
                ['label' => '4. Fasilitas dan kesejahteraan', 'value' => $request->facility_welfare_feedback],
                ['label' => '5. Hubungan kerja', 'value' => $request->work_relationship_feedback],
                ['label' => '6. Imbalan yang diterima', 'value' => $request->compensation_feedback],
                ['label' => '7. Masukan untuk divisi', 'value' => $request->division_feedback],
                ['label' => '8. Masukan untuk perusahaan', 'value' => $request->company_feedback],
            ],
            'clearance' => [
                ['label' => '1. Kartu Halo dan tagihan', 'value' => $request->clearance_kartu_halo],
                ['label' => '2. Hutang karyawan', 'value' => $request->clearance_employee_debt],
                ['label' => '3. Pengembalian seragam dan nametag', 'value' => $request->clearance_uniform_return],
                ['label' => '4. Pengembalian kendaraan', 'value' => $request->clearance_vehicle_return],
                ['label' => '5. Pengembalian inventaris', 'value' => $request->clearance_inventory_return],
                ['label' => '6. Penonaktifan akun', 'value' => $request->clearance_account_deactivation],
                ['label' => '7. Data tagihan/piutang', 'value' => $request->clearance_receivable_data],
                ['label' => '8. Promotor internal', 'value' => $request->clearance_promotor_internal],
                ['label' => '9. Nota pending', 'value' => $request->clearance_nota_pending],
                ['label' => '10. Stock opname', 'value' => $request->clearance_stock_opname],
            ],
        ];
    }

    /**
     * @return array<int, array{approver_id: int, name: string|null, email: string|null, title: string|null, status: string, notes: string|null, approved_at: string|null}>
     */
    public function buildApprovals(Request $request): array
    {
        $request->loadMissing('approvers');

        return $request->approvers->sortBy('id')->map(function ($approver): array {
            $approvedAt = null;

            if ($approver->pivot?->approved_at) {
                try {
                    $approvedAt = Carbon::parse($approver->pivot->approved_at)->format('Y-m-d H:i');
                } catch (\Throwable $exception) {
                    $approvedAt = (string) $approver->pivot->approved_at;
                }
            }

            return [
                'approver_id' => $approver->id,
                'name'        => $approver->name,
                'email'       => $approver->email,
                'title'       => $approver->title,
                'status'      => $this->normalizeApprovalStatus($approver->pivot?->status),
                'notes'       => $approver->pivot?->notes,
                'approved_at' => $approvedAt,
            ];
        })->values()->all();
    }

    public function syncOverallStatus(Request $request): string
    {
        $request->loadMissing('approvers');

        $status = $this->resolveOverallStatus($request);

        if ($request->form_status !== $status) {
            $request->form_status = $status;
            $request->save();
        }

        return $status;
    }

    public function formatFormStatus(?string $status): string
    {
        return match ($this->normalizeFormStatus($status)) {
            'approved' => self::FORM_STATUS_APPROVED,
            'rejected' => self::FORM_STATUS_REJECTED,
            default    => self::FORM_STATUS_PENDING,
        };
    }

    public function normalizeFormStatus(?string $status): string
    {
        $status = strtolower(trim((string) $status));

        return match ($status) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            default    => 'pending',
        };
    }

    public function normalizeApprovalStatus(?string $status): string
    {
        $status = strtolower(trim((string) $status));

        if (! in_array($status, [
            self::APPROVAL_PENDING,
            self::APPROVAL_APPROVED,
            self::APPROVAL_REJECTED,
            self::APPROVAL_WAITING,
        ], true)) {
            return self::APPROVAL_PENDING;
        }

        return $status;
    }

    protected function resolveOverallStatus(Request $request): string
    {
        $statuses = $request->approvers
            ->map(fn ($approver): string => $this->normalizeApprovalStatus($approver->pivot?->status))
            ->values();

        if ($statuses->contains(self::APPROVAL_REJECTED)) {
            return self::FORM_STATUS_REJECTED;
        }

        if ($statuses->isNotEmpty() && $statuses->every(fn (string $status): bool => $status === self::APPROVAL_APPROVED)) {
            return self::FORM_STATUS_APPROVED;
        }

        return self::FORM_STATUS_PENDING;
    }

    protected function formatDate(mixed $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        if ($date instanceof Carbon) {
            return $date->format('Y-m-d');
        }

        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Throwable $exception) {
            return (string) $date;
        }
    }

    public function resolveResignationLetterUrl(Request $request): ?string
    {
        $value = $request->resignation_letter_url;

        if (! $value) {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        if (blank($request->form_response_id)) {
            return Storage::url($value);
        }

        return URL::temporarySignedRoute(
            'exit-clearance.public.attachments.download',
            now()->addMinutes(60),
            [
                'response'   => $request->form_response_id,
                'attachment' => 'resignation-letter',
            ],
        );
    }
}
