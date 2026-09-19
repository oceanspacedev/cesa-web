<?php

namespace Cesa\Rekrutmen\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Filament\Resources\JobPostingResource;
use Cesa\Rekrutmen\Filament\Resources\RequestManPowerResource;
use Cesa\Rekrutmen\Http\Requests\AiSettingsRequest;
use Cesa\Rekrutmen\Http\Requests\QueueAiScreeningRequest;
use Cesa\Rekrutmen\Http\Requests\SaveRecruitmentPipelineRequest;
use Cesa\Rekrutmen\Http\Requests\SendCandidateNotificationRequest;
use Cesa\Rekrutmen\Http\Requests\UploadCandidateCvRequest;
use Cesa\Rekrutmen\Jobs\QueueCandidateCvScreeningBatchJob;
use Cesa\Rekrutmen\Models\Approver;
use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Models\ScheduledNotification;
use Cesa\Rekrutmen\Services\AiScreeningService;
use Cesa\Rekrutmen\Services\AiSettingsService;
use Cesa\Rekrutmen\Services\RecruitmentProgressReportExport;
use Cesa\Rekrutmen\Services\RecruitmentProgressReportService;
use Cesa\Rekrutmen\Services\RekrutmenStorage;
use Cesa\Rekrutmen\Services\ScheduledNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Webkul\Support\Models\Company;

class RekrutmenSpaController extends Controller
{
    /**
     * Check and process due scheduled notifications with a 15-second throttle.
     */
    protected function checkAndProcessDueNotifications(): void
    {
        if (Cache::add('rekrutmen_scheduled_due_lock', 1, 15)) {
            try {
                app(ScheduledNotificationService::class)->processDueNotifications();
            } catch (\Throwable $e) {
                Log::warning('Auto-processing scheduled notifications failed: '.$e->getMessage());
            }
        }
    }

    /**
     * Get installed CESA plugins navigation items.
     *
     * @return array<int, array{key: string, label: string, url: string, icon: string, svg: ?string}>
     */
    public function getInstalledPlugins(): array
    {
        try {
            $panel = filament()->getPanel('admin');
            filament()->setCurrentPanel($panel);

            $navigation = filament()->getNavigation();

            return collect($navigation)->map(function ($group) {
                $label = $group->getLabel();
                $icon = $group->getIcon();
                $url = $group->getItems()->first()?->getUrl();

                if (! $label || ! $url || ! $icon) {
                    return null;
                }

                $svgName = str_replace('icon-', '', $icon);
                $svgPath = resource_path("svg/{$svgName}.svg");
                $svgContent = file_exists($svgPath) ? file_get_contents($svgPath) : null;

                return [
                    'key'   => $svgName,
                    'label' => $label,
                    'url'   => $url,
                    'icon'  => $icon,
                    'svg'   => $svgContent,
                ];
            })->filter()->values()->all();
        } catch (\Throwable $e) {
            Log::warning('Failed to load installed plugins for Rekrutmen navbar: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Get installed CESA plugins as a JSON response.
     */
    public function getInstalledPluginsApi(): JsonResponse
    {
        return response()->json($this->getInstalledPlugins());
    }

    /**
     * Render the single-page application entry view.
     */
    public function index(): View
    {
        $this->checkAndProcessDueNotifications();

        $user = auth()->user();

        return view('rekrutmen::spa', [
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'plugins' => $this->getInstalledPlugins(),
        ]);
    }

    /**
     * Get Request Man Power list formatted identically to the CESA layout.
     */
    public function getRequests(Request $request): JsonResponse
    {
        $this->checkAndProcessDueNotifications();

        $query = RequestManPower::with([
            'approver',
            'currentPendingApproval',
            'division',
            'company',
            'jobPosting.applications:id,job_posting_id,status',
            'jobPosting.requestManPowers',
            'jobPosting.rekrutmenPipeline',
        ])->latest('created_at');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('posisi_dibutuhkan', 'like', "%{$search}%")
                    ->orWhere('nama_pengaju', 'like', "%{$search}%")
                    ->orWhere('lokasi_penempatan', 'like', "%{$search}%");
            });
        }

        $records = $query->paginate($request->input('per_page', 50));

        $records->getCollection()->transform(function (RequestManPower $record) {
            $fulfillmentStatus = $record->fulfillmentStatus();
            $approvalDesc = RequestManPowerResource::formatApprovalDescription($record);
            $positionDesc = RequestManPowerResource::formatTablePositionDescription($record);
            $hiredCount = $record->hiredCandidatesCount();
            $neededCount = $record->neededHeadcount();

            return [
                'id'                         => $record->id,
                'request_number'             => $record->id,
                'nama_pengaju'               => $record->nama_pengaju,
                'posisi_pengaju'             => $record->posisi_pengaju,
                'posisi_dibutuhkan'          => $record->posisi_dibutuhkan,
                'position_name'              => $record->posisi_dibutuhkan,
                'position_title'             => $record->posisi_dibutuhkan,
                'position_description'       => $positionDesc,
                'division_name'              => $record->division?->name ?? $record->divisi ?? '-',
                'department'                 => $record->division?->name ?? $record->divisi ?? '-',
                'company_name'               => $record->company?->name ?? $record->business_entity_name ?? '-',
                'business_entity_name'       => $record->business_entity_name ?? $record->company?->name ?? '-',
                'lokasi_penempatan'          => $record->lokasi_penempatan ?? '-',
                'branch'                     => $record->lokasi_penempatan ?? '-',
                'location'                   => $record->lokasi_penempatan ?? '-',
                'status_kebutuhan'           => $record->status_kebutuhan?->getLabel() ?? (string) $record->status_kebutuhan,
                'jumlah_karyawan_dibutuhkan' => $neededCount,
                'quantity'                   => $neededCount,
                'fulfilled_count'            => $hiredCount,
                'estimasi_tanggal_join'      => $record->estimasi_tanggal_join ? $record->estimasi_tanggal_join->format('d/m/Y') : '-',
                'requirements_kualifikasi'   => $record->requirements_kualifikasi,
                'job_description'            => $record->job_description,
                'keterangan'                 => $record->keterangan,
                'fulfillment_status'         => $fulfillmentStatus ? $fulfillmentStatus->getLabel() : 'No Candidate Yet',
                'fulfillment_color'          => $fulfillmentStatus ? $fulfillmentStatus->getColor() : 'danger',
                'fulfillment_summary'        => $record->fulfillmentSummary(),
                'tanggal_pengajuan'          => $record->tanggal_pengajuan ? $record->tanggal_pengajuan->format('d/m/Y') : ($record->created_at ? $record->created_at->format('d/m/Y') : '-'),
                'submission_date'            => $record->tanggal_pengajuan ? $record->tanggal_pengajuan->format('d/m/Y') : ($record->created_at ? $record->created_at->format('d/m/Y') : '-'),
                'created_at'                 => $record->created_at ? $record->created_at->format('d/m/Y') : '-',
                'raw_status'                 => $record->status ? (is_object($record->status) ? $record->status->value : $record->status) : 'pending',
                'status'                     => $record->status ? $record->status->getLabel() : 'Pending',
                'status_color'               => $record->status ? $record->status->getColor() : 'warning',
                'approval_status'            => $record->status ? $record->status->getLabel() : 'Pending',
                'approval_description'       => $approvalDesc,
                'public_progress_url'        => $record->getPublicProgressUrl(),
                'can_approve_reject'         => in_array($record->status, [
                    RequestManPowerStatus::PENDING,
                    RequestManPowerStatus::HOLD,
                ], true),
            ];
        });

        return response()->json($records);
    }

