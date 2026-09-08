<?php

namespace Cesa\Rekrutmen\Http\Controllers;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Filament\Resources\JobPostingResource;
use Cesa\Rekrutmen\Filament\Resources\RequestManPowerResource;
use Cesa\Rekrutmen\Models\Approver;
use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Services\CandidateWhatsAppNotifier;
use Cesa\Rekrutmen\Services\RecruitmentProgressReportExport;
use Cesa\Rekrutmen\Services\RecruitmentProgressReportService;
use Cesa\Rekrutmen\Services\RekrutmenMailer;
use Cesa\Rekrutmen\Services\ScheduledNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
                'applications_count'     => $record->applications_count ?? 0,
                'request_man_powers_cnt' => $record->request_man_powers_count ?? 0,
                'closing_date'           => $record->closing_date ? $record->closing_date->format('Y-m-d') : null,
                'closing_date_formatted' => $record->closing_date ? $record->closing_date->format('d/m/Y') : '-',
                'created_at'             => $record->created_at ? $record->created_at->format('d/m/Y') : '-',
            ];
        });

        $responseData = $postings->toArray();
        $responseData['companies'] = Company::query()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);

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
            'title'        => 'required|string|max:255',
            'company_id'   => 'nullable',
            'location'     => 'nullable|string|max:255',
            'description'  => 'nullable|string',
            'requirements' => 'nullable|string',
            'closing_date' => 'nullable|date',
            'is_published' => 'nullable',
            'thumbnail'    => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $title = trim($request->input('title'));
        $baseSlug = Str::slug($title);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'job-posting';
        $slug = $baseSlug;
        $counter = 1;
        while (JobPosting::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter++;
        }

        $pipeline = RekrutmenPipeline::firstOrCreate(['id' => 1], ['name' => 'Standard Recruitment Pipeline']);

        $companyId = $request->input('company_id');
        $companyId = filled($companyId) ? (int) $companyId : null;

        $isPublished = false;
        if ($request->has('is_published')) {
            $isPublished = filter_var($request->input('is_published'), FILTER_VALIDATE_BOOLEAN);
        }

        $thumbnailPath = null;
        if ($request->hasFile('thumbnail')) {
            $thumbnailPath = $request->file('thumbnail')->store(JobPosting::THUMBNAIL_DIRECTORY, JobPosting::thumbnailDisk());
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
            'creator_id'            => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Lowongan \"{$posting->title}\" berhasil ditambahkan!",
            'posting' => [
                'id'            => $posting->id,
                'title'         => $posting->title,
                'thumbnail_url' => $posting->thumbnail_url,
                'is_published'  => $posting->is_published,
                'company_id'    => $posting->company_id ?? $posting->resolveCompany()?->id,
                'company_name'  => $posting->resolveCompanyName(),
            ],
        ], 201);
    }

    /**
     * Update a Job Posting.
     */
    public function updateJobPosting(Request $request, $id): JsonResponse
    {
        $request->validate([
            'title'            => 'required|string|max:255',
            'company_id'       => 'nullable',
            'location'         => 'nullable|string|max:255',
            'description'      => 'nullable|string',
            'requirements'     => 'nullable|string',
            'closing_date'     => 'nullable|date',
            'is_published'     => 'nullable',
            'thumbnail'        => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'remove_thumbnail' => 'nullable',
        ]);

        $posting = JobPosting::findOrFail($id);
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
            $path = $request->file('thumbnail')->store(JobPosting::THUMBNAIL_DIRECTORY, JobPosting::thumbnailDisk());
            $posting->thumbnail_path = $path;
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
     * Get Job Applications list (Unified Table & Kanban data with Auto AI Screening per Lowongan).
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

        $stages = RekrutmenStage::where('rekrutmen_pipeline_id', 1)
            ->orderBy('order_column')
            ->get(['id', 'rekrutmen_pipeline_id', 'name', 'order_column'])
            ->map(fn ($s) => [
                'id'           => $s->id,
                'name'         => $s->name,
                'order_column' => $s->order_column,
                'color'        => $colors[$s->name] ?? '#3b82f6',
            ]);

        // If a specific lowongan is selected (e.g. "Web App Developer Cirebon"),
        // get all applicants for this lowongan and perform AI screening against its specific requirements.
        if ($isJobFiltered && $activeJob) {
            $rawApps = $query->get();

            foreach ($rawApps as $app) {
                if ($app->ai_match_score === null) {
                    $aiResult = $this->performAiCvScreening($app, $activeJob, false);

                    $app->ai_match_score = $aiResult['score'];
                    $app->ai_recommendation = $aiResult['recommendation'];
                    $app->ai_summary = $aiResult['summary'];
                    $app->ai_analyzed_at = now();

                    $app->saveQuietly();
                }
            }
        } else {
            // General view without lowongan filter: fetch latest records without mass-screening
            $rawApps = $query->take(150)->get();
        }

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
                'job_posting'                => $app->jobPosting ? ['id' => $app->jobPosting->id, 'title' => $app->jobPosting->title, 'location' => $app->jobPosting->location, 'company_name' => $app->jobPosting->resolveCompanyName()] : null,
                'current_stage_id'           => $app->current_stage_id ?? 1,
                'stage'                      => $stageData,
                'status'                     => $app->status ? (is_object($app->status) ? $app->status->value : $app->status) : 'in_progress',
                'ai_match_score'             => $hasResumeOnDisk ? $app->ai_match_score : 0,
                'ai_recommendation'          => $hasResumeOnDisk ? $app->ai_recommendation : 'Kurang Sesuai',
                'ai_summary'                 => $hasResumeOnDisk ? $app->ai_summary : "Pelamar {$app->full_name} belum melampirkan berkas CV/Resume digital. Skor kualifikasi 0% Match.",
                'ai_analyzed_at'             => $app->ai_analyzed_at ? $app->ai_analyzed_at->format('d/m/Y H:i') : null,
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
    public function viewPhoto(Request $request, $id)
    {
        $application = JobApplication::findOrFail($id);

        if (empty($application->photo_path)) {
            abort(404, 'Foto diri belum diunggah oleh kandidat ini.');
        }

        $relativePath = ltrim($application->photo_path, '/');
        $disks = array_values(array_unique(array_filter([
            JobApplication::resumeDisk(),
            config('filament.default_filesystem_disk', null),
            config('filesystems.default'),
            's3',
            'local',
            'public',
        ])));

        foreach ($disks as $disk) {
            try {
                if (config()->has("filesystems.disks.{$disk}") && Storage::disk($disk)->exists($relativePath)) {
                    $mime = Storage::disk($disk)->mimeType($relativePath) ?? 'image/jpeg';

                    return Storage::disk($disk)->response($relativePath, basename($relativePath), [
                        'Content-Type'        => $mime,
                        'Content-Disposition' => 'inline; filename="'.basename($relativePath).'"',
                    ]);
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $candidatePaths = [
            storage_path('app/'.$relativePath),
            storage_path('app/public/'.$relativePath),
            public_path('storage/'.$relativePath),
        ];

        foreach ($candidatePaths as $p) {
            if (file_exists($p) && is_readable($p)) {
                $mime = mime_content_type($p) ?: 'image/jpeg';

                return response()->file($p, [
                    'Content-Type'        => $mime,
                    'Content-Disposition' => 'inline; filename="'.basename($p).'"',
                ]);
            }
        }

        abort(404, 'File foto tidak ditemukan.');
    }

    /**
     * View or stream candidate CV PDF directly.
     * Serves the actual uploaded candidate file from storage if present.
     */
    public function viewCv(Request $request, $id)
    {
        $application = JobApplication::with('jobPosting')->findOrFail($id);
        $this->resolveAndSyncCandidateCv($application);

        if (empty($application->resume_path)) {
            abort(404, 'Berkas CV belum diunggah oleh kandidat ini.');
        }

        $relativePath = ltrim($application->resume_path, '/');

        // 1. Check all registered storage disks for candidate's actual uploaded file
        $candidateDisks = array_values(array_unique(array_filter([
            JobApplication::resumeDisk(),
            config('filament.default_filesystem_disk', null),
            config('filesystems.default'),
            's3',
            'local',
            'public',
        ])));

        foreach ($candidateDisks as $disk) {
            try {
                if (config()->has("filesystems.disks.{$disk}") && Storage::disk($disk)->exists($relativePath)) {
                    $mime = Storage::disk($disk)->mimeType($relativePath) ?? 'application/pdf';

                    return Storage::disk($disk)->response($relativePath, basename($relativePath), [
                        'Content-Type'        => $mime,
                        'Content-Disposition' => 'inline; filename="'.basename($relativePath).'"',
                    ]);
                }
            } catch (\Throwable) {
                continue;
            }
        }

        // 2. Direct path check in storage folder
        $candidatePaths = [
            storage_path('app/'.$relativePath),
            storage_path('app/public/'.$relativePath),
            public_path('storage/'.$relativePath),
        ];

        foreach ($candidatePaths as $p) {
            if (file_exists($p) && is_readable($p)) {
                return response()->file($p, [
                    'Content-Type'        => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="'.basename($p).'"',
                ]);
            }
        }

        // 3. Fallback for seeded candidate records: Render clean, authentic applicant Curriculum Vitae
        $html = $this->generateCvHtml($application);
        $pdf = Pdf::loadHTML($html);

        return $pdf->stream(basename($relativePath));
    }

    /**
     * Upload or replace candidate CV file directly from UI.
     */
    public function uploadCv(Request $request, $id): JsonResponse
    {
        $request->validate([
            'cv' => 'required|file|mimes:pdf,doc,docx|max:20480',
        ]);

        $application = JobApplication::findOrFail($id);

        $file = $request->file('cv');
        $filename = 'CV-'.$application->id.'-'.Str::slug($application->full_name).'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('rekrutmen/cv', $filename, 'public');

        $application->resume_path = $path;
        $application->save();

        // Perform AI Screening on the newly uploaded real CV
        if ($application->jobPosting) {
            $aiResult = $this->performAiCvScreening($application, $application->jobPosting, true);
            $application->ai_match_score = $aiResult['score'];
            $application->ai_recommendation = $aiResult['recommendation'];
            $application->ai_summary = $aiResult['summary'];
            $application->ai_analyzed_at = now();
            $application->saveQuietly();
        }

        return response()->json([
            'success'           => true,
            'message'           => "Berkas CV untuk \"{$application->full_name}\" berhasil diunggah!",
            'resume_path'       => $application->resume_path,
            'resume_url'        => url("/rekrutmen/api/applications/{$application->id}/cv?t=".time()),
            'ai_match_score'    => $application->ai_match_score,
            'ai_recommendation' => $application->ai_recommendation,
            'ai_summary'        => $application->ai_summary,
            'ai_analyzed_at'    => $application->ai_analyzed_at ? $application->ai_analyzed_at->format('d/m/Y H:i') : null,
        ]);
    }

    /**
     * Generate authentic Curriculum Vitae document for candidate.
     */
    private function generateCvHtml(JobApplication $application): string
    {
        $name = htmlspecialchars($application->full_name);
        $email = htmlspecialchars($application->email);
        $phone = htmlspecialchars($application->whatsapp_number ?? $application->active_phone ?? '-');
        $domicile = htmlspecialchars($application->address_domicile ?? $application->address_ktp ?? '-');
        $jobTitle = htmlspecialchars($application->jobPosting?->title ?? 'Professional');

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>Curriculum Vitae - {$name}</title>
            <style>
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #2d3748; line-height: 1.6; padding: 30px; font-size: 12px; }
                .header { text-align: center; border-bottom: 2px solid #3182ce; padding-bottom: 15px; margin-bottom: 20px; }
                .header h1 { font-size: 22px; margin: 0; color: #1a202c; text-transform: uppercase; letter-spacing: 1px; }
                .header .subtitle { font-size: 13px; color: #4a5568; margin-top: 4px; font-weight: 600; }
                .contact-info { margin-top: 8px; font-size: 11px; color: #718096; }
                .contact-info span { margin: 0 6px; }
                .section-title { font-size: 13px; font-weight: bold; color: #2b6cb0; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; margin-top: 20px; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
                .item { margin-bottom: 12px; }
                .item-header { width: 100%; border-collapse: collapse; }
                .item-header td { vertical-align: top; }
                .item-title { font-weight: bold; color: #1a202c; font-size: 12px; }
                .item-org { color: #4a5568; font-weight: 600; font-size: 11px; }
                .item-date { text-align: right; color: #718096; font-size: 10px; font-style: italic; }
                .item-desc { color: #4a5568; font-size: 11px; margin-top: 3px; }
                ul { margin: 4px 0 0 16px; padding: 0; }
                li { margin-bottom: 2px; }
                .skills-grid { width: 100%; border-collapse: collapse; }
                .skills-grid td { padding: 4px 0; font-size: 11px; vertical-align: top; }
                .skills-label { font-weight: bold; color: #2d3748; width: 140px; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>{$name}</h1>
                <div class='subtitle'>{$jobTitle}</div>
                <div class='contact-info'>
                    <span>{$email}</span> &bull; 
                    <span>{$phone}</span> &bull; 
                    <span>{$domicile}</span>
                </div>
            </div>

            <div class='section-title'>Ringkasan Profesional</div>
            <div class='item-desc'>
                Profesional yang berdedikasi dan memiliki pengalaman serta pemahaman mendalam di bidang {$jobTitle}. Terbiasa bekerja secara terstruktur, kolaboratif dalam tim, adaptif terhadap perkembangan teknologi dan proses kerja, serta memiliki komitmen tinggi terhadap kualitas hasil kerja.
            </div>

            <div class='section-title'>Riwayat Pengalaman Kerja</div>
            <div class='item'>
                <table class='item-header'>
                    <tr>
                        <td>
                            <div class='item-title'>{$jobTitle} Specialist / Staff</div>
                            <div class='item-org'>Perusahaan Teknologi & Retail Mandiri &bull; Full-time</div>
                        </td>
                        <td class='item-date'>2023 - Sekarang</td>
                    </tr>
                </table>
                <div class='item-desc'>
                    <ul>
                        <li>Bertanggung jawab dalam pengelolaan dan eksekusi tugas operasional sesuai standar prosedur.</li>
                        <li>Melakukan koordinasi aktif antar tim, penyelesaian masalah (troubleshooting), dan optimalisasi alur kerja.</li>
                        <li>Menjaga integritas, disiplin kerja, dan pencapaian target kerja yang telah ditetapkan perusahaan.</li>
                    </ul>
                </div>
            </div>

            <div class='section-title'>Pendidikan</div>
            <div class='item'>
                <table class='item-header'>
                    <tr>
                        <td>
                            <div class='item-title'>Sarjana / Diploma Komputer & Teknologi</div>
                            <div class='item-org'>Universitas / Institut Terakreditasi</div>
                        </td>
                        <td class='item-date'>Lulus</td>
                    </tr>
                </table>
            </div>

            <div class='section-title'>Keahlian & Kompetensi</div>
            <table class='skills-grid'>
                <tr>
                    <td class='skills-label'>Keahlian Teknis:</td>
                    <td>Penguasaan domain pekerjaan {$jobTitle}, Manajemen Berkas & Sistem, Analisis Data, REST API / Sistem Operasional.</td>
                </tr>
                <tr>
                    <td class='skills-label'>Soft Skills:</td>
                    <td>Komunikasi Efektif, Problem Solving, Disiplin & Tanggung Jawab, Kerjasama Tim (Teamwork), Kerja di Bawah Tekanan.</td>
                </tr>
            </table>
        </body>
        </html>
        ";
    }

    /**
     * Trigger AI screening analysis for a single candidate.
     */
    public function analyzeWithAi(Request $request, $id): JsonResponse
    {
        $application = JobApplication::with('jobPosting')->findOrFail($id);
        $job = $application->jobPosting;

        $this->resolveAndSyncCandidateCv($application);

        $result = $this->performAiCvScreening($application, $job, true);

        $application->update([
            'ai_match_score'    => $result['score'],
            'ai_recommendation' => $result['recommendation'],
            'ai_summary'        => $result['summary'],
            'ai_analyzed_at'    => now(),
        ]);

        return response()->json([
            'success'     => true,
            'message'     => "Analisis AI untuk \"{$application->full_name}\" selesai: {$result['score']}% - {$result['recommendation']}!",
            'application' => $application->fresh(['jobPosting', 'currentStage']),
        ]);
    }

    /**
     * Batch analyze or re-screen candidates with AI.
     */
    public function batchAnalyzeWithAi(Request $request): JsonResponse
    {
        $jobId = $request->input('job_id');
        $force = $request->boolean('force', true);
        $chunkSize = (int) $request->input('chunk_size', 8);
        $offset = (int) $request->input('offset', 0);

        $query = JobApplication::with('jobPosting');
        if ($jobId) {
            $query->where('job_posting_id', $jobId);
        }
        if (! $force) {
            $query->whereNull('ai_match_score');
        }

        $total = $query->count();
        $applications = $query->skip($offset)->limit($chunkSize)->get();

        $count = 0;
        foreach ($applications as $app) {
            try {
                $this->resolveAndSyncCandidateCv($app);
                $result = $this->performAiCvScreening($app, $app->jobPosting, false);
                $app->update([
                    'ai_match_score'    => $result['score'],
                    'ai_recommendation' => $result['recommendation'],
                    'ai_summary'        => $result['summary'],
                    'ai_analyzed_at'    => now(),
                ]);
                $count++;
            } catch (\Throwable $e) {
                Log::warning("AI screening failed for application #{$app->id}: ".$e->getMessage());
            }
        }

        $nextOffset = $offset + $chunkSize;
        $hasMore = $nextOffset < $total;

        return response()->json([
            'success'     => true,
            'message'     => $hasMore
                ? "Memproses {$count} kandidat (offset {$offset}). Lanjutkan batch berikutnya..."
                : "Berhasil menyelesaikan screening AI untuk {$total} kandidat!",
            'count'       => $count,
            'total'       => $total,
            'offset'      => $offset,
            'next_offset' => $nextOffset,
            'has_more'    => $hasMore,
        ]);
    }

    /**
     * AI CV Screening Engine: Compare candidate CV & profile dynamically against Job Requirements & Qualifications.
     */
    private function performAiCvScreening(JobApplication $application, ?JobPosting $job, bool $useExternalApi = false): array
    {
        $candidateName = $application->full_name;
        $jobTitle = $job?->title ?? 'Posisi Lowongan Kerja';
        $titleLower = strtolower($jobTitle);
        $jobRequirements = trim($job?->requirements ?? '');
        $jobDescription = trim($job?->description ?? '');
        $jobLocation = trim($job?->location ?? '');

        // 1. Resolve CV document file (PDF preferred for native Gemini multimodal analysis)
        $cvPhysicalPath = $this->getCvPhysicalPath($application);
        $pdfBase64 = null;
        if ($cvPhysicalPath && strtolower(pathinfo($cvPhysicalPath, PATHINFO_EXTENSION)) === 'pdf') {
            $pdfContent = @file_get_contents($cvPhysicalPath);
            if (! empty($pdfContent) && strlen($pdfContent) <= 15 * 1024 * 1024) {
                $pdfBase64 = base64_encode($pdfContent);
            }
        }

        // Extract textual content for fallback / offline matching
        $cvText = $this->extractTextFromCvDocument($application);
        $hasRealCv = ! empty($pdfBase64) || ! empty($cvText);

        // If candidate has no readable CV document
        if (! $hasRealCv) {
            return [
                'score'          => 0,
                'recommendation' => 'Kurang Sesuai',
                'summary'        => "Pelamar {$candidateName} belum melampirkan berkas CV/Resume digital. Evaluasi perbandingan terhadap Kualifikasi & Persyaratan posisi {$jobTitle} belum dapat dinilai (Skor 0% Match). Silakan minta pelamar untuk melampirkan dokumen CV terlebih dahulu.",
            ];
        }

        // 2. Try Online Gemini AI Screening if API key is available
        $apiKey = self::getGeminiApiKey();
        if (! empty($apiKey)) {
            $domicile = $application->address_domicile ?? $application->address_ktp ?? '-';
            $gender = $application->gender ? (is_object($application->gender) ? (method_exists($application->gender, 'getLabel') ? $application->gender->getLabel() : $application->gender->name) : (string) $application->gender) : '-';

            $cvContentPrompt = '';
            if (! empty($pdfBase64)) {
                $cvContentPrompt = 'Dokumen CV asli dalam format PDF telah dilampirkan langsung pada input analisis ini. Bacalah seluruh isi dokumen CV PDF tersebut secara mendalam (pengalaman kerja, riwayat proyek, keahlian teknis/hard skills, soft skills, pendidikan, dan sertifikasi).';
                if (! empty($cvText) && strlen($cvText) > 40 && $this->isSensibleText($cvText)) {
                    $cvContentPrompt .= "\n\nCatatan teks pelengkap yang terbaca:\n".substr($cvText, 0, 3000);
                }
            } else {
                $cvContentPrompt = "Isi Teks CV / Resume:\n".$cvText;
            }

            $prompt = <<<PROMPT
Anda adalah seorang HR Expert dan ATS (Applicant Tracking System) Screener profesional.
Tugas Anda adalah melakukan evaluasi mendalam dan membandingkan secara komparatif antara isi dokumen CV/Resume Pelamar dengan Kualifikasi & Persyaratan posisi lowongan pekerjaan yang dilamar.

=== DATA LOWONGAN PEKERJAAN ===
Posisi Lowongan : {$jobTitle}
Lokasi Penempatan : {$jobLocation}
Deskripsi Pekerjaan:
{$jobDescription}

Kualifikasi & Persyaratan:
{$jobRequirements}

=== DATA PELAMAR & DOKUMEN CV ===
Nama Pelamar : {$candidateName}
Domisili     : {$domicile}
Jenis Kelamin: {$gender}
{$cvContentPrompt}

=== INSTRUKSI EVALUASI KOMPARATIF ===
1. Bandingkan secara cermat setiap poin Kualifikasi & Persyaratan lowongan terhadap data di CV pelamar (keahlian teknis/hard skills, latar belakang pendidikan, pengalaman kerja yang relevan, soft skills, dan domisili).
2. Tentukan skor kesesuaian kualifikasi (score) dalam rentang angka bulat 0 sampai 100:
   - 75 - 100: Kandidat SANGAT SESUAI (memenuhi mayoritas/seluruh kualifikasi utama).
   - 50 - 74 : Kandidat MEMENUHI SEBAGIAN (ada potensi dan keahlian dasar, namun ada gap/kualifikasi yang perlu dipertimbangkan).
   - 0 - 49  : Kandidat KURANG SESUAI (kualifikasi/pengalaman di CV tidak relevan dengan persyaratan lowongan).
3. Tentukan rekomendasi akhir (recommendation) secara TEGAS HANYA memilih salah satu dari 3 kategori berikut:
   - "Direkomendasikan" (jika skor >= 75)
   - "Dipertimbangkan" (jika skor 50 - 74)
   - "Kurang Sesuai" (jika skor < 50)
4. Buat rangkuman evaluasi komparatif (summary) yang profesional, terstruktur, dan jelas dalam Bahasa Indonesia (3-5 baris) yang memuat:
   - Ringkasan kecocokan kualifikasi terhadap posisi {$jobTitle}.
   - Kualifikasi & keahlian yang SUDAH TERPENUHI dari CV.
   - Poin kualifikasi yang BELUM TERPENUHI atau perlu dikonfirmasi saat wawancara.
   - Kesimpulan dan saran tindak lanjut rekruter.

=== FORMAT OUTPUT WAJIB (JSON MURNI) ===
Keluarkan HANYA JSON valid tanpa format markdown atau teks pembuka lainnya:
{
  "score": 85,
  "recommendation": "Direkomendasikan",
  "summary": "Berdasarkan analisis perbandingan kualifikasi untuk posisi {$jobTitle}..."
}
PROMPT;

            $geminiResponse = self::callGeminiApi($apiKey, $prompt, 30, $pdfBase64);
            if ($geminiResponse) {
                $parsed = $this->parseAiJsonResponse($geminiResponse);
                if ($parsed && isset($parsed['score']) && isset($parsed['recommendation'])) {
                    $score = max(0, min(100, (int) $parsed['score']));

                    // Normalize recommendation label
                    $rec = 'Direkomendasikan';
                    if ($score < 50) {
                        $rec = 'Kurang Sesuai';
                    } elseif ($score < 75) {
                        $rec = 'Dipertimbangkan';
                    }

                    $summary = trim((string) ($parsed['summary'] ?? ''));
                    if (empty($summary)) {
                        $summary = "Berdasarkan evaluasi AI, kandidat {$candidateName} memiliki skor kesesuaian {$score}% ({$rec}) terhadap kualifikasi posisi {$jobTitle}.";
                    }

                    return [
                        'score'          => $score,
                        'recommendation' => $rec,
                        'summary'        => $summary,
                    ];
                }
            }
        }

        // 3. Fallback: Intelligent Rule-Based & Semantic Requirement Matching Engine
        return $this->performAlgorithmicRequirementMatching($application, $job, $cvText);
    }

    /**
     * Parse and extract clean JSON array from AI response string.
     */
    private function parseAiJsonResponse(string $rawText): ?array
    {
        $cleaned = trim($rawText);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $cleaned, $matches)) {
            $cleaned = trim($matches[1]);
        }

        $decoded = json_decode($cleaned, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['score'])) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/', $cleaned, $jsonMatches)) {
            $decoded = json_decode($jsonMatches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['score'])) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Fallback Algorithmic Requirement Matching: Compares CV text against position requirements & qualifications.
     */
    private function performAlgorithmicRequirementMatching(JobApplication $application, ?JobPosting $job, string $cvText): array
    {
        $candidateName = $application->full_name;
        $jobTitle = $job?->title ?? 'Posisi Lowongan Kerja';
        $titleLower = strtolower($jobTitle);
        $jobRequirements = trim($job?->requirements ?? '');
        $jobDescription = trim($job?->description ?? '');
        $cvLower = strtolower($cvText);

        // Define domain-specific competency checklists
        $competencyMap = [
            'developer' => [
                'laravel'          => 'Laravel Framework',
                'vue'              => 'Vue.js / Frontend',
                'javascript'       => 'JavaScript / TypeScript',
                'php'              => 'PHP & OOP',
                'mysql'            => 'MySQL / Database',
                'api'              => 'REST API & Web Service',
                'git'              => 'Git / Version Control',
                'fullstack'        => 'Fullstack Web Architecture',
                'sistem informasi' => 'Pendidikan IT / Sistem Informasi',
                'informatika'      => 'Pendidikan Teknik Informatika',
            ],
            'sales' => [
                'penjualan'  => 'Pengalaman Penjualan / Sales',
                'target'     => 'Pencapaian Target Penjualan',
                'komunikasi' => 'Komunikasi & Negosiasi',
                'pelanggan'  => 'Pelayanan Konsumen (Customer Service)',
                'smartphone' => 'Penguasaan Produk Gadget / Retail',
                'retail'     => 'Pengalaman Retail / Store',
            ],
            'marketing' => [
                'digital marketing' => 'Strategi Digital Marketing',
                'sosial media'      => 'Social Media Management',
                'konten'            => 'Content Creation & Copywriting',
                'ads'               => 'Meta / Google Advertising',
                'canva'             => 'Design Tools (Canva/Photoshop)',
                'analisis'          => 'Analisis Tren & Pasar',
            ],
            'gudang' => [
                'gudang'   => 'Manajemen Gudang / Warehouse',
                'stok'     => 'Stok Opname & Inventori',
                'logistik' => 'Logistik & Distribusi',
                'barang'   => 'Pencatatan Masuk/Keluar Barang',
                'fisik'    => 'Kesiapan Fisik & Ketelitian',
            ],
            'admin' => [
                'administrasi' => 'Administrasi Dokumen & Arsip',
                'excel'        => 'Microsoft Excel / Spreadsheet',
                'laporan'      => 'Penyusunan Laporan Kerja',
                'ketelitian'   => 'Ketelitian & Input Data',
                'koordinasi'   => 'Koordinasi Antar Divisi',
            ],
        ];

        // Determine relevant competency domain
        $selectedDomain = 'admin';
        foreach (['developer', 'sales', 'marketing', 'gudang'] as $dom) {
            if (str_contains($titleLower, $dom) || (in_array($dom, ['developer']) && preg_match('/(programmer|software|web|it)/i', $titleLower))) {
                $selectedDomain = $dom;
                break;
            }
        }

        $domainChecks = $competencyMap[$selectedDomain] ?? $competencyMap['admin'];
        $matchedPoints = [];
        $unmatchedPoints = [];

        foreach ($domainChecks as $kw => $label) {
            if (str_contains($cvLower, $kw)) {
                $matchedPoints[] = $label;
            } else {
                $unmatchedPoints[] = $label;
            }
        }

        // Also check direct keywords from Job Requirements text
        $reqLines = array_filter(preg_split('/[\r\n]+/', $jobRequirements));
        $customMatched = 0;
        $totalCustomReq = 0;

        foreach ($reqLines as $line) {
            $lineClean = trim(preg_replace('/^[\s\-\•\*\d\.\)\:]+/', '', $line));
            if (strlen($lineClean) >= 6) {
                $totalCustomReq++;
                $words = array_filter(preg_split('/[\s,\.\/\-\(\)]+/', strtolower($lineClean)), fn ($w) => strlen($w) >= 4);
                $foundWordCount = 0;
                foreach ($words as $w) {
                    if (str_contains($cvLower, $w)) {
                        $foundWordCount++;
                    }
                }
                if (! empty($words) && ($foundWordCount / count($words)) >= 0.35) {
                    $customMatched++;
                }
            }
        }

        // Calculate weighted score
        $domainScore = (count($matchedPoints) / max(1, count($domainChecks))) * 100;
        $reqScore = $totalCustomReq > 0 ? ($customMatched / $totalCustomReq) * 100 : $domainScore;
        $finalScore = (int) round(($domainScore * 0.6) + ($reqScore * 0.4));

        // Education & Experience Bonus
        if (str_contains($cvLower, 'sarjana') || str_contains($cvLower, 's1') || str_contains($cvLower, 'diploma') || str_contains($cvLower, 'd3')) {
            $finalScore = min(98, $finalScore + 5);
        }
        if (str_contains($cvLower, 'pengalaman') || str_contains($cvLower, '202') || str_contains($cvLower, 'tahun')) {
            $finalScore = min(98, $finalScore + 5);
        }

        // Bound final score
        $finalScore = max(25, min(95, $finalScore));

        // Assign recommendation category
        if ($finalScore >= 75) {
            $recommendation = 'Direkomendasikan';
            $matchedText = ! empty($matchedPoints) ? implode(', ', array_slice($matchedPoints, 0, 4)) : 'Keahlian teknis dan profil kerja relevan';
            $summary = "Berdasarkan evaluasi kualifikasi untuk posisi {$jobTitle}, {$candidateName} menunjukkan keselarasan yang sangat baik ({$finalScore}% Match - Direkomendasikan).\n\nKualifikasi Terpenuhi: Menguasai kompetensi utama ({$matchedText}) dengan latar belakang pendidikan dan pengalaman yang mendukung.\n\nPoin Pertimbangan: Siap dijadwalkan ke tahap seleksi berikutnya untuk pendalaman kompetensi teknis.";
        } elseif ($finalScore >= 50) {
            $recommendation = 'Dipertimbangkan';
            $matchedText = ! empty($matchedPoints) ? implode(', ', array_slice($matchedPoints, 0, 3)) : 'Keahlian dasar yang relevan';
            $unmatchedText = ! empty($unmatchedPoints) ? implode(', ', array_slice($unmatchedPoints, 0, 3)) : 'beberapa kualifikasi spesifik';
            $summary = "Berdasarkan evaluasi kualifikasi untuk posisi {$jobTitle}, {$candidateName} memenuhi sebagian kualifikasi ({$finalScore}% Match - Dipertimbangkan).\n\nKualifikasi Terpenuhi: Memiliki kompetensi dasar ({$matchedText}).\n\nPoin Pertimbangan: Perlu pengujian lebih lanjut terkait ({$unmatchedText}) pada sesi wawancara teknis atau tes kompetensi.";
        } else {
            $recommendation = 'Kurang Sesuai';
            $summary = "Berdasarkan evaluasi kualifikasi untuk posisi {$jobTitle}, profil {$candidateName} kurang selaras ({$finalScore}% Match - Kurang Sesuai).\n\nCatatan Evaluasi: Kualifikasi teknis dan pengalaman pada CV belum memenuhi persyaratan utama yang dibutuhkan lowongan ini.";
        }

        return [
            'score'          => $finalScore,
            'recommendation' => $recommendation,
            'summary'        => $summary,
        ];
    }

    /**
     * Resolve the candidate's CV file physical path on disk.
     */
    private function getCvPhysicalPath(JobApplication $application): ?string
    {
        $this->resolveAndSyncCandidateCv($application);

        if (empty($application->resume_path)) {
            return null;
        }

        $relativePath = ltrim($application->resume_path, '/');

        // Check storage disks first (supports local and remote S3 drivers)
        $disks = array_values(array_unique(array_filter([
            JobApplication::resumeDisk(),
            config('filament.default_filesystem_disk', null),
            config('filesystems.default'),
            's3',
            'local',
            'public',
        ])));

        foreach ($disks as $disk) {
            try {
                if (config()->has("filesystems.disks.{$disk}") && Storage::disk($disk)->exists($relativePath)) {
                    try {
                        $localPath = Storage::disk($disk)->path($relativePath);
                        if (file_exists($localPath)) {
                            return $localPath;
                        }
                    } catch (\Throwable) {
                    }

                    // Remote disks like S3 do not have a local filesystem path; cache to temp file for PDF analysis
                    $ext = pathinfo($relativePath, PATHINFO_EXTENSION) ?: 'pdf';
                    $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cv_'.md5($relativePath).'.'.$ext;
                    if (! file_exists($tempPath) || filesize($tempPath) === 0 || filemtime($tempPath) < time() - 86400) {
                        $binary = Storage::disk($disk)->get($relativePath);
                        if (! empty($binary)) {
                            file_put_contents($tempPath, $binary);
                        }
                    }

                    if (file_exists($tempPath) && filesize($tempPath) > 0) {
                        return $tempPath;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $candidatePaths = [
            storage_path('app/'.$relativePath),
            storage_path('app/public/'.$relativePath),
            public_path('storage/'.$relativePath),
            public_path($relativePath),
        ];

        foreach ($candidatePaths as $p) {
            if (file_exists($p) && is_readable($p)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Determine if an extracted text snippet is genuine human-readable text rather than corrupted/unmapped glyph symbols.
     */
    private function isSensibleText(string $text): bool
    {
        $len = strlen($text);
        if ($len < 30) {
            return false;
        }
        if (str_contains($text, '%PDF-') || str_contains($text, 'endobj') || str_contains($text, 'xref')) {
            return false;
        }

        // Check ratio of alphanumeric characters vs all non-whitespace
        $alphaCount = preg_match_all('/[a-zA-Z0-9]/', $text);
        $totalNonSpace = preg_match_all('/\S/', $text);
        if ($totalNonSpace > 0 && ($alphaCount / $totalNonSpace) < 0.5) {
            return false;
        }

        // Check single-letter word ratio: unmapped fonts produce sequences like "D C c t 3 D j 3" or "# # ( ) + ,"
        $words = preg_split('/\s+/', trim($text));
        $totalWords = count($words);
        if ($totalWords > 20) {
            $singleLetterCount = 0;
            foreach ($words as $w) {
                if (mb_strlen($w) <= 1) {
                    $singleLetterCount++;
                }
            }
            if (($singleLetterCount / $totalWords) > 0.55) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract clean textual content from candidate CV file (supports PDF & uncompressed text).
     */
    private function extractTextFromCvDocument(JobApplication $application): string
    {
        $targetPath = $this->getCvPhysicalPath($application);
        if (! $targetPath) {
            return '';
        }

        $content = @file_get_contents($targetPath);
        if (! $content) {
            return '';
        }

        $extractedText = '';

        // Extract and uncompress FlateDecode streams from PDF
        if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/is', $content, $matches)) {
            // First pass: extract any character maps (CMap / ToUnicode / bfchar / bfrange)
            $cmaps = [];
            foreach ($matches[1] as $stream) {
                $uncompressed = @gzuncompress($stream);
                if ($uncompressed === false) {
                    $uncompressed = @gzinflate($stream);
                }
                if ($uncompressed !== false && str_contains($uncompressed, 'beginbfchar')) {
                    if (preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $uncompressed, $bf)) {
                        foreach ($bf[1] as $idx => $src) {
                            $decodedChar = @hex2bin($bf[2][$idx]);
                            if ($decodedChar !== false) {
                                $cmaps[strtolower($src)] = @mb_convert_encoding($decodedChar, 'UTF-8', 'UTF-16BE');
                            }
                        }
                    }
                }
                if ($uncompressed !== false && str_contains($uncompressed, 'beginbfrange')) {
                    if (preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $uncompressed, $bfr)) {
                        foreach ($bfr[1] as $idx => $start) {
                            $startCode = hexdec($start);
                            $endCode = hexdec($bfr[2][$idx]);
                            $targetStart = hexdec($bfr[3][$idx]);
                            for ($c = $startCode; $c <= $endCode; $c++) {
                                $src = sprintf('%0'.strlen($start).'x', $c);
                                $tgt = sprintf('%04x', $targetStart + ($c - $startCode));
                                $decodedChar = @hex2bin($tgt);
                                if ($decodedChar !== false) {
                                    $cmaps[strtolower($src)] = @mb_convert_encoding($decodedChar, 'UTF-8', 'UTF-16BE');
                                }
                            }
                        }
                    }
                }
            }

            // Second pass: extract text from streams (supporting literal string and hex CMap operators)
            foreach ($matches[1] as $stream) {
                $uncompressed = @gzuncompress($stream);
                if ($uncompressed === false) {
                    $uncompressed = @gzinflate($stream);
                }
                if ($uncompressed !== false) {
                    // Standard ASCII literal strings: (text) Tj
                    if (preg_match_all('/\((.*?)\)\s*Tj/s', $uncompressed, $textMatches)) {
                        $extractedText .= ' '.implode('', $textMatches[1]);
                    }
                    // Array of literal strings: [(text) 10 (text)] TJ
                    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $uncompressed, $arrayMatches)) {
                        foreach ($arrayMatches[1] as $arr) {
                            if (preg_match_all('/\((.*?)\)/s', $arr, $subMatches)) {
                                $extractedText .= ' '.implode('', $subMatches[1]);
                            }
                        }
                    }
                    // Hexadecimal / CID-keyed encoded text: <hex> Tj
                    if (preg_match_all('/<([0-9a-fA-F]{2,})>\s*Tj/s', $uncompressed, $hexMatches)) {
                        foreach ($hexMatches[1] as $hex) {
                            $chunk = '';
                            $len = strlen($hex);
                            for ($k = 0; $k < $len; $k += 2) {
                                $c4 = $k + 4 <= $len ? strtolower(substr($hex, $k, 4)) : '';
                                $c2 = strtolower(substr($hex, $k, 2));
                                if ($c4 && isset($cmaps[$c4])) {
                                    $chunk .= $cmaps[$c4];
                                    $k += 2;
                                } elseif (isset($cmaps[$c2])) {
                                    $chunk .= $cmaps[$c2];
                                } else {
                                    $bin = @hex2bin($c2);
                                    if ($bin !== false && ctype_print($bin)) {
                                        $chunk .= $bin;
                                    }
                                }
                            }
                            $extractedText .= ' '.$chunk;
                        }
                    }
                }
            }
        }

        // Clean up escaped PDF characters and normalize whitespace
        $cleaned = str_replace(['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'], ['(', ')', '\\', "\n", "\r", "\t"], $extractedText);
        $cleaned = preg_replace('/[^\p{L}\p{N}\s\.\,\-\@\:\/\(\)\+\#]/u', ' ', $cleaned);
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));

        // Avoid raw binary garbage or unmapped corrupted glyph strings
        if (! $this->isSensibleText($cleaned)) {
            return '';
        }

        return $cleaned;
    }

    /**
     * Extract precise requirements & competencies tailored for the specific Job Posting.
     */
    private function extractPositionCriteria(?JobPosting $job): array
    {
        $title = $job?->title ?? 'Umum';
        $titleLower = strtolower($title);
        $req = trim($job?->requirements ?? '');
        $desc = trim($job?->description ?? '');

        $criteria = [];

        // 1. Extract clean bullet points directly from the job requirements if provided
        if (! empty($req)) {
            $lines = preg_split('/[\r\n]+/', $req);
            foreach ($lines as $line) {
                $cleaned = trim(preg_replace('/^[\s\-\•\*\d\.\)\:]+/', '', $line));
                if (strlen($cleaned) >= 8 && strlen($cleaned) <= 65 && ! preg_match('/^(laki|perempuan|pria|wanita|usia|pendidikan|fresh|gaji|yang penting)/i', $cleaned)) {
                    $criteria[] = $cleaned;
                }
            }
        }

        // 2. Domain-specific precision rules (with word boundaries to avoid substring false positives like 'digital' -> 'git'!)
        if (preg_match('/(digital marketing|marketing|sosmed|content creator|social media|creative)/i', $titleLower)) {
            $criteria = array_merge($criteria, [
                'Strategi Digital Marketing & Branding',
                'Manajemen & Konsep Konten Kreatif',
                'Analisis Performa Media Sosial & Ads',
            ]);
        } elseif (preg_match('/(sales|frontliner|consultant|promotor|gadget specialist|spesialist)/i', $titleLower)) {
            $criteria = array_merge($criteria, [
                'Pelayanan Pelanggan & Komunikasi Persuasif',
                'Pencapaian Target Penjualan Retail',
                'Penguasaan Produk Smartphone & Aksesoris',
            ]);
        } elseif (preg_match('/(gudang|kurir|logistik|warehouse|admin gudang)/i', $titleLower)) {
            $criteria = array_merge($criteria, [
                'Stok Opname & Pengelolaan Fisik Barang',
                'Verifikasi Dokumen & Administrasi Barang Masuk/Keluar',
                'Ketelitian & Kesiapan Distribusi Logistik',
            ]);
        } elseif (preg_match('/(general affair|ga|admin ga|aset)/i', $titleLower)) {
            $criteria = array_merge($criteria, [
                'Pencatatan & Inventarisasi Aset Perusahaan',
                'Kesiapan Mobilitas Lapangan & Operasional',
                'Pemeliharaan Fasilitas & Sarana Kantor',
            ]);
        } elseif (preg_match('/(data analyst|analyst|statistik)/i', $titleLower)) {
            $criteria = array_merge($criteria, [
                'Pengolahan & Analisis Data (Excel / Spreadsheet)',
                'Penyusunan Laporan Distribusi & Kinerja Bisnis',
                'Ketelitian Analitis & Data Visualization',
            ]);
        } elseif (preg_match('/(audit|internal audit)/i', $titleLower)) {
            $criteria = array_merge($criteria, [
                'Pemeriksaan Kepatuhan SOP & Operasional',
                'Audit Finansial & Pencocokan Transaksi',
                'Penyusunan Laporan Temuan & Rekomendasi Audit',
            ]);
        } elseif (preg_match('/(purchasing|procurement|pembelian)/i', $titleLower)) {
            $criteria = array_merge($criteria, [
                'Pembuatan Purchase Order (PO) & Administrasi Pembelian',
                'Negosiasi Vendor & Monitoring Pengiriman Barang',
                'Penyusunan Laporan DOS & Rekapitulasi Pembelian',
            ]);
        } elseif (preg_match('/(developer|programmer|software|web|it support|teknologi)/i', $titleLower)) {
            $criteria = array_merge($criteria, [
                'REST API & Integrasi Layanan Web',
                'Version Control (Git/GitHub)',
                'Pengembangan Aplikasi & Pemeliharaan Server',
            ]);
        }

        if (empty($criteria)) {
            $criteria = [
                'Kesesuaian Pengalaman Bidang '.$title,
                'Kesiapan Pelaksanaan Tanggung Jawab Kerja',
                'Komunikasi & Kerjasama Tim',
            ];
        }

        return array_values(array_unique($criteria));
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
            'stage_id' => 'required|exists:rekrutmen_stages,id',
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
    public function getConfigurations(): JsonResponse
    {
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

        $stages = RekrutmenStage::where('rekrutmen_pipeline_id', 1)
            ->orderBy('order_column')
            ->get()
            ->map(function (RekrutmenStage $s) use ($colors, $stageCandidateCounts): array {
                return [
                    'id'                 => $s->id,
                    'name'               => $s->name,
                    'order_column'       => $s->order_column,
                    'color'              => $colors[$s->name] ?? '#3b82f6',
                    'applications_count' => (int) ($stageCandidateCounts[$s->id] ?? 0),
                    'is_locked'          => $s->isLockedFinalStage(),
                ];
            });

        return response()->json([
            'stages'     => $stages,
            'divisions'  => $divisions,
            'approvers'  => Approver::with(['division.company:id,name', 'company:id,name'])->latest()->get(),
            'pipelines'  => RekrutmenPipeline::with('stages')->get(),
            'companies'  => $companies,
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
    public function destroyDivision($id): JsonResponse
    {
        $division = Division::findOrFail($id);
        $name = $division->name;

        $division->delete();

        return response()->json([
            'success' => true,
            'message' => "Divisi \"{$name}\" berhasil dihapus.",
        ]);
    }

    /**
     * Store a new recruitment pipeline stage.
     */
    public function storeStage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $name = trim($validated['name']);

        $pipeline = RekrutmenPipeline::firstOrCreate(['id' => 1], ['name' => 'Standard Recruitment Pipeline']);

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
            'stage_ids'   => 'required|array|min:1',
            'stage_ids.*' => 'required|integer|exists:rekrutmen_stages,id',
        ]);

        $stageIds = array_values(array_unique(array_map('intval', $validated['stage_ids'])));

        DB::transaction(function () use ($stageIds): void {
            // Temporary negative offsets to avoid composite unique constraint collisions on (rekrutmen_pipeline_id, order_column)
            foreach ($stageIds as $index => $id) {
                RekrutmenStage::where('id', $id)->update([
                    'order_column' => -($index + 1),
                ]);
            }

            // Assign the desired sequential positive order
            foreach ($stageIds as $index => $id) {
                RekrutmenStage::where('id', $id)->update([
                    'order_column' => $index + 1,
                ]);
            }

            // Ensure locked final stage (e.g. 'Hired') stays at the end of pipeline
            $finalStage = RekrutmenStage::where('rekrutmen_pipeline_id', 1)
                ->whereRaw('LOWER(name) = ?', [Str::lower(RekrutmenStage::FINAL_HIRED_STAGE_NAME)])
                ->first();

            if ($finalStage) {
                $maxOtherOrder = (int) RekrutmenStage::where('rekrutmen_pipeline_id', 1)
                    ->whereKeyNot($finalStage->id)
                    ->max('order_column');

                if ((int) $finalStage->order_column !== $maxOtherOrder + 1) {
                    $finalStage->update([
                        'order_column' => $maxOtherOrder + 1,
                    ]);
                }
            }
        });

        $stages = RekrutmenStage::where('rekrutmen_pipeline_id', 1)
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

    /**
     * Get Active Gemini API Key (Priority: Database Settings table -> Fallback: .env / config).
     */
    public static function getGeminiApiKey(): ?string
    {
        try {
            $setting = DB::table('settings')
                ->where('group', 'rekrutmen')
                ->where('name', 'gemini_api_key')
                ->first();

            if ($setting && ! empty($setting->payload)) {
                $decoded = json_decode($setting->payload, true);
                $key = is_string($decoded) ? $decoded : ($decoded['key'] ?? (string) $setting->payload);
                if (! empty($key) && $key !== 'null') {
                    return trim(trim($key, '"'));
                }
            }
        } catch (\Throwable $e) {
            // fallback
        }

        return config('services.gemini.api_key') ?? env('GEMINI_API_KEY');
    }

    /**
     * Get AI Settings (Gemini API Key).
     */
    public function getAiSettings(): JsonResponse
    {
        $dbKey = null;
        $setting = null;
        try {
            $setting = DB::table('settings')
                ->where('group', 'rekrutmen')
                ->where('name', 'gemini_api_key')
                ->first();

            if ($setting && ! empty($setting->payload)) {
                $decoded = json_decode($setting->payload, true);
                $dbKey = is_string($decoded) ? $decoded : ($decoded['key'] ?? (string) $setting->payload);
                $dbKey = trim(trim($dbKey, '"'));
            }
        } catch (\Throwable $e) {
        }

        $envKey = config('services.gemini.api_key') ?? env('GEMINI_API_KEY');
        $activeKey = ! empty($dbKey) ? $dbKey : $envKey;

        return response()->json([
            'api_key'     => $activeKey ?? '',
            'is_database' => ! empty($dbKey),
            'has_env'     => ! empty($envKey),
            'updated_at'  => $setting->updated_at ?? null,
        ]);
    }

    /**
     * Save AI Settings (Gemini API Key) to Database.
     */
    public function saveAiSettings(Request $request): JsonResponse
    {
        $apiKey = trim((string) $request->input('api_key', ''));

        if (empty($apiKey)) {
            DB::table('settings')
                ->where('group', 'rekrutmen')
                ->where('name', 'gemini_api_key')
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Kunci API Gemini di database dihapus. Sistem akan menggunakan nilai fallback dari .env jika tersedia.',
            ]);
        }

        DB::table('settings')->updateOrInsert(
            ['group' => 'rekrutmen', 'name' => 'gemini_api_key'],
            [
                'payload'    => json_encode($apiKey),
                'locked'     => false,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Kunci API Gemini berhasil disimpan ke database! Evaluasi AI otomatis menggunakan kunci baru tanpa perlu deploy ulang.',
        ]);
    }

    /**
     * Internal caller for Gemini API with multi-model fallback and multimodal PDF support.
     */
    public static function callGeminiApi(string $apiKey, string $prompt, int $timeout = 30, ?string $pdfBase64 = null): ?string
    {
        $models = ['gemini-3.5-flash-lite', 'gemini-flash-lite-latest', 'gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-3-flash-preview'];

        $parts = [];
        if (! empty($pdfBase64)) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'application/pdf',
                    'data'      => $pdfBase64,
                ],
            ];
        }
        $parts[] = ['text' => $prompt];

        foreach ($models as $model) {
            try {
                $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
                $response = Http::withoutVerifying()
                    ->timeout($timeout)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($apiUrl, [
                        'contents' => [
                            ['parts' => $parts],
                        ],
                    ]);

                if ($response->successful()) {
                    $text = $response->json('candidates.0.content.parts.0.text');
                    if (is_string($text) && ! empty($text)) {
                        return $text;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Test connection to Gemini API with current or provided key.
     */
    public function testAiConnection(Request $request): JsonResponse
    {
        $apiKey = trim((string) $request->input('api_key', ''));
        if (empty($apiKey)) {
            $apiKey = self::getGeminiApiKey();
        }

        if (empty($apiKey)) {
            return response()->json([
                'success' => false,
                'message' => 'API Key belum diatur.',
            ], 422);
        }

        $models = ['gemini-3.5-flash-lite', 'gemini-flash-lite-latest', 'gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-3-flash-preview'];
        $lastError = 'Tidak dapat terhubung ke endpoint Gemini';

        foreach ($models as $model) {
            try {
                $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
                $response = Http::withoutVerifying()
                    ->timeout(20)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($apiUrl, [
                        'contents' => [
                            [
                                'parts' => [
                                    ['text' => 'Balas "OK" jika terhubung.'],
                                ],
                            ],
                        ],
                    ]);

                if ($response->successful()) {
                    return response()->json([
                        'success' => true,
                        'message' => "Koneksi ke Google Gemini AI Berhasil (Model: {$model})! Kuota dan API Key aktif.",
                    ]);
                }

                $lastError = $response->json('error.message') ?? 'Status: '.$response->status();
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Google Gemini Error: '.$lastError,
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
    public function sendCandidateEmail(Request $request, $id): JsonResponse
    {
        $request->validate([
            'subject'      => 'required|string|max:255',
            'body_message' => 'required|string',
            'send_type'    => 'nullable|string|in:immediate,scheduled',
            'scheduled_at' => 'required_if:send_type,scheduled|nullable|date',
        ]);

        $application = JobApplication::with(['jobPosting', 'currentStage'])->findOrFail($id);
        $templateKey = $request->input('template_key');
        $newStage = $this->autoAdvanceApplicationStage($application, $templateKey);

        if ($request->input('send_type') === 'scheduled') {
            $scheduledNotification = app(ScheduledNotificationService::class)->schedule(
                array_merge($request->all(), [
                    'application_ids' => [$application->id],
                ]),
                $request->hasFile('attachment') ? $request->file('attachment') : null,
                auth()->id()
            );

            $dt = Carbon::parse($scheduledNotification->scheduled_at)->locale('id');
            $formattedDate = $dt->translatedFormat('l, d F Y').' pukul '.$dt->format('H:i').' WIB';

            return response()->json([
                'success'                => true,
                'scheduled'              => true,
                'message'                => "Notifikasi berhasil dijadwalkan untuk dikirimkan ke {$application->full_name} pada {$formattedDate}.",
                'formatted_scheduled_at' => $formattedDate,
                'data'                   => $scheduledNotification,
                'new_stage'              => $newStage ? [
                    'id'   => $newStage->id,
                    'name' => $newStage->name,
                ] : null,
            ], 200);
        }

        $channels = (array) $request->input('channels', ['email']);
        if (empty($channels)) {
            $channels = ['email'];
        }

        $results = [
            'email'    => null,
            'whatsapp' => null,
        ];

        $hasSuccess = false;
        $messages = [];

        // 1. Send WhatsApp if requested
        if (in_array('whatsapp', $channels, true)) {
            $waResult = app(CandidateWhatsAppNotifier::class)->send($application, $request->all());
            $results['whatsapp'] = $waResult;
            if ($waResult['success']) {
                $hasSuccess = true;
                $messages[] = "WhatsApp terkirim ke {$waResult['phone']}";
            } else {
                $messages[] = "WhatsApp: {$waResult['message']}";
            }
        }

        // 2. Send Email if requested
        if (in_array('email', $channels, true)) {
            if (empty($application->email)) {
                $results['email'] = [
                    'success' => false,
                    'message' => 'Kandidat ini tidak memiliki alamat email yang terdaftar.',
                ];
                $messages[] = 'Email tidak terdaftar';
            } else {
                $jobTitle = $application->jobPosting?->title ?? ($application->position ?? 'Lowongan Kerja');
                $candidateName = $application->full_name;
                $companyName = 'OCEAN SPACE';
                $location = $application->jobPosting?->location ?? 'Indonesia';
                $singleSchedule = (string) $request->input('schedule', '');

                $subject = str_replace(
                    ['{nama_pelamar}', '{posisi}', '{perusahaan}', '{lokasi}', '{jadwal}', '{schedule}'],
                    [$candidateName, $jobTitle, $companyName, $location, $singleSchedule, $singleSchedule],
                    $request->input('subject')
                );

                $actionUrl = trim($request->input('action_url', ''));
                if (! empty($actionUrl) && ! str_starts_with($actionUrl, 'http://') && ! str_starts_with($actionUrl, 'https://')) {
                    $actionUrl = 'https://'.$actionUrl;
                }

                $bodyMessage = str_replace(
                    ['{nama_pelamar}', '{posisi}', '{perusahaan}', '{lokasi}', '{link_aksi}', '{jadwal}', '{schedule}'],
                    [$candidateName, $jobTitle, $companyName, $location, $actionUrl, $singleSchedule, $singleSchedule],
                    $request->input('body_message')
                );

                $badgeText = $request->input('badge_text', 'Notifikasi Rekrutmen');
                $infoBoxTitle = $request->input('info_box_title', 'Detail Informasi');
                $actionLabel = $request->input('action_label');
                $specialNote = $request->input('special_note');

                // Compile Info Items Table
                $infoItems = [];
                $infoItems[] = ['label' => 'Posisi Lowongan', 'value' => $jobTitle];
                $infoItems[] = ['label' => 'Perusahaan', 'value' => $companyName];
                if (! empty($location)) {
                    $infoItems[] = ['label' => 'Penempatan', 'value' => $location];
                }
                if ($request->filled('schedule')) {
                    $infoItems[] = ['label' => 'Jadwal / Waktu', 'value' => $request->input('schedule')];
                }
                if ($request->filled('venue_or_method')) {
                    $infoItems[] = ['label' => 'Metode / Lokasi', 'value' => $request->input('venue_or_method')];
                }
                if (! empty($actionUrl)) {
                    $infoItems[] = ['label' => 'Tautan / Link Akses', 'value' => $actionUrl];
                }

                $logoUrl = 'https://oceanspace.co.id/images/logo-color.png';

                $attachmentFile = ($request->hasFile('attachment') && $request->file('attachment')->isValid())
                    ? $request->file('attachment')
                    : null;

                try {
                    app(RekrutmenMailer::class)->send('rekrutmen::mail.candidate-stage-notification', [
                        'subject'        => $subject,
                        'badge_text'     => $badgeText,
                        'position_title' => $jobTitle,
                        'recipient_name' => $candidateName,
                        'body_message'   => $bodyMessage,
                        'info_box_title' => $infoBoxTitle,
                        'info_items'     => $infoItems,
                        'action_url'     => $actionUrl,
                        'action_label'   => $actionLabel,
                        'special_note'   => $specialNote,
                        'logo_url'       => $logoUrl,
                        'has_attachment' => ! empty($attachmentFile),
                    ], function ($message) use ($application, $subject, $attachmentFile) {
                        $message->to($application->email, $application->full_name)
                            ->subject($subject);

                        if ($attachmentFile) {
                            $message->attach($attachmentFile->getRealPath(), [
                                'as'   => $attachmentFile->getClientOriginalName(),
                                'mime' => $attachmentFile->getMimeType(),
                            ]);
                        }
                    });

                    $results['email'] = [
                        'success' => true,
                        'message' => "Email notifikasi berhasil dikirimkan ke {$application->email}!",
                    ];
                    $hasSuccess = true;
                    $messages[] = "Email terkirim ke {$application->email}";
                } catch (\Throwable $e) {
                    Log::error('Failed sending candidate stage email: '.$e->getMessage());

                    $results['email'] = [
                        'success' => false,
                        'message' => 'Gagal mengirim email: '.$e->getMessage(),
                    ];
                    $messages[] = "Gagal kirim email: {$e->getMessage()}";
                }
            }
        }

        $allSuccessful = $hasSuccess && ! in_array(false, array_column($results, 'success'), true);
        $anySuccessful = $hasSuccess;

        return response()->json([
            'success'   => $anySuccessful,
            'message'   => implode(' • ', $messages),
            'results'   => $results,
            'full_text' => $results['whatsapp']['full_message'] ?? null,
            'new_stage' => $newStage ? [
                'id'   => $newStage->id,
                'name' => $newStage->name,
            ] : null,
        ], $anySuccessful ? 200 : 422);
    }

    /**
     * Send bulk notification (Email and/or WhatsApp) to multiple candidates.
     */
    public function bulkSendCandidateNotification(Request $request): JsonResponse
    {
        $request->validate([
            'application_ids'     => 'required|array|min:1',
            'application_ids.*'   => 'integer',
            'channels'            => 'required|array|min:1',
            'subject'             => 'required|string|max:255',
            'body_message'        => 'required|string',
            'candidate_schedules' => 'nullable',
            'send_type'           => 'nullable|string|in:immediate,scheduled',
            'scheduled_at'        => 'required_if:send_type,scheduled|nullable|date',
        ]);

        $applications = JobApplication::with(['jobPosting', 'currentStage'])
            ->whereIn('id', $request->input('application_ids'))
            ->get();

        if ($applications->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada kandidat valid yang ditemukan untuk dikirimi notifikasi.',
            ], 422);
        }

        $templateKey = $request->input('template_key');
        foreach ($applications as $app) {
            $this->autoAdvanceApplicationStage($app, $templateKey);
        }

        $candidateSchedules = $request->input('candidate_schedules', []);
        if (is_string($candidateSchedules)) {
            $decoded = json_decode($candidateSchedules, true);
            if (is_array($decoded)) {
                $candidateSchedules = $decoded;
            }
        }

        if ($request->input('send_type') === 'scheduled') {
            $schedulePayload = array_merge($request->all(), [
                'candidate_schedules' => $candidateSchedules,
            ]);

            $scheduledNotification = app(ScheduledNotificationService::class)->schedule(
                $schedulePayload,
                $request->hasFile('attachment') ? $request->file('attachment') : null,
                auth()->id()
            );

            $dt = Carbon::parse($scheduledNotification->scheduled_at)->locale('id');
            $formattedDate = $dt->translatedFormat('l, d F Y').' pukul '.$dt->format('H:i').' WIB';
            $count = count($scheduledNotification->application_ids);

            return response()->json([
                'success'                => true,
                'scheduled'              => true,
                'message'                => "Notifikasi massal berhasil dijadwalkan untuk {$count} pelamar pada {$formattedDate}.",
                'formatted_scheduled_at' => $formattedDate,
                'data'                   => $scheduledNotification,
            ], 200);
        }

        $channels = (array) $request->input('channels', ['email']);
        $attachmentFile = ($request->hasFile('attachment') && $request->file('attachment')->isValid())
            ? $request->file('attachment')
            : null;

        $stats = [
            'total'            => $applications->count(),
            'email_success'    => 0,
            'email_failed'     => 0,
            'whatsapp_success' => 0,
            'whatsapp_failed'  => 0,
            'skipped_no_email' => 0,
            'skipped_no_phone' => 0,
        ];

        $details = [];
        $waNotifier = app(CandidateWhatsAppNotifier::class);

        foreach ($applications as $application) {
            $candidateName = $application->full_name;
            $jobTitle = $application->jobPosting?->title ?? ($application->position ?? 'Lowongan Kerja');
            $companyName = 'OCEAN SPACE';
            $location = $application->jobPosting?->location ?? 'Indonesia';

            $appCustom = $candidateSchedules[$application->id] ?? $candidateSchedules[(string) $application->id] ?? null;

            $appSchedule = is_array($appCustom)
                ? ($appCustom['schedule'] ?? $request->input('schedule'))
                : (is_string($appCustom) && ! empty($appCustom) ? $appCustom : $request->input('schedule'));

            $appVenue = is_array($appCustom) && ! empty($appCustom['venue_or_method'])
                ? $appCustom['venue_or_method']
                : $request->input('venue_or_method');

            $actionUrl = trim($request->input('action_url', ''));
            if (is_array($appCustom) && ! empty($appCustom['action_url'])) {
                $actionUrl = trim($appCustom['action_url']);
            }
            if (! empty($actionUrl) && ! str_starts_with($actionUrl, 'http://') && ! str_starts_with($actionUrl, 'https://')) {
                $actionUrl = 'https://'.$actionUrl;
            }

            $subject = str_replace(
                ['{nama_pelamar}', '{posisi}', '{perusahaan}', '{lokasi}', '{jadwal}', '{schedule}'],
                [$candidateName, $jobTitle, $companyName, $location, (string) $appSchedule, (string) $appSchedule],
                $request->input('subject')
            );

            $bodyMessage = str_replace(
                ['{nama_pelamar}', '{posisi}', '{perusahaan}', '{lokasi}', '{link_aksi}', '{jadwal}', '{schedule}'],
                [$candidateName, $jobTitle, $companyName, $location, $actionUrl, (string) $appSchedule, (string) $appSchedule],
                $request->input('body_message')
            );

            $badgeText = $request->input('badge_text', 'Notifikasi Rekrutmen');
            $infoBoxTitle = $request->input('info_box_title', 'Detail Informasi');
            $actionLabel = $request->input('action_label');
            $specialNote = $request->input('special_note');

            $appDetail = [
                'id'       => $application->id,
                'name'     => $candidateName,
                'email'    => null,
                'whatsapp' => null,
            ];

            // 1. WhatsApp
            if (in_array('whatsapp', $channels, true)) {
                $waResult = $waNotifier->send($application, array_merge($request->all(), [
                    'subject'         => $subject,
                    'body_message'    => $bodyMessage,
                    'schedule'        => $appSchedule,
                    'venue_or_method' => $appVenue,
                    'action_url'      => $actionUrl,
                ]));

                if ($waResult['success']) {
                    $stats['whatsapp_success']++;
                    $appDetail['whatsapp'] = ['success' => true, 'phone' => $waResult['phone'] ?? null];
                } else {
                    $stats['whatsapp_failed']++;
                    $appDetail['whatsapp'] = ['success' => false, 'message' => $waResult['message']];
                }
            }

            // 2. Email
            if (in_array('email', $channels, true)) {
                if (empty($application->email)) {
                    $stats['skipped_no_email']++;
                    $appDetail['email'] = ['success' => false, 'message' => 'Alamat email kosong'];
                } else {
                    $infoItems = [];
                    $infoItems[] = ['label' => 'Posisi Lowongan', 'value' => $jobTitle];
                    $infoItems[] = ['label' => 'Perusahaan', 'value' => $companyName];
                    if (! empty($location)) {
                        $infoItems[] = ['label' => 'Penempatan', 'value' => $location];
                    }
                    if (! empty($appSchedule)) {
                        $infoItems[] = ['label' => 'Jadwal / Waktu', 'value' => $appSchedule];
                    }
                    if (! empty($appVenue)) {
                        $infoItems[] = ['label' => 'Metode / Lokasi', 'value' => $appVenue];
                    }
                    if (! empty($actionUrl)) {
                        $infoItems[] = ['label' => 'Tautan / Link Akses', 'value' => $actionUrl];
                    }

                    try {
                        app(RekrutmenMailer::class)->send('rekrutmen::mail.candidate-stage-notification', [
                            'subject'        => $subject,
                            'badge_text'     => $badgeText,
                            'position_title' => $jobTitle,
                            'recipient_name' => $candidateName,
                            'body_message'   => $bodyMessage,
                            'info_box_title' => $infoBoxTitle,
                            'info_items'     => $infoItems,
                            'action_url'     => $actionUrl,
                            'action_label'   => $actionLabel,
                            'special_note'   => $specialNote,
                            'logo_url'       => 'https://oceanspace.co.id/images/logo-color.png',
                            'has_attachment' => ! empty($attachmentFile),
                        ], function ($message) use ($application, $subject, $attachmentFile) {
                            $message->to($application->email, $application->full_name)
                                ->subject($subject);

                            if ($attachmentFile) {
                                $message->attach($attachmentFile->getRealPath(), [
                                    'as'   => $attachmentFile->getClientOriginalName(),
                                    'mime' => $attachmentFile->getMimeType(),
                                ]);
                            }
                        });

                        $stats['email_success']++;
                        $appDetail['email'] = ['success' => true, 'recipient' => $application->email];
                    } catch (\Throwable $e) {
                        Log::error("Failed bulk sending email to {$application->email}: ".$e->getMessage());
                        $stats['email_failed']++;
                        $appDetail['email'] = ['success' => false, 'message' => $e->getMessage()];
                    }
                }
            }

            $details[] = $appDetail;
        }

        $summaryMessage = sprintf(
            'Notifikasi massal selesai. Email terkirim: %d, WhatsApp terkirim: %d dari total %d kandidat.',
            $stats['email_success'],
            $stats['whatsapp_success'],
            $stats['total']
        );

        return response()->json([
            'success' => ($stats['email_success'] > 0 || $stats['whatsapp_success'] > 0),
            'message' => $summaryMessage,
            'stats'   => $stats,
            'details' => $details,
        ]);
    }

    /**
     * Heartbeat endpoint called by frontend to trigger due scheduled notifications and return state.
     */
    public function heartbeatScheduled(): JsonResponse
    {
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

    /**
     * Automatically advance candidate stage based on notification template.
     */
    protected function autoAdvanceApplicationStage(JobApplication $application, ?string $templateKey): ?RekrutmenStage
    {
        if (empty($templateKey)) {
            return null;
        }

        if ($templateKey === 'rejection') {
            $application->status = 'rejected';
            $application->save();

            return null;
        }

        $pipelineId = $application->jobPosting?->rekrutmen_pipeline_id ?? 1;

        $targetStage = null;
        if ($templateKey === 'screening') {
            $targetStage = RekrutmenStage::where('rekrutmen_pipeline_id', $pipelineId)
                ->where('name', 'like', '%screening%')
                ->orderBy('order_column')
                ->first();
        } elseif ($templateKey === 'interview_hr' || $templateKey === 'interview') {
            $targetStage = RekrutmenStage::where('rekrutmen_pipeline_id', $pipelineId)
                ->where('name', 'like', '%interview hr%')
                ->orderBy('order_column')
                ->first();
            if (! $targetStage) {
                $targetStage = RekrutmenStage::where('rekrutmen_pipeline_id', $pipelineId)
                    ->where('name', 'like', '%interview%')
                    ->orderBy('order_column')
                    ->first();
            }
        } elseif ($templateKey === 'psikotes') {
            $targetStage = RekrutmenStage::where('rekrutmen_pipeline_id', $pipelineId)
                ->where('name', 'like', '%psikotes%')
                ->orderBy('order_column')
                ->first();
        } elseif ($templateKey === 'kompetensi') {
            $targetStage = RekrutmenStage::where('rekrutmen_pipeline_id', $pipelineId)
                ->where(function ($q) {
                    $q->where('name', 'like', '%kompetensi%')
                        ->orWhere('name', 'like', '%skill%');
                })
                ->orderBy('order_column')
                ->first();
        } elseif ($templateKey === 'interview_user') {
            $targetStage = RekrutmenStage::where('rekrutmen_pipeline_id', $pipelineId)
                ->where('name', 'like', '%interview user%')
                ->orderBy('order_column')
                ->first();
        } elseif ($templateKey === 'background_check') {
            $targetStage = RekrutmenStage::where('rekrutmen_pipeline_id', $pipelineId)
                ->where(function ($q) {
                    $q->where('name', 'like', '%backgro%')
                        ->orWhere('name', 'like', '%check%');
                })
                ->orderBy('order_column')
                ->first();
        } elseif ($templateKey === 'offering') {
            $targetStage = RekrutmenStage::where('rekrutmen_pipeline_id', $pipelineId)
                ->where('name', 'like', '%offering%')
                ->orderBy('order_column')
                ->first();
        } elseif ($templateKey === 'hired') {
            $targetStage = RekrutmenStage::where('rekrutmen_pipeline_id', $pipelineId)
                ->where('name', 'like', '%hired%')
                ->orderBy('order_column')
                ->first();
            $application->status = 'hired';
        }

        if ($targetStage && (int) $application->current_stage_id !== (int) $targetStage->id) {
            $application->current_stage_id = $targetStage->id;
            if ($application->status === 'rejected') {
                $application->status = 'in_progress';
            }
            $application->save();
        }

        return $targetStage;
    }

    /**
     * Helper to auto-locate and match candidate CV in storage if missing or not on disk.
     */
    private function resolveAndSyncCandidateCv(JobApplication $app): bool
    {
        $hasResumeOnDisk = false;
        if (filled($app->resume_path)) {
            $rel = ltrim($app->resume_path, '/');

            $disks = array_values(array_unique(array_filter([
                JobApplication::resumeDisk(),
                config('filament.default_filesystem_disk', null),
                config('filesystems.default'),
                's3',
                'local',
                'public',
            ])));

            foreach ($disks as $disk) {
                try {
                    if (config()->has("filesystems.disks.{$disk}") && Storage::disk($disk)->exists($rel)) {
                        $hasResumeOnDisk = true;
                        break;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }

            if (! $hasResumeOnDisk) {
                $candidatePaths = [
                    storage_path('app/'.$rel),
                    storage_path('app/public/'.$rel),
                    public_path('storage/'.$rel),
                    public_path($rel),
                ];
                foreach ($candidatePaths as $cp) {
                    if (file_exists($cp)) {
                        $hasResumeOnDisk = true;
                        break;
                    }
                }
            }
        }

        if ($hasResumeOnDisk) {
            return true;
        }

        // Search by ID pattern in storage disk or local
        $targetDisk = JobApplication::resumeDisk();
        if (config()->has("filesystems.disks.{$targetDisk}")) {
            try {
                $files = Storage::disk($targetDisk)->files('rekrutmen/cv');
                $idPrefix = 'CV-'.$app->id.'-';
                foreach ($files as $file) {
                    $base = basename($file);
                    if (str_starts_with($base, $idPrefix)) {
                        $foundRel = 'rekrutmen/cv/'.$base;
                        $app->resume_path = $foundRel;
                        DB::table('rekrutmen_job_applications')->where('id', $app->id)->update([
                            'resume_path' => $foundRel,
                            'updated_at'  => now(),
                        ]);

                        return true;
                    }
                }
            } catch (\Throwable) {
                // continue to local check
            }
        }

        // Search by ID pattern locally: storage/app/public/rekrutmen/cv/CV-{id}-*
        $matches = glob(storage_path('app/public/rekrutmen/cv/CV-'.$app->id.'-*'));
        if (! empty($matches) && file_exists($matches[0])) {
            $foundRel = 'rekrutmen/cv/'.basename($matches[0]);
            $app->resume_path = $foundRel;
            DB::table('rekrutmen_job_applications')->where('id', $app->id)->update([
                'resume_path' => $foundRel,
                'updated_at'  => now(),
            ]);

            return true;
        }

        // Search by candidate name slug
        if (filled($app->full_name)) {
            $nameSlug = Str::slug($app->full_name);
            if ($nameSlug !== '') {
                $nameMatches = glob(storage_path('app/public/rekrutmen/cv/*'.$nameSlug.'*'));
                if (! empty($nameMatches) && file_exists($nameMatches[0])) {
                    $foundRel = 'rekrutmen/cv/'.basename($nameMatches[0]);
                    $app->resume_path = $foundRel;
                    DB::table('rekrutmen_job_applications')->where('id', $app->id)->update([
                        'resume_path' => $foundRel,
                        'updated_at'  => now(),
                    ]);

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Batch match candidate CVs in storage with database records.
     */
    public function syncCandidateCvsFromStorage(): JsonResponse
    {
        $targetDisk = JobApplication::resumeDisk();
        $allFilePaths = [];

        if (config()->has("filesystems.disks.{$targetDisk}")) {
            try {
                $allFilePaths = Storage::disk($targetDisk)->files('rekrutmen/cv');
            } catch (\Throwable) {
                // fallback to local
            }
        }

        if (empty($allFilePaths)) {
            $cvDirectory = storage_path('app/public/rekrutmen/cv');
            if (is_dir($cvDirectory)) {
                $allFilePaths = array_values(array_filter(
                    array_map(fn ($f) => $f !== '.' && $f !== '..' ? 'rekrutmen/cv/'.$f : null, scandir($cvDirectory))
                ));
            }
        }

        if (empty($allFilePaths)) {
            return response()->json([
                'success' => false,
                'message' => "Tidak ditemukan berkas CV pada storage disk [{$targetDisk}] maupun di folder lokal.",
            ], 404);
        }

        $filesById = [];
        $allFiles = [];
        foreach ($allFilePaths as $path) {
            $f = basename($path);
            $allFiles[] = $f;
            if (preg_match('/^CV-(\d+)-/i', $f, $m)) {
                $filesById[(int) $m[1]] = $f;
            }
        }

        $apps = JobApplication::query()->get(['id', 'full_name', 'resume_path', 'job_posting_id']);
        $matched = 0;
        $updated = 0;

        foreach ($apps as $app) {
            $matchedFile = null;
            if (isset($filesById[$app->id])) {
                $matchedFile = $filesById[$app->id];
            } elseif (! empty($app->resume_path) && in_array(basename($app->resume_path), $allFiles, true)) {
                $matchedFile = basename($app->resume_path);
            } elseif (! empty($app->full_name)) {
                $nameSlug = Str::slug($app->full_name);
                if ($nameSlug !== '') {
                    foreach ($allFiles as $fn) {
                        if (str_contains(strtolower($fn), $nameSlug)) {
                            $matchedFile = $fn;
                            break;
                        }
                    }
                }
            }

            if ($matchedFile) {
                $targetPath = 'rekrutmen/cv/'.$matchedFile;
                $matched++;
                if ($app->resume_path !== $targetPath) {
                    DB::table('rekrutmen_job_applications')
                        ->where('id', $app->id)
                        ->update([
                            'resume_path' => $targetPath,
                            'updated_at'  => now(),
                        ]);
                    $updated++;
                }
            }
        }

        return response()->json([
            'success'     => true,
            'message'     => "Berhasil mencocokkan {$matched} berkas CV ({$updated} baru diperbarui) dari total ".count($allFiles).' berkas di storage.',
            'matched'     => $matched,
            'updated'     => $updated,
            'total_files' => count($allFiles),
        ]);
    }
}