    /**
     * Approve a manpower request manually.
     */
    public function approveRequest(Request $request, $id): JsonResponse
    {
        $record = RequestManPower::findOrFail($id);

        try {
            $record->approveBy(Auth::id());

            return response()->json([
                'success' => true,
                'message' => "Permintaan Manpower (MPP #{$record->id}) berhasil disetujui (Approved) dan lowongan dibuat!",
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to approve manpower request', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyetujui permintaan: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject a manpower request.
     */
    public function rejectRequest(Request $request, $id): JsonResponse
    {
        $record = RequestManPower::findOrFail($id);

        try {
            $record->rejectBy(Auth::id());

            return response()->json([
                'success' => true,
                'message' => "Permintaan Manpower (MPP #{$record->id}) telah ditolak (Rejected).",
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to reject manpower request', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menolak permintaan: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Hold a manpower request.
     */
    public function holdRequest(Request $request, $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|min:3',
        ]);

        $record = RequestManPower::findOrFail($id);

        try {
            $record->markOnHold(Auth::id(), $request->input('reason'));

            return response()->json([
                'success' => true,
                'message' => "Permintaan Manpower (MPP #{$record->id}) telah di-hold.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal hold permintaan: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Job Postings list.
     */
    public function getJobPostings(Request $request): JsonResponse
    {
        $query = JobPosting::with([
            'company',
            'requestManPower.company',
            'requestManPowers.company',
            'rekrutmenPipeline',
        ])
            ->withCount(['applications', 'requestManPowers'])
            ->latest('created_at');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhereHas('company', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('requestManPower.company', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('requestManPowers.company', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $postings = $query->paginate($request->input('per_page', 50));

        $postings->getCollection()->transform(function (JobPosting $record) {
            return [
                'id'                     => $record->id,
                'title'                  => $record->title,
                'slug'                   => $record->slug,
                'company_id'             => $record->company_id ?? $record->resolveCompany()?->id,
                'company_name'           => $record->resolveCompanyName(),
                'description'            => $record->description,
                'requirements'           => $record->requirements,
                'context_description'    => JobPostingResource::formatJobPostingContext($record),
                'location'               => $record->location ?? 'Indonesia',
                'thumbnail_path'         => $record->thumbnail_path,
                'thumbnail_url'          => $record->thumbnail_url,
                'is_published'           => (bool) $record->is_published,
                'rekrutmen_pipeline_id'  => $record->rekrutmen_pipeline_id ?? 1,
                'pipeline_name'          => $record->rekrutmenPipeline?->name ?? 'Default Recruitment Pipeline',
                'applications_count'     => $record->applications_count ?? 0,
                'request_man_powers_cnt' => $record->request_man_powers_count ?? 0,
                'closing_date'           => $record->closing_date ? $record->closing_date->format('Y-m-d') : null,
                'closing_date_formatted' => $record->closing_date ? $record->closing_date->format('d/m/Y') : '-',
                'created_at'             => $record->created_at ? $record->created_at->format('d/m/Y') : '-',
            ];
        });

        $responseData = $postings->toArray();
        $responseData['companies'] = Company::query()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
        $responseData['pipelines'] = RekrutmenPipeline::query()->orderBy('id')->get(['id', 'name']);

        return response()->json($responseData);
    }

    /**
     * Get list of active companies for selection.
     */
    public function getCompanies(): JsonResponse
    {
        return response()->json(
            Company::query()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])
        );
    }

    /**
     * Toggle publish status of a Job Posting.
     */
    public function togglePublishJobPosting(Request $request, $id): JsonResponse
    {
        $posting = JobPosting::findOrFail($id);

        $newStatus = $request->has('is_published')
            ? (bool) $request->input('is_published')
            : ! $posting->is_published;

        $posting->is_published = $newStatus;
        $posting->save();

        return response()->json([
            'success'      => true,
            'is_published' => $posting->is_published,
            'message'      => $posting->is_published
                ? "Lowongan \"{$posting->title}\" berhasil di-Publish (Aktif)!"
                : "Lowongan \"{$posting->title}\" diubah menjadi Draft (Nonaktif).",
        ]);
    }

    /**
     * Store a new Job Posting.
     */
    public function storeJobPosting(Request $request): JsonResponse
    {
        $request->validate([
            'title'                 => 'required|string|max:255',
            'company_id'            => 'nullable',
            'rekrutmen_pipeline_id' => 'nullable|integer|exists:rekrutmen_pipelines,id',
            'location'              => 'nullable|string|max:255',
            'description'           => 'nullable|string',
            'requirements'          => 'nullable|string',
            'closing_date'          => 'nullable|date',
            'is_published'          => 'nullable',
            'thumbnail'             => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $title = trim($request->input('title'));
        $baseSlug = Str::slug($title);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'job-posting';
        $slug = $baseSlug;
        $counter = 1;
        while (JobPosting::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter++;
        }

        $pipelineId = $request->input('rekrutmen_pipeline_id');
        if (filled($pipelineId)) {
            $pipeline = RekrutmenPipeline::find($pipelineId);
        }
        if (! isset($pipeline) || ! $pipeline) {
            $pipeline = RekrutmenPipeline::firstOrCreate(['id' => 1], ['name' => 'Default Recruitment Pipeline']);
        }

        $companyId = $request->input('company_id');
        $companyId = filled($companyId) ? (int) $companyId : null;

        $isPublished = false;
        if ($request->has('is_published')) {
            $isPublished = filter_var($request->input('is_published'), FILTER_VALIDATE_BOOLEAN);
        }

        $thumbnailPath = null;
        if ($request->hasFile('thumbnail')) {
            $thumbnailPath = app(RekrutmenStorage::class)->storeUploadedFile($request->file('thumbnail'), JobPosting::THUMBNAIL_DIRECTORY, JobPosting::thumbnailDisk());
        }

        $posting = JobPosting::create([
            'company_id'            => $companyId,
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => $title,
            'slug'                  => $slug,
            'location'              => $request->input('location'),
            'description'           => $request->input('description'),
            'requirements'          => $request->input('requirements'),
            'closing_date'          => $request->input('closing_date'),
            'is_published'          => $isPublished,
            'thumbnail_path'        => $thumbnailPath,
            'thumbnail_disk'        => $thumbnailPath ? JobPosting::thumbnailDisk() : null,
            'creator_id'            => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Lowongan \"{$posting->title}\" berhasil ditambahkan!",
            'posting' => [
                'id'                    => $posting->id,
                'title'                 => $posting->title,
                'thumbnail_url'         => $posting->thumbnail_url,
                'is_published'          => $posting->is_published,
                'company_id'            => $posting->company_id ?? $posting->resolveCompany()?->id,
                'company_name'          => $posting->resolveCompanyName(),
                'rekrutmen_pipeline_id' => $posting->rekrutmen_pipeline_id,
            ],
        ], 201);
    }

    /**
     * Update a Job Posting.
     */
    public function updateJobPosting(Request $request, $id): JsonResponse
    {
        $request->validate([
            'title'                 => 'required|string|max:255',
            'company_id'            => 'nullable',
            'rekrutmen_pipeline_id' => 'nullable|integer|exists:rekrutmen_pipelines,id',
            'location'              => 'nullable|string|max:255',
            'description'           => 'nullable|string',
            'requirements'          => 'nullable|string',
            'closing_date'          => 'nullable|date',
            'is_published'          => 'nullable',
            'thumbnail'             => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'remove_thumbnail'      => 'nullable',
        ]);

        $posting = JobPosting::findOrFail($id);

        if ($request->has('rekrutmen_pipeline_id')) {
            $pipelineId = $request->filled('rekrutmen_pipeline_id') ? $request->integer('rekrutmen_pipeline_id') : 1;

            if ($pipelineId !== (int) $posting->rekrutmen_pipeline_id && $posting->applications()->withTrashed()->exists()) {
                throw ValidationException::withMessages([
                    'rekrutmen_pipeline_id' => 'Pipeline tidak dapat diubah karena lowongan sudah memiliki riwayat pelamar.',
                ]);
            }

            $posting->rekrutmen_pipeline_id = $pipelineId;
        }

        $posting->title = $request->input('title');

        if ($request->has('company_id')) {
            $companyId = $request->input('company_id');
            $posting->company_id = filled($companyId) ? (int) $companyId : null;

            if ($posting->request_man_power_id) {
                RequestManPower::whereKey($posting->request_man_power_id)->update(['company_id' => $posting->company_id]);
            }
            RequestManPower::where('job_posting_id', $posting->id)->update(['company_id' => $posting->company_id]);
        }

        $posting->location = $request->input('location');
        $posting->description = $request->input('description');
        $posting->requirements = $request->input('requirements');
        $posting->closing_date = $request->input('closing_date');
        if ($request->has('is_published')) {
            $posting->is_published = filter_var($request->input('is_published'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($request->hasFile('thumbnail')) {
            $path = app(RekrutmenStorage::class)->storeUploadedFile($request->file('thumbnail'), JobPosting::THUMBNAIL_DIRECTORY, JobPosting::thumbnailDisk());
            $posting->thumbnail_path = $path;
            $posting->thumbnail_disk = JobPosting::thumbnailDisk();
        } elseif ($request->input('remove_thumbnail') === '1' || $request->input('remove_thumbnail') === true || $request->input('remove_thumbnail') === 'true') {
            $posting->thumbnail_path = null;
        }

        $posting->save();

        return response()->json([
            'success' => true,
            'message' => "Lowongan \"{$posting->title}\" berhasil diperbarui!",
            'posting' => [
                'id'            => $posting->id,
                'title'         => $posting->title,
                'thumbnail_url' => $posting->thumbnail_url,
                'is_published'  => $posting->is_published,
                'company_id'    => $posting->company_id ?? $posting->resolveCompany()?->id,
                'company_name'  => $posting->resolveCompanyName(),
            ],
        ]);
    }

    /**
     * Delete a Job Posting.
     */
    public function destroyJobPosting($id): JsonResponse
    {
        $posting = JobPosting::findOrFail($id);

        $hasApplications = DB::table('rekrutmen_job_applications')
            ->where('job_posting_id', $posting->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($hasApplications) {
            return response()->json([
                'success' => false,
                'message' => "Lowongan \"{$posting->title}\" masih memiliki kandidat pelamar. Tidak dapat dihapus.",
            ], 422);
        }

        $posting->delete();

        return response()->json([
            'success' => true,
            'message' => "Lowongan \"{$posting->title}\" berhasil dihapus.",
        ]);
    }

    /**
     * Get job applications with the latest background screening status.
     */
    public function getApplications(Request $request): JsonResponse
    {
        $this->checkAndProcessDueNotifications();

        $query = JobApplication::with([
            'jobPosting.requestManPower.company',
            'jobPosting.requestManPowers.company',
            'currentStage',
        ])->latest('created_at');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('whatsapp_number', 'like', "%{$search}%")
                    ->orWhere('active_phone', 'like', "%{$search}%");
            });
        }

        $activeJob = null;
        $isJobFiltered = false;
        if ($request->filled('job_id')) {
            $jobId = (int) $request->input('job_id');
            $query->where('job_posting_id', $jobId);
            $activeJob = JobPosting::find($jobId);
            $isJobFiltered = true;
        }

        $colors = [
            'Screening CV'              => '#2563eb',
            'Interview HR'              => '#d97706',
            'Psikotes'                  => '#7c3aed',
            'Tes Kompetensi (Optional)' => '#4f46e5',
            'Interview User'            => '#0284c7',
            'Backgrond Check'           => '#0d9488',
            'Offering Letter'           => '#ea580c',
            'Hired'                     => '#059669',
        ];

        $pipelineId = null;
        if ($activeJob && $activeJob->rekrutmen_pipeline_id) {
            $pipelineId = (int) $activeJob->rekrutmen_pipeline_id;
        } elseif ($request->filled('pipeline_id')) {
            $pipelineId = (int) $request->input('pipeline_id');
            $query->whereHas('jobPosting', fn ($postingQuery) => $postingQuery->where('rekrutmen_pipeline_id', $pipelineId));
        }

        $stages = RekrutmenStage::query()
            ->when($pipelineId, fn ($stageQuery) => $stageQuery->where('rekrutmen_pipeline_id', $pipelineId))
            ->orderBy('rekrutmen_pipeline_id')
            ->orderBy('order_column')
            ->get(['id', 'rekrutmen_pipeline_id', 'name', 'order_column'])
            ->map(fn ($s) => [
                'id'                    => $s->id,
                'rekrutmen_pipeline_id' => $s->rekrutmen_pipeline_id,
                'name'                  => $s->name,
                'order_column'          => $s->order_column,
                'color'                 => $colors[$s->name] ?? '#3b82f6',
            ]);

        $rawApps = $isJobFiltered ? $query->get() : $query->take(150)->get();

        $applications = $rawApps->map(function (JobApplication $app) use ($colors) {
            $stage = $app->currentStage;
            $stageData = null;
            if ($stage) {
                $stageData = [
                    'id'    => $stage->id,
                    'name'  => $stage->name,
                    'color' => $colors[$stage->name] ?? '#2563eb',
                ];
            }

            $marital = $app->marital_status ? (is_object($app->marital_status) ? (method_exists($app->marital_status, 'getLabel') ? $app->marital_status->getLabel() : $app->marital_status->name ?? (string) $app->marital_status) : (string) $app->marital_status) : '-';

            $genderLabel = '-';
            if ($app->gender) {
                $genderLabel = is_object($app->gender)
                    ? (method_exists($app->gender, 'getLabel') ? $app->gender->getLabel() : $app->gender->name ?? (string) $app->gender)
                    : (string) $app->gender;
            }

            $hasResumeOnDisk = $this->resolveAndSyncCandidateCv($app);

            return [
                'id'                         => $app->id,
                'full_name'                  => $app->full_name,
                'email'                      => $app->email,
                'phone'                      => $app->whatsapp_number ?? $app->active_phone ?? '-',
                'whatsapp_number'            => $app->whatsapp_number ?? '-',
                'active_phone'               => $app->active_phone ?? '-',
                'gender'                     => $genderLabel,
                'birth_date'                 => $app->birth_date ? $app->birth_date->format('d/m/Y') : '-',
                'marital_status'             => $marital,
                'address'                    => $app->address_domicile ?? $app->address_ktp ?? '-',
                'address_domicile'           => $app->address_domicile ?? '-',
                'address_ktp'                => $app->address_ktp ?? '-',
                'emergency_contact_name'     => $app->emergency_contact_name ?? '-',
                'emergency_contact_relation' => $app->emergency_contact_relation ?? '-',
                'emergency_contact_phone'    => $app->emergency_contact_phone ?? '-',
                'photo_path'                 => $app->photo_path,
                'has_photo'                  => filled($app->photo_path),
                'photo_url'                  => $app->photo_path ? url("/rekrutmen/api/applications/{$app->id}/photo") : null,
                'source'                     => $app->source ?? 'Website',
                'job_posting_id'             => $app->job_posting_id,
                'job_posting'                => $app->jobPosting ? ['id' => $app->jobPosting->id, 'title' => $app->jobPosting->title, 'rekrutmen_pipeline_id' => $app->jobPosting->rekrutmen_pipeline_id, 'location' => $app->jobPosting->location, 'company_name' => $app->jobPosting->resolveCompanyName()] : null,
                'current_stage_id'           => $app->current_stage_id ?? 1,
                'stage'                      => $stageData,
                'status'                     => $app->status ? (is_object($app->status) ? $app->status->value : $app->status) : 'in_progress',
                ...$this->screeningPayload($app),
                'has_resume'                 => $hasResumeOnDisk,
                'resume_path'                => $hasResumeOnDisk ? $app->resume_path : null,
                'resume_filename'            => $hasResumeOnDisk ? basename($app->resume_path) : "CV-{$app->id}.pdf",
                'resume_url'                 => $hasResumeOnDisk ? url("/rekrutmen/api/applications/{$app->id}/cv") : null,
                'created_at'                 => $app->created_at ? $app->created_at->format('d/m/Y') : '-',
            ];
        });

        return response()->json([
            'stages'       => $stages,
            'applications' => $applications,
            'active_job'   => $activeJob ? ['id' => $activeJob->id, 'title' => $activeJob->title, 'location' => $activeJob->location, 'company_name' => $activeJob->resolveCompanyName()] : null,
            'total'        => $applications->count(),
        ]);
    }

    /**
     * View candidate profile photo.
     */
    public function viewPhoto(Request $request, $id): Response
    {
        $application = JobApplication::query()->findOrFail($id);
        Gate::authorize('view', $application);
        $disk = $application->resolveAttachmentDisk('photo');
        abort_if($disk === null, 404, 'File foto tidak ditemukan pada penyimpanan asal.');

        return Storage::disk($disk)->response($application->photo_path, basename($application->photo_path));
    }

    public function viewCv(Request $request, $id): Response
    {
        $application = JobApplication::query()->with('jobPosting')->findOrFail($id);
        Gate::authorize('view', $application);
        $this->resolveAndSyncCandidateCv($application);
        $disk = $application->resolveAttachmentDisk('resume');
        abort_if($disk === null, 404, 'Berkas CV tidak ditemukan pada penyimpanan asal.');

        return Storage::disk($disk)->response($application->resume_path, basename($application->resume_path));
    }

    /**
     * Upload or replace candidate CV file directly from UI.
     */
    public function uploadCv(UploadCandidateCvRequest $request, $id): JsonResponse
    {
        $application = JobApplication::findOrFail($id);

        $disk = JobApplication::resumeDisk();
        $path = app(RekrutmenStorage::class)->storeUploadedFile($request->file('cv'), JobApplication::RESUME_DIRECTORY, $disk);

        $application->resume_path = $path;
        $application->resume_disk = $disk;
        $application->save();

        $application->refresh();

        return response()->json([
            'success'     => true,
            'message'     => "Berkas CV untuk \"{$application->full_name}\" berhasil diunggah.",
            'resume_path' => $application->resume_path,
            'resume_url'  => url("/rekrutmen/api/applications/{$application->id}/cv?t=".time()),
            ...$this->screeningPayload($application),
        ]);
    }

    public function analyzeWithAi(QueueAiScreeningRequest $request, int $id): JsonResponse
    {
        $application = JobApplication::query()->with('jobPosting')->findOrFail($id);
        Gate::authorize('update', $application);
        $this->assertAiConfigured();
        $this->resolveAndSyncCandidateCv($application);
        $queued = app(AiScreeningService::class)->queue($application, $request->boolean('force'));

        return response()->json([
            'success' => true,
            'queued'  => $queued,
            'message' => $queued
                ? 'CV masuk antrean analisis. Proses tetap berjalan meskipun halaman ditutup.'
                : 'Tidak ada analisis baru yang dijadwalkan. Periksa status screening pelamar.',
            'application' => $this->screeningPayload($application->fresh()),
        ], 202);
    }

    public function batchAnalyzeWithAi(QueueAiScreeningRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', JobApplication::class);
        Gate::authorize('update_rekrutmen_job::application');
        $this->assertAiConfigured();
        $data = $request->validated();
        $query = JobApplication::query()->applyPermissionScope();
        if (! empty($data['application_ids'])) {
            $query->whereIn('id', $data['application_ids']);
        }
        if (! empty($data['job_id'])) {
            $query->where('job_posting_id', $data['job_id']);
        }

        $total = (clone $query)->count();
        $query->whereNotIn('ai_screening_status', ['queued', 'processing']);
        if (! $request->boolean('force')) {
            $query->where('ai_screening_status', '!=', 'completed');
        }

        $applicationIds = $query->orderBy('id')->pluck('id');
        $queued = $applicationIds->count();
        $connection = app(AiScreeningService::class)->queueConnection();
        $requestedAt = now()->startOfSecond()->toIso8601String();
        foreach ($applicationIds->chunk(100) as $chunk) {
            QueueCandidateCvScreeningBatchJob::dispatch($chunk->values()->all(), (int) $request->user()->id, $request->boolean('force'), $requestedAt)
                ->onConnection($connection)->afterCommit();
        }

        return response()->json([
            'success' => true,
            'message' => "{$queued} CV masuk antrean analisis. Proses tetap berjalan meskipun halaman ditutup.",
            'queued'  => $queued,
            'total'   => $total,
            'skipped' => $total - $queued,
        ], 202);
    }

    public function aiScreeningStatus(QueueAiScreeningRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', JobApplication::class);
        $query = JobApplication::query()->applyPermissionScope();
        if ($request->filled('job_id')) {
            $query->where('job_posting_id', $request->integer('job_id'));
        }

        $counts = array_fill_keys(['pending', 'queued', 'processing', 'completed', 'needs_review', 'failed'], 0);
        $groups = (clone $query)->select('ai_screening_status')->selectRaw('COUNT(*) as total')
            ->groupBy('ai_screening_status')->get();
        foreach ($groups as $group) {
            $status = $group->ai_screening_status ?: 'pending';
            if (array_key_exists($status, $counts)) {
                $counts[$status] += (int) $group->total;
            }
        }

        if ($request->has('ids')) {
            $query->whereIn('id', $request->validated()['ids']);
        }
        $applications = $query->latest('id')->limit(200)->get([
            'id', 'ai_screening_status', 'ai_screening_error', 'ai_match_score',
            'ai_recommendation', 'ai_summary', 'ai_analyzed_at',
        ]);

        return response()->json([
            'counts'       => $counts,
            'total'        => array_sum($counts),
            'applications' => $applications->map(fn (JobApplication $application): array => $this->screeningPayload($application)),
        ]);
    }

    /** @return array<string, mixed> */
    private function screeningPayload(JobApplication $application): array
    {
        $completed = $application->ai_screening_status === 'completed';

        return [
            'id'                  => $application->id,
            'ai_screening_status' => $application->ai_screening_status ?: 'pending',
            'ai_screening_error'  => $application->ai_screening_error,
            'ai_match_score'      => $completed ? $application->ai_match_score : null,
            'ai_recommendation'   => $completed ? $application->ai_recommendation : null,
            'ai_summary'          => $completed ? $application->ai_summary : null,
            'ai_analyzed_at'      => $completed ? $application->ai_analyzed_at?->format('d/m/Y H:i') : null,
        ];
    }

    private function assertAiConfigured(): void
    {
        if (! app(AiSettingsService::class)->configured()) {
            throw ValidationException::withMessages([
                'ai' => 'Atur endpoint, model, dan API key di Master Rekrutmen sebelum memulai analisis.',
            ]);
        }
    }

    /**
     * Move application to another stage.
     */
    public function updateApplicationStage(Request $request, $id): JsonResponse
    {
        $application = JobApplication::findOrFail($id);
        $stageInput = $request->input('stage_id');

        if ($stageInput === 'rejected' || $stageInput === 'reject') {
            $application->status = 'rejected';
            $application->save();

            return response()->json([
                'success'     => true,
                'message'     => 'Kandidat berhasil ditolak (tanpa notifikasi)',
                'application' => $application->load(['currentStage', 'jobPosting']),
            ]);
        }

        $request->validate([
            'stage_id' => ['required', 'integer', Rule::exists('rekrutmen_stages', 'id')
                ->where('rekrutmen_pipeline_id', $application->jobPosting?->rekrutmen_pipeline_id)
                ->whereNull('deleted_at')],
        ]);

        $application->current_stage_id = $request->input('stage_id');
        if ($application->status === 'rejected') {
            $application->status = 'in_progress';
        }
        $application->save();

        return response()->json([
            'success'     => true,
            'message'     => 'Status tahapan berhasil diperbarui',
            'application' => $application->load(['currentStage', 'jobPosting']),
        ]);
    }

    /**
     * Batch reject selected applications without sending WA or email notifications.
     */
    public function batchRejectApplications(Request $request): JsonResponse
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'required|integer|exists:rekrutmen_job_applications,id',
        ]);

        $ids = $request->input('ids', []);
        $count = 0;

        foreach ($ids as $appId) {
            $application = JobApplication::find($appId);
            if ($application) {
                $application->status = 'rejected';
                $application->save();
                $count++;
            }
        }

        return response()->json([
            'success' => true,
            'count'   => $count,
            'message' => "{$count} pelamar berhasil ditolak (tanpa notifikasi WA maupun email).",
        ]);
    }

    /**
     * Update application status (e.g. reject, shortlist, hired).
     */
    public function updateApplicationStatus(Request $request, $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|string',
        ]);

        $application = JobApplication::findOrFail($id);
        $application->status = $request->input('status');
        $application->save();

        return response()->json([
            'success'     => true,
            'message'     => 'Status pelamar berhasil diperbarui',
            'application' => $application->load(['currentStage', 'jobPosting']),
        ]);
    }

    /**
     * Get Recruitment Progress Report data.
     */
    public function getProgressReport(Request $request): JsonResponse
    {
        $reportData = app(RecruitmentProgressReportService::class)->build([
            'date_from'      => $request->input('date_from'),
            'date_to'        => $request->input('date_to'),
            'job_posting_id' => $request->filled('job_posting_id') ? (int) $request->input('job_posting_id') : null,
            'company_id'     => $request->filled('company_id') ? (int) $request->input('company_id') : null,
        ]);

        $positions = $reportData['positions']->map(function ($item) {
            $posting = $item['posting'];
            $request = $item['request'];
            $stats = $item['statistics'];
            $cycleHealth = $item['cycle_health'];

            return [
                'id'                     => $posting->id,
                'job_posting_id'         => $posting->id,
                'position'               => $posting->title,
                'company'                => $request?->company?->name ?? 'PT Complete Selular Group',
                'location'               => $posting->location ?? 'Indonesia',
                'needed'                 => $item['needed'] ?? 1,
                'total_applicants'       => $stats['total_applicants'] ?? 0,
                'hired'                  => $stats['hired'] ?? 0,
                'in_process'             => $stats['in_progress'] ?? 0,
                'rejected'               => $stats['rejected'] ?? 0,
                'request_status_label'   => $item['request_status_label'] ?? ($posting->is_published ? 'Published' : 'Draft'),
                'cycle_health'           => is_array($cycleHealth) ? ($cycleHealth['status_label'] ?? 'Optimal') : 'Optimal',
                'cycle_health_status'    => is_array($cycleHealth) ? ($cycleHealth['status'] ?? 'healthy') : 'healthy',
                'cycle_health_summary'   => is_array($cycleHealth) ? ($cycleHealth['summary'] ?? '') : '',
                'cycle_health_desc'      => is_array($cycleHealth) ? ($cycleHealth['description'] ?? '') : '',
                'cycle_health_issues'    => is_array($cycleHealth) ? ($cycleHealth['issues'] ?? []) : [],
                'fulfillment_percentage' => $item['fulfillment_percentage'] ?? 0,
            ];
        });

        return response()->json([
            'summary'   => $reportData['summary'],
            'positions' => $positions,
            'overview'  => $reportData['overview'] ?? [],
            'timeline'  => $reportData['timeline'] ?? [],
        ]);
    }

    /**
     * Export Recruitment Progress Report to Excel with the authentic 4-sheet enterprise template.
     */
    public function exportProgressReport(Request $request): BinaryFileResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(180);

        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $jobPostingId = $request->filled('job_posting_id') ? (int) $request->input('job_posting_id') : null;
        $companyId = $request->filled('company_id') ? (int) $request->input('company_id') : null;

        $reportData = app(RecruitmentProgressReportService::class)->build([
            'date_from'      => $dateFrom,
            'date_to'        => $dateTo,
            'job_posting_id' => $jobPostingId,
            'company_id'     => $companyId,
        ]);

        $periodLabel = 'Semua Periode';
        if (filled($dateFrom) && filled($dateTo)) {
            $periodLabel = sprintf(
                'Periode %s s/d %s',
                Carbon::parse($dateFrom)->format('d M Y'),
                Carbon::parse($dateTo)->format('d M Y')
            );
        } elseif (filled($dateFrom)) {
            $periodLabel = 'Mulai '.Carbon::parse($dateFrom)->format('d M Y');
        } elseif (filled($dateTo)) {
            $periodLabel = 'Sampai '.Carbon::parse($dateTo)->format('d M Y');
        }

        $from = filled($dateFrom) ? Carbon::parse($dateFrom)->format('Ymd') : 'all';
        $to = filled($dateTo) ? Carbon::parse($dateTo)->format('Ymd') : 'all';
        $filename = "recruitment-progress-mpp-{$from}-to-{$to}.xlsx";

        return Excel::download(
            new RecruitmentProgressReportExport(
                $reportData,
                [
                    'date_from'      => $dateFrom,
                    'date_to'        => $dateTo,
                    'period_label'   => $periodLabel,
                    'position_label' => 'Semua Posisi',
                    'company_label'  => 'Semua Perusahaan',
                ]
            ),
            $filename
        );
    }

    /**
     * Get master configurations (pipelines, stages, divisions, approvers).
     */
    public function getConfigurations(?Request $request = null): JsonResponse
    {
        $request = $request ?? request();
        $divisions = Division::query()
            ->with('company:id,name')
            ->orderBy('name')
            ->get()
            ->map(static function (Division $division): array {
                $companyName = $division->company?->name;

                return [
                    'id'           => $division->id,
                    'name'         => $division->name,
                    'display_name' => $division->nameWithCompany(),
                    'is_active'    => (bool) $division->is_active,
                    'company_id'   => $division->company_id,
                    'company_name' => $companyName,
                    'badan_usaha'  => $companyName,
                ];
            })
            ->sortBy(fn (array $division): string => mb_strtolower(($division['company_name'] ?? '').' '.$division['name']))
            ->values();

        $companies = Company::query()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);

        $colors = [
            'Screening CV'              => '#2563eb',
            'Interview HR'              => '#d97706',
            'Psikotes'                  => '#7c3aed',
            'Tes Kompetensi (Optional)' => '#4f46e5',
            'Interview User'            => '#0284c7',
            'Background Check'          => '#0d9488',
            'Backgrond Check'           => '#0d9488',
            'Offering Letter'           => '#ea580c',
            'Hired'                     => '#059669',
        ];

        $stageCandidateCounts = DB::table('rekrutmen_job_applications')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'rejected')
            ->select('current_stage_id', DB::raw('count(*) as total'))
            ->groupBy('current_stage_id')
            ->pluck('total', 'current_stage_id');

        $pipelines = RekrutmenPipeline::query()->with('activeStages')->withCount('jobPostings')
            ->orderBy('id')
            ->get()
            ->map(function (RekrutmenPipeline $p) use ($colors, $stageCandidateCounts): array {
                $pipelineStages = $p->activeStages
                    ->map(function (RekrutmenStage $s) use ($colors, $stageCandidateCounts): array {
                        return [
                            'id'                    => $s->id,
                            'rekrutmen_pipeline_id' => $s->rekrutmen_pipeline_id,
                            'name'                  => $s->name,
                            'order_column'          => $s->order_column,
                            'color'                 => $colors[$s->name] ?? '#3b82f6',
                            'applications_count'    => (int) ($stageCandidateCounts[$s->id] ?? 0),
                            'is_locked'             => $s->isLockedFinalStage(),
                        ];
                    });

                return [
                    'id'                 => $p->id,
                    'name'               => $p->name,
                    'description'        => $p->description,
                    'stages_count'       => $pipelineStages->count(),
                    'job_postings_count' => (int) $p->job_postings_count,
                    'stages'             => $pipelineStages,
                ];
            });

        $selectedPipelineId = (int) $request->input('pipeline_id', $pipelines->first()['id'] ?? 1);
        $selectedPipeline = $pipelines->firstWhere('id', $selectedPipelineId) ?? $pipelines->first();
        $stages = $selectedPipeline ? $selectedPipeline['stages'] : collect();

        return response()->json([
            'selected_pipeline_id' => $selectedPipeline['id'] ?? null,
            'stages'               => $stages,
            'divisions'            => $divisions,
            'approvers'            => Approver::with(['division.company:id,name', 'company:id,name'])->latest()->get(),
            'pipelines'            => $pipelines,
            'companies'            => $companies,
        ]);
    }

    /**
     * Store a new master recruitment pipeline.
     */
    public function storePipeline(SaveRecruitmentPipelineRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $pipeline = RekrutmenPipeline::getConnectionResolver()->connection()->transaction(function () use ($validated): RekrutmenPipeline {
            $pipeline = RekrutmenPipeline::create([
                'name'        => trim($validated['name']),
                'description' => $validated['description'] ?? null,
                'creator_id'  => Auth::id(),
            ]);

            if (! empty($validated['clone_from_pipeline_id'])) {
                $sourceStages = RekrutmenStage::where('rekrutmen_pipeline_id', $validated['clone_from_pipeline_id'])
                    ->orderBy('order_column')
                    ->get();

                foreach ($sourceStages as $stage) {
                    RekrutmenStage::create([
                        'rekrutmen_pipeline_id' => $pipeline->id,
                        'name'                  => $stage->name,
                        'order_column'          => $stage->order_column,
                        'creator_id'            => Auth::id(),
                    ]);
                }
            } else {
                $defaultStages = ['Screening CV', 'Interview HR', 'Interview User', 'Offering Letter', 'Hired'];
                foreach ($defaultStages as $idx => $sName) {
                    RekrutmenStage::create([
                        'rekrutmen_pipeline_id' => $pipeline->id,
                        'name'                  => $sName,
                        'order_column'          => $idx + 1,
                        'creator_id'            => Auth::id(),
                    ]);
                }
            }

            return $pipeline;
        });

        return response()->json([
            'success'  => true,
            'message'  => "Pipeline \"{$pipeline->name}\" berhasil dibuat.",
            'pipeline' => $pipeline->load('stages'),
        ], 201);
    }

    /**
     * Update an existing recruitment pipeline.
     */
    public function updatePipeline(SaveRecruitmentPipelineRequest $request, $id): JsonResponse
    {
        $pipeline = RekrutmenPipeline::findOrFail($id);

        $validated = $request->validated();

        $pipeline->update([
            'name'        => trim($validated['name']),
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'success'  => true,
            'message'  => "Pipeline \"{$pipeline->name}\" berhasil diperbarui.",
            'pipeline' => $pipeline,
        ]);
    }

    /**
     * Destroy a recruitment pipeline.
     */
    public function destroyPipeline(Request $request, $id): JsonResponse
    {
        $pipeline = RekrutmenPipeline::findOrFail($id);

        if ($pipeline->id === 1) {
            return response()->json([
                'success' => false,
                'message' => 'Pipeline standar utama tidak dapat dihapus.',
            ], 422);
        }

        if ($pipeline->jobPostings()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Pipeline ini tidak dapat dihapus karena masih digunakan oleh lowongan pekerjaan terdaftar.',
            ], 422);
        }

        $pipeline->getConnection()->transaction(function () use ($pipeline): void {
            $pipeline->activeStages()->delete();
            $pipeline->delete();
        });

        return response()->json([
            'success' => true,
            'message' => "Pipeline \"{$pipeline->name}\" berhasil dihapus.",
        ]);
    }

    /**
     * Store a new division.
     */
    public function storeDivision(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'company_id' => 'required|integer|exists:companies,id',
            'is_active'  => 'nullable|boolean',
        ]);

        $name = trim($validated['name']);
        $companyId = (int) $validated['company_id'];

        $exists = Division::where('company_id', $companyId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Divisi dengan nama tersebut sudah terdaftar pada badan usaha ini.',
            ], 422);
        }

        $division = Division::create([
            'company_id' => $companyId,
            'name'       => $name,
            'is_active'  => $request->has('is_active') ? (bool) $request->input('is_active') : true,
            'creator_id' => Auth::id(),
        ]);

        $division->load('company:id,name');

        return response()->json([
            'success'  => true,
            'message'  => "Divisi \"{$division->name}\" berhasil ditambahkan.",
            'division' => [
                'id'           => $division->id,
                'name'         => $division->name,
                'display_name' => $division->nameWithCompany(),
                'is_active'    => (bool) $division->is_active,
                'company_id'   => $division->company_id,
                'company_name' => $division->company?->name,
                'badan_usaha'  => $division->company?->name,
            ],
        ]);
    }

    /**
     * Update an existing division.
     */
    public function updateDivision(Request $request, $id): JsonResponse
    {
        $division = Division::findOrFail($id);

        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'company_id' => 'required|integer|exists:companies,id',
            'is_active'  => 'nullable|boolean',
        ]);

        $name = trim($validated['name']);
        $companyId = (int) $validated['company_id'];

        $exists = Division::where('company_id', $companyId)
            ->where('id', '!=', $division->id)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Divisi dengan nama tersebut sudah terdaftar pada badan usaha ini.',
            ], 422);
        }

        $division->update([
            'company_id' => $companyId,
            'name'       => $name,
            'is_active'  => $request->has('is_active') ? (bool) $request->input('is_active') : $division->is_active,
        ]);

        $division->load('company:id,name');

        return response()->json([
            'success'  => true,
            'message'  => "Divisi \"{$division->name}\" berhasil diperbarui.",
            'division' => [
                'id'           => $division->id,
                'name'         => $division->name,
                'display_name' => $division->nameWithCompany(),
                'is_active'    => (bool) $division->is_active,
                'company_id'   => $division->company_id,
                'company_name' => $division->company?->name,
                'badan_usaha'  => $division->company?->name,
            ],
        ]);
    }

    /**
     * Delete a division.
     */
    public function destroyDivision(Request $request, $id): JsonResponse
    {
        $division = Division::findOrFail($id);

        $hasApprovers = Approver::where('division_id', $division->id)->exists();
        if ($hasApprovers) {
            return response()->json([
                'success' => false,
                'message' => "Divisi \"{$division->name}\" tidak dapat dihapus karena masih digunakan pada data Approver.",
            ], 422);
        }

        $division->delete();

        return response()->json([
            'success' => true,
            'message' => "Divisi \"{$division->name}\" berhasil dihapus.",
        ]);
    }

    /**
     * Store a new recruitment pipeline stage.
     */
    public function storeStage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                  => 'required|string|max:255',
            'rekrutmen_pipeline_id' => 'nullable|integer|exists:rekrutmen_pipelines,id',
            'pipeline_id'           => 'nullable|integer|exists:rekrutmen_pipelines,id',
        ]);

        $name = trim($validated['name']);
        $pipelineId = $validated['rekrutmen_pipeline_id'] ?? $validated['pipeline_id'] ?? 1;

        $pipeline = RekrutmenPipeline::firstOrCreate(['id' => $pipelineId], ['name' => 'Standard Recruitment Pipeline']);

        $maxOrder = (int) RekrutmenStage::where('rekrutmen_pipeline_id', $pipeline->id)->max('order_column');

        $stage = RekrutmenStage::create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => $name,
            'order_column'          => $maxOrder + 1,
            'creator_id'            => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Tahapan \"{$stage->name}\" berhasil ditambahkan.",
            'stage'   => $stage,
        ]);
    }

    /**
     * Reorder recruitment pipeline stages.
     */
    public function reorderStages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stage_ids'             => 'required|array|min:1',
            'stage_ids.*'           => 'required|integer|distinct|exists:rekrutmen_stages,id',
            'rekrutmen_pipeline_id' => 'nullable|integer|exists:rekrutmen_pipelines,id',
            'pipeline_id'           => 'nullable|integer|exists:rekrutmen_pipelines,id',
        ]);

        $stageIds = array_values(array_unique(array_map('intval', $validated['stage_ids'])));
        $firstStage = RekrutmenStage::find($stageIds[0]);
        $pipelineId = $validated['rekrutmen_pipeline_id'] ?? $validated['pipeline_id'] ?? ($firstStage?->rekrutmen_pipeline_id ?? 1);

        $pipelineStages = RekrutmenStage::query()
            ->where('rekrutmen_pipeline_id', $pipelineId)
            ->orderBy('order_column')
            ->get();

        if (count(array_diff($stageIds, $pipelineStages->modelKeys())) > 0) {
            throw ValidationException::withMessages([
                'stage_ids' => 'Semua tahapan harus berasal dari pipeline yang dipilih.',
            ]);
        }

        $orderedStages = collect($stageIds)
            ->map(fn (int $stageId) => $pipelineStages->firstWhere('id', $stageId))
            ->merge($pipelineStages->whereNotIn('id', $stageIds));
        $stageIds = $orderedStages->reject(fn (RekrutmenStage $stage): bool => $stage->isLockedFinalStage())
            ->merge($orderedStages->filter(fn (RekrutmenStage $stage): bool => $stage->isLockedFinalStage()))
            ->pluck('id')->all();

        RekrutmenStage::getConnectionResolver()->connection()->transaction(function () use ($stageIds, $pipelineId): void {
            // Temporary negative offsets to avoid composite unique constraint collisions on (rekrutmen_pipeline_id, order_column)
            foreach ($stageIds as $index => $id) {
                RekrutmenStage::where('id', $id)->update([
                    'order_column' => -($index + 1),
                ]);
            }

            $reservedOrders = RekrutmenStage::onlyTrashed()
                ->where('rekrutmen_pipeline_id', $pipelineId)->pluck('order_column')->all();
            $nextOrder = 1;
            foreach ($stageIds as $id) {
                while (in_array($nextOrder, $reservedOrders)) {
                    $nextOrder++;
                }

                RekrutmenStage::whereKey($id)->update(['order_column' => $nextOrder++]);
            }

        });

        $stages = RekrutmenStage::where('rekrutmen_pipeline_id', $pipelineId)
            ->orderBy('order_column')
            ->get(['id', 'rekrutmen_pipeline_id', 'name', 'order_column']);

        return response()->json([
            'success' => true,
            'message' => 'Urutan tahapan pipeline berhasil diperbarui.',
            'stages'  => $stages,
        ]);
    }

    /**
     * Update an existing recruitment pipeline stage.
     */
    public function updateStage(Request $request, $id): JsonResponse
    {
        $stage = RekrutmenStage::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $name = trim($validated['name']);

        if ($stage->isLockedFinalStage() && ! RekrutmenStage::isFinalHiredStageName($name)) {
            return response()->json([
                'success' => false,
                'message' => 'Tahapan final Hired tidak dapat diubah namanya.',
            ], 422);
        }

        $stage->update([
            'name' => $name,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Tahapan \"{$stage->name}\" berhasil diperbarui.",
            'stage'   => $stage,
        ]);
    }

    /**
     * Delete a recruitment pipeline stage.
     */
    public function destroyStage($id): JsonResponse
    {
        $stage = RekrutmenStage::findOrFail($id);

        if ($stage->isLockedFinalStage()) {
            return response()->json([
                'success' => false,
                'message' => 'Tahapan final Hired tidak dapat dihapus.',
            ], 422);
        }

        $hasActiveCandidates = DB::table('rekrutmen_job_applications')
            ->where('current_stage_id', $stage->id)
            ->where('status', '!=', 'rejected')
            ->whereNull('deleted_at')
            ->exists();

        if ($hasActiveCandidates) {
            return response()->json([
                'success' => false,
                'message' => "Tahapan \"{$stage->name}\" masih memiliki kandidat aktif. Pindahkan kandidat terlebih dahulu.",
            ], 422);
        }

        $stage->delete();

        return response()->json([
            'success' => true,
            'message' => "Tahapan \"{$stage->name}\" berhasil dihapus.",
        ]);
    }

    public function getAiSettings(AiSettingsService $settings): JsonResponse
    {
        return response()->json($settings->publicSettings());
    }

    public function saveAiSettings(AiSettingsRequest $request, AiSettingsService $settings): JsonResponse
    {
        $settings->save($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Pengaturan AI berhasil disimpan dan langsung digunakan untuk screening berikutnya.',
        ]);
    }

    public function testAiConnection(AiSettingsRequest $request, AiSettingsService $settings): JsonResponse
    {
        $current = $settings->current();
        $data = $request->validated();
        $apiKey = trim((string) ($data['api_key'] ?? '')) ?: $current['api_key'];
        if (($data['clear_api_key'] ?? false) || $apiKey === '') {
            return response()->json(['success' => false, 'message' => 'API key belum diatur.'], 422);
        }

        $baseUrl = rtrim((string) ($data['base_url'] ?? $current['base_url']), '/');
        $model = (string) ($data['model'] ?? $current['model']);
        try {
            $response = Http::withToken($apiKey)->acceptJson()->connectTimeout(10)->timeout(20)
                ->post($baseUrl.'/chat/completions', [
                    'model'           => $model,
                    'messages'        => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Balas JSON {"status":"OK"} jika terhubung.']]]],
                    'response_format' => ['type' => 'json_object'],
                ]);
            $content = $response->json('choices.0.message.content');
            if ($response->successful() && is_string($content) && trim($content) !== '') {
                return response()->json(['success' => true, 'message' => "Koneksi OpenAI Compatible berhasil (Model: {$model})!"]);
            }
        } catch (\Throwable) {
        }

        return response()->json([
            'success' => false,
            'message' => 'Koneksi OpenAI Compatible gagal. Periksa API key, endpoint, model, dan kuota layanan.',
        ], 400);
    }

    /**
     * Get default mail templates for stages.
     */
    public static function getDefaultMailTemplates(): array
    {
        return [
            'screening' => [
                'id'           => 'screening',
                'name'         => '1. Screening CV',
                'stage'        => 'Screening CV',
                'badge'        => 'Screening CV',
                'subject'      => 'Konfirmasi Penerimaan Lamaran - {posisi}',
                'body'         => "Halo {nama_pelamar},\n\nTerima kasih atas minat Anda bergabung dengan {perusahaan} untuk posisi {posisi}.\n\nBerkas lamaran dan CV Anda telah kami terima dan saat ini sedang dalam proses peninjauan (Screening CV) oleh tim rekrutmen kami. Kami akan menginformasikan perkembangan seleksi Anda selanjutnya.",
                'info_title'   => 'Informasi Lamaran',
                'action_label' => 'Cek Status Lamaran',
                'has_link'     => false,
                'has_schedule' => false,
                'has_note'     => true,
                'default_note' => 'Pastikan kontak WhatsApp dan email Anda aktif untuk menerima pembaruan informasi proses seleksi.',
            ],
            'interview_hr' => [
                'id'           => 'interview_hr',
                'name'         => '2. Interview HR',
                'stage'        => 'Interview HR',
                'badge'        => 'Interview HR',
                'subject'      => 'Undangan Wawancara HR (Interview HR) - {posisi}',
                'body'         => "Halo {nama_pelamar},\n\nSelamat! Berdasarkan hasil peninjauan berkas lamaran Anda untuk posisi {posisi} di {perusahaan}, kami mengundang Anda untuk mengikuti sesi Wawancara HR (Interview HR).\n\nSesi wawancara ini bertujuan untuk saling mengenal lebih dalam mengenai profil, pengalaman, serta aspirasi karier Anda.",
                'info_title'   => 'Jadwal & Detail Wawancara HR',
                'action_label' => 'Buka Link Wawancara HR',
                'has_link'     => true,
                'has_schedule' => true,
                'has_note'     => true,
                'default_note' => 'Mohon hadir 5-10 menit sebelum waktu wawancara dengan koneksi internet yang stabil dan berpakaian rapi.',
            ],
            'psikotes' => [
                'id'           => 'psikotes',
                'name'         => '3. Psikotes',
                'stage'        => 'Psikotes',
                'badge'        => 'Psikotes Online',
                'subject'      => 'Undangan Tes Psikotes Online - {posisi}',
                'body'         => "Halo {nama_pelamar},\n\nSelamat! Anda berhasil melangkah ke tahapan selanjutnya untuk posisi {posisi} di {perusahaan}.\n\nKami mengundang Anda untuk mengikuti rangkaian Tes Psikotes & Asesmen secara online. Silakan akses tautan ujian yang tertera dan selesaikan tes sebelum batas waktu yang ditentukan.",
                'info_title'   => 'Informasi Pelaksanaan Psikotes',
                'action_label' => 'Mulai Tes Psikotes',
                'has_link'     => true,
                'has_schedule' => true,
                'has_note'     => true,
                'default_note' => 'Pastikan koneksi internet stabil dan gunakan browser di komputer/laptop untuk pengerjaan tes.',
            ],
            'kompetensi' => [
                'id'           => 'kompetensi',
                'name'         => '4. Tes Kompetensi (Optional)',
                'stage'        => 'Tes Kompetensi (Optional)',
                'badge'        => 'Tes Kompetensi',
                'subject'      => 'Undangan Tes Kompetensi & Studi Kasus - {posisi}',
                'body'         => "Halo {nama_pelamar},\n\nSebagai bagian dari tahapan seleksi posisi {posisi} di {perusahaan}, kami mengundang Anda untuk menyelesaikan Tes Kompetensi Teknis (Skill Assessment / Case Study).\n\nInstruksi lengkap, brief tugas, serta lembar pengumpulan hasil dapat Anda akses melalui tautan di bawah.",
                'info_title'   => 'Detail Tugas / Tes Kompetensi',
                'action_label' => 'Buka Lembar Soal & Brief',
                'has_link'     => true,
                'has_schedule' => true,
                'has_note'     => true,
                'default_note' => 'Kumpulkan hasil pengerjaan sebelum batas waktu yang ditentukan sesuai dengan petunjuk instruksi.',
            ],
            'interview_user' => [
                'id'           => 'interview_user',
                'name'         => '5. Interview User',
                'stage'        => 'Interview User',
                'badge'        => 'Interview User',
                'subject'      => 'Undangan Wawancara User (User Interview) - {posisi}',
                'body'         => "Halo {nama_pelamar},\n\nSelamat! Anda telah dinyatakan lolos dan berhak mengikuti tahapan Wawancara User untuk posisi {posisi} di {perusahaan}.\n\nPada sesi ini Anda akan berdiskusi langsung dengan tim User / Departemen terkait mengenai ruang lingkup teknis pekerjaan dan proyek yang akan dikerjakan.",
                'info_title'   => 'Jadwal & Detail Wawancara User',
                'action_label' => 'Buka Link Wawancara User',
                'has_link'     => true,
                'has_schedule' => true,
                'has_note'     => true,
                'default_note' => 'Siapkan portofolio atau materi presentasi terkait pengalaman atau proyek Anda yang relevan.',
            ],
            'background_check' => [
                'id'           => 'background_check',
                'name'         => '6. Background Check',
                'stage'        => 'Backgrond Check',
                'badge'        => 'Background Check',
                'subject'      => 'Verifikasi Data & Referensi Kerja (Background Check) - {posisi}',
                'body'         => "Halo {nama_pelamar},\n\nTerima kasih atas partisipasi Anda dalam seluruh proses seleksi posisi {posisi} di {perusahaan}. Saat ini proses rekrutmen Anda telah memasuki tahap Verifikasi Latar Belakang (Background Check).\n\nMohon bantuannya untuk melengkapi data kontak referensi kerja profesional dan dokumen pendukung melalui tautan tertera.",
                'info_title'   => 'Informasi Kelengkapan Dokumen',
                'action_label' => 'Lengkapi Form Background Check',
                'has_link'     => true,
                'has_schedule' => true,
                'has_note'     => true,
                'default_note' => 'Seluruh data yang Anda berikan bersifat rahasia dan hanya digunakan untuk keperluan verifikasi proses seleksi.',
            ],
            'offering' => [
                'id'           => 'offering',
                'name'         => '7. Offering Letter',
                'stage'        => 'Offering Letter',
                'badge'        => 'Offering Letter',
                'subject'      => 'Penawaran Kerja Resmi (Offering Letter) - {posisi}',
                'body'         => "Halo {nama_pelamar},\n\nSelamat! Berdasarkan hasil evaluasi dari seluruh tahapan seleksi yang telah Anda lalui, Manajemen {perusahaan} bermaksud menyampaikan Penawaran Kerja Resmi (Offering Letter) untuk posisi {posisi}.\n\nSilakan tinjau rincian penawaran kerja terlampir dan berikan konfirmasi penerimaan Anda sebelum batas waktu yang ditentukan.",
                'info_title'   => 'Rincian Penawaran Kerja',
                'action_label' => 'Lihat Dokumen Offering Letter',
                'has_link'     => true,
                'has_schedule' => true,
                'has_note'     => true,
                'default_note' => 'Harap melakukan konfirmasi penerimaan dan menandatangani dokumen sebelum batas waktu berakhir.',
            ],
            'hired' => [
                'id'           => 'hired',
                'name'         => '8. Hired & Onboarding',
                'stage'        => 'Hired',
                'badge'        => 'Selamat Bergabung',
                'subject'      => 'Selamat Bergabung di {perusahaan}! (Onboarding) - {posisi}',
                'body'         => "Halo {nama_pelamar},\n\nSelamat Bergabung di {perusahaan}! Kami sangat bangga menyambut Anda sebagai bagian resmi dari tim kami untuk posisi {posisi}.\n\nInformasi mengenai jadwal hari pertama masuk kerja (First Day Onboarding), perlengkapan kerja, serta agenda pengenalan tim tertera pada rincian berikut.",
                'info_title'   => 'Jadwal Hari Pertama & Onboarding',
                'action_label' => 'Buka Panduan Onboarding',
                'has_link'     => true,
                'has_schedule' => true,
                'has_note'     => true,
                'default_note' => 'Selamat memulai perjalanan baru bersama kami! Jangan ragu menghubungi HR jika ada pertanyaan.',
            ],
            'rejection' => [
                'id'           => 'rejection',
                'name'         => 'Pemberitahuan Status (Penolakan)',
                'stage'        => 'Ditolak',
                'badge'        => 'Status Lamaran',
                'subject'      => 'Pembaruan Status Proses Seleksi - {posisi}',
                'body'         => "Halo {nama_pelamar},\n\nTerima kasih atas waktu dan dedikasi Anda dalam mengikuti proses seleksi posisi {posisi} di {perusahaan}.\n\nSetelah pertimbangan menyeluruh, saat ini kami memutuskan untuk melanjutkan proses dengan kandidat lain yang kualifikasinya lebih mendekati kebutuhan posisi saat ini. Profil Anda akan tetap tersimpan dalam talent database kami untuk peluang mendatang yang relevan.",
                'info_title'   => 'Informasi Lamaran',
                'action_label' => '',
                'has_link'     => false,
                'has_schedule' => false,
                'has_note'     => false,
                'default_note' => 'Kami mendoakan yang terbaik untuk perjalanan karier profesional Anda.',
            ],
        ];
    }

    /**
     * Get Mail Templates.
     */
    public function getMailTemplates(): JsonResponse
    {
        $templates = self::getDefaultMailTemplates();

        try {
            $setting = DB::table('settings')
                ->where('group', 'rekrutmen')
                ->where('name', 'mail_templates')
                ->first();

            if ($setting && ! empty($setting->payload)) {
                $saved = json_decode($setting->payload, true);
                if (is_array($saved)) {
                    foreach ($saved as $k => $tpl) {
                        if (isset($templates[$k])) {
                            $templates[$k] = array_merge($templates[$k], $tpl);
                        } else {
                            $templates[$k] = $tpl;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        return response()->json([
            'templates' => $templates,
        ]);
    }

    /**
     * Save Mail Templates to Database.
     */
    public function saveMailTemplates(Request $request): JsonResponse
    {
        $templates = $request->input('templates', []);

        DB::table('settings')->updateOrInsert(
            ['group' => 'rekrutmen', 'name' => 'mail_templates'],
            [
                'payload'    => json_encode($templates),
                'locked'     => false,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Template email notifikasi berhasil disimpan ke database!',
        ]);
    }

    /**
     * Send notification (email and/or WhatsApp) directly to candidate using template.
     */
    public function sendCandidateEmail(SendCandidateNotificationRequest $request, int $id): JsonResponse
    {
        $application = JobApplication::with(['jobPosting', 'currentStage'])->findOrFail($id);
        Gate::authorize('update', $application);
        $originalStage = $application->current_stage_id;
        $scheduled = $request->validated('send_type') === 'scheduled';
        $service = app(ScheduledNotificationService::class);
        $data = array_merge($request->validated(), [
            'application_ids' => [$id],
            'scheduled_at'    => $scheduled ? $request->validated('scheduled_at') : null,
        ]);
        $notification = $service->schedule($data, $request->file('attachment'), $request->user()->id, $scheduled);

        if (! $scheduled) {
            $service->executeScheduled($notification, true);
        }

        $progress = $service->progress($notification->fresh());
        $queued = in_array($progress['status'], ['pending', 'processing'], true);
        $results = collect($progress['details'])->first() ?? [];
        $application->refresh()->load('currentStage');
        $hasSuccess = ($progress['stats']['email_success'] + $progress['stats']['whatsapp_success']) > 0;

        return response()->json(array_merge($progress, [
            'success'   => $queued || $hasSuccess,
            'queued'    => $queued,
            'scheduled' => $scheduled,
            'batch_id'  => $notification->id,
            'data'      => ['id' => $notification->id, 'status' => $progress['status'], 'scheduled_at' => $notification->scheduled_at],
            'results'   => ['email' => $results['email'] ?? null, 'whatsapp' => $results['whatsapp'] ?? null],
            'message'   => $queued ? 'Notifikasi masuk antrean pengiriman.' : ($hasSuccess ? 'Pengiriman selesai. Periksa hasil setiap kanal.' : 'Pesan belum berhasil dikirim. Periksa detail pengiriman.'),
            'new_stage' => $application->current_stage_id !== $originalStage && $application->currentStage
                ? ['id' => $application->currentStage->id, 'name' => $application->currentStage->name] : null,
        ]), $queued ? 202 : ($hasSuccess ? 200 : 422));
    }

    public function bulkSendCandidateNotification(SendCandidateNotificationRequest $request): JsonResponse
    {
        $applications = JobApplication::query()->whereIn('id', $request->validated('application_ids'))->get();
        foreach ($applications as $application) {
            Gate::authorize('update', $application);
        }

        abort_if($applications->isEmpty(), 422, 'Tidak ada kandidat valid yang dipilih.');
        $scheduled = $request->validated('send_type') === 'scheduled';
        $service = app(ScheduledNotificationService::class);
        $notification = $service->schedule(array_merge($request->validated(), [
            'scheduled_at' => $scheduled ? $request->validated('scheduled_at') : null,
        ]), $request->file('attachment'), $request->user()->id);

        return response()->json(array_merge($service->progress($notification->fresh()), [
            'success'   => true,
            'queued'    => true,
            'scheduled' => $scheduled,
            'batch_id'  => $notification->id,
            'data'      => ['id' => $notification->id, 'status' => $notification->status, 'scheduled_at' => $notification->scheduled_at],
            'message'   => 'Notifikasi massal masuk antrean. Hasil diperbarui per kandidat.',
        ]), 202);
    }

    public function notificationProgress(ScheduledNotification $notification): JsonResponse
    {
        abort_unless((int) $notification->creator_id === (int) auth()->id() || auth()->user()->roles()->where('name', config('filament-shield.super_admin.name', 'super_admin'))->exists(), 403);

        foreach (JobApplication::query()->whereIn('id', $notification->application_ids)->get() as $application) {
            Gate::authorize('update', $application);
        }

        return response()->json(app(ScheduledNotificationService::class)->progress($notification));
    }

    /**
     * Heartbeat endpoint called by frontend to trigger due scheduled notifications and return state.
     */
    public function heartbeatScheduled(): JsonResponse
    {
        Gate::authorize('viewAny', JobApplication::class);

        try {
            $processed = app(ScheduledNotificationService::class)->processDueNotifications();
            $hasPending = ScheduledNotification::where('status', ScheduledNotification::STATUS_PENDING)->exists();

            return response()->json([
                'success'     => true,
                'processed'   => $processed,
                'has_pending' => $hasPending,
                'server_time' => now()->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Heartbeat notification check failed: '.$e->getMessage());

            return response()->json([
                'success'     => true,
                'processed'   => 0,
                'has_pending' => false,
                'server_time' => now()->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function resolveAndSyncCandidateCv(JobApplication $application): bool
    {
        $storage = app(RekrutmenStorage::class);
        $file = $storage->findCandidateResume($application);
        if ($file === null) {
            return false;
        }

        $storage->rememberCandidateResume($application, $file);

        return $application->resolveAttachmentDisk('resume') !== null;
    }

    public function syncCandidateCvsFromStorage(): JsonResponse
    {
        Gate::authorize('viewAny', JobApplication::class);
        $storage = app(RekrutmenStorage::class);
        $files = $storage->files(JobApplication::RESUME_DIRECTORY);
        $matched = 0;
        $updated = 0;

        foreach (JobApplication::query()->get(['id', 'creator_id', 'resume_path', 'resume_disk']) as $application) {
            if (! Gate::allows('update', $application)) {
                continue;
            }
            $file = $storage->findCandidateResume($application, $files);
            if ($file === null) {
                continue;
            }
            $matched++;
            if ($application->resume_path !== $file['path'] || $application->resume_disk !== $file['disk']) {
                $storage->rememberCandidateResume($application, $file);
                $updated++;
            }
        }

        return response()->json([
            'success'     => true,
            'message'     => "Berhasil mencocokkan {$matched} berkas CV ({$updated} diperbarui). Berkas dengan lokasi ambigu dilewati.",
            'matched'     => $matched,
            'updated'     => $updated,
            'total_files' => count($files),
        ]);
    }
}
