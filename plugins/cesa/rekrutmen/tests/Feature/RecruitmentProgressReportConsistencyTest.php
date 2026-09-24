<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Enums\JobApplicationGender;
use Cesa\Rekrutmen\Enums\JobApplicationMaritalStatus;
use Cesa\Rekrutmen\Enums\JobApplicationStatus;
use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Enums\StatusKebutuhan;
use Cesa\Rekrutmen\Livewire\RecruitmentProgressReport;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Services\RecruitmentProgressReportExport;
use Cesa\Rekrutmen\Services\RecruitmentProgressReportService;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Team;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class RecruitmentProgressReportConsistencyTest extends RekrutmenTestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'is_active' => true,
        ]);

        Permission::findOrCreate('view_any_cesa::rekrutmen::models::job::application::history', 'web');
        $this->user->givePermissionTo('view_any_cesa::rekrutmen::models::job::application::history');

        $this->actingAs($this->user);
    }

    public function test_activity_summary_uses_compact_text_when_all_entries_have_same_result(): void
    {
        app()->setLocale('id');

        $this->assertSame('12 Orang Lolos', RecruitmentProgressReportService::activitySummaryText(12, 12, 0, 0));
        $this->assertSame('3 Orang Tidak Lolos', RecruitmentProgressReportService::activitySummaryText(3, 0, 3, 0));
        $this->assertSame('2 Orang Menunggu', RecruitmentProgressReportService::activitySummaryText(2, 0, 0, 2));
        $this->assertSame('12 Orang 10 Lolos 2 Menunggu', RecruitmentProgressReportService::activitySummaryText(12, 10, 0, 2));
    }

    public function test_report_limits_every_section_to_allowed_postings(): void
    {
        [$allowedPosting, $allowedStage] = $this->createFixtureForDivision('IT', 'allowed-report-posting');
        [$otherPosting, $otherStage] = $this->createFixtureForDivision('Finance', 'other-report-posting');

        $allowedCandidate = $this->makeJobApplication($allowedPosting, $allowedStage, 'allowed-report@example.com', 'Allowed Candidate');
        $otherCandidate = $this->makeJobApplication($otherPosting, $otherStage, 'other-report@example.com', 'Other Candidate');

        foreach ([[$allowedPosting, $allowedStage, $allowedCandidate], [$otherPosting, $otherStage, $otherCandidate]] as [$posting, $stage, $candidate]) {
            JobApplication::recordBatchActivity(
                $posting->id,
                $stage->id,
                '2026-04-07',
                [['job_application_id' => $candidate->id, 'result' => 'pending', 'notes' => 'Pending']],
                $this->user->id,
            );
        }

        $filters = ['date_from' => '2026-04-01', 'date_to' => '2026-04-30'];
        $reportService = app(RecruitmentProgressReportService::class);

        $unrestrictedReport = $reportService->build($filters);
        $this->assertEqualsCanonicalizing([$allowedPosting->id, $otherPosting->id], $unrestrictedReport['posting_ids']);

        $report = $reportService->build([...$filters, 'allowed_posting_ids' => [$allowedPosting->id]]);

        $this->assertSame([$allowedPosting->id], $report['posting_ids']);
        $this->assertSame([$allowedPosting->id], $report['postings']->pluck('id')->all());
        $this->assertSame(1, $report['summary']['total_positions_active']);
        $this->assertSame(1, $report['summary']['total_activities_this_period']);
        $this->assertSame([$allowedPosting->id], $report['positions']->map(fn (array $position): int => $position['posting']->id)->all());
        $this->assertSame([$allowedPosting->id], $report['overview']->pluck('job_posting_id')->all());
        $this->assertSame([$allowedPosting->id], $report['activities']->pluck('job_posting_id')->all());
        $this->assertSame([$allowedPosting->id], $report['timeline']->first()['activities']->pluck('job_posting_id')->all());
        $this->assertSame($allowedCandidate->full_name, $report['activities']->first()['entries']->first()->jobApplication->full_name);

        foreach ([
            ['allowed_posting_ids' => []],
            ['allowed_posting_ids' => [$allowedPosting->id], 'job_posting_id' => $otherPosting->id],
        ] as $restrictedFilters) {
            $emptyReport = $reportService->build([...$filters, ...$restrictedFilters]);

            $this->assertSame([], $emptyReport['posting_ids']);
            $this->assertSame(0, $emptyReport['summary']['total_positions_active']);
            $this->assertSame(0, $emptyReport['summary']['total_activities_this_period']);
            $this->assertTrue($emptyReport['postings']->isEmpty());
            $this->assertTrue($emptyReport['positions']->isEmpty());
            $this->assertTrue($emptyReport['overview']->isEmpty());
            $this->assertTrue($emptyReport['activities']->isEmpty());
            $this->assertTrue($emptyReport['timeline']->isEmpty());
            $this->assertTrue($emptyReport['hr_kpis']->isEmpty());
        }
    }

    public function test_progress_api_scopes_report_positions_by_creator_and_team_without_posting_view_permission(): void
    {
        [$ownPosting, $ownStage] = $this->createFixtureForDivision('IT', 'api-own-report');
        $teammate = User::factory()->create(['is_active' => true]);
        $outsider = User::factory()->create(['is_active' => true]);

        $this->actingAs($teammate);
        [$teamPosting, $teamStage] = $this->createFixtureForDivision('Finance', 'api-team-report');

        $this->actingAs($outsider);
        [$outsidePosting, $outsideStage] = $this->createFixtureForDivision('Sales', 'api-outside-report');

        foreach ([
            [$ownPosting, $ownStage],
            [$teamPosting, $teamStage],
            [$outsidePosting, $outsideStage],
        ] as [$posting, $stage]) {
            $candidate = $this->makeJobApplication($posting, $stage, "api-report-{$posting->id}@example.com", "Candidate {$posting->id}");
            JobApplication::recordBatchActivity(
                $posting->id,
                $stage->id,
                '2026-04-07',
                [['job_application_id' => $candidate->id, 'result' => 'pending', 'notes' => 'Pending']],
                $this->user->id,
            );
        }

        $this->actingAs($this->user);
        $this->assertFalse($this->user->can('viewAny', JobPosting::class));

        $url = '/api/recruitment/progress-report?date_from=2026-04-01&date_to=2026-04-30';
        $individualReport = $this->getJson($url)->assertOk();
        $this->assertSame([$ownPosting->id], collect($individualReport->json('positions'))->pluck('job_posting_id')->all());
        $this->getJson($url.'&job_posting_id='.$teamPosting->id)
            ->assertOk()
            ->assertJsonPath('summary.total_positions_active', 0)
            ->assertJsonCount(0, 'positions');

        $this->user->update(['resource_permission' => PermissionType::GROUP]);
        $this->actingAs($this->user->fresh());
        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'positions');

        $team = Team::query()->create(['name' => 'Progress API Team']);
        $this->user->teams()->attach($team->id);
        $teammate->teams()->attach($team->id);

        $groupReport = $this->getJson($url)->assertOk();
        $this->assertEqualsCanonicalizing(
            [$ownPosting->id, $teamPosting->id],
            collect($groupReport->json('positions'))->pluck('job_posting_id')->all(),
        );

        $groupTimeline = $this->getJson('/api/recruitment/progress-report/timeline?date_from=2026-04-01&date_to=2026-04-30')->assertOk();
        $this->assertEqualsCanonicalizing(
            [$ownPosting->id, $teamPosting->id],
            collect($groupTimeline->json('timeline'))->flatMap(fn (array $day): array => $day['activities'])->pluck('job_posting_id')->all(),
        );

        $groupOverview = $this->getJson('/api/recruitment/progress-report/overview?date_from=2026-04-01&date_to=2026-04-30')->assertOk();
        $this->assertEqualsCanonicalizing(
            [$ownPosting->id, $teamPosting->id],
            collect($groupOverview->json('overview'))->pluck('job_posting_id')->all(),
        );

        $this->user->update(['resource_permission' => PermissionType::GLOBAL]);
        $this->actingAs($this->user->fresh());
        $globalReport = $this->getJson($url)->assertOk();
        $this->assertEqualsCanonicalizing(
            [$ownPosting->id, $teamPosting->id, $outsidePosting->id],
            collect($globalReport->json('positions'))->pluck('job_posting_id')->all(),
        );

        $noPostingUser = User::factory()->create(['is_active' => true]);
        $noPostingUser->givePermissionTo('view_any_cesa::rekrutmen::models::job::application::history');
        $this->actingAs($noPostingUser);
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('summary.total_positions_active', 0)
            ->assertJsonCount(0, 'positions');
    }

    public function test_report_endpoints_use_consistent_pipeline_based_counts(): void
    {
        [$jobPosting, $firstStage] = $this->createFixtureForDivision('IT', 'backend-engineer-report');
        [$otherPosting, $otherStage] = $this->createFixtureForDivision('Finance', 'finance-analyst-report');

        $passedCandidate = $this->makeJobApplication($jobPosting, $firstStage, 'passed-report@example.com', 'Passed Candidate');
        $failedCandidate = $this->makeJobApplication($jobPosting, $firstStage, 'failed-report@example.com', 'Failed Candidate');
        $deletedCandidate = $this->makeJobApplication($jobPosting, $firstStage, 'deleted-report@example.com', 'Deleted Candidate');
        $otherCandidate = $this->makeJobApplication($otherPosting, $otherStage, 'other-report@example.com', 'Other Candidate');
        $deletedCandidate->delete();

        JobApplication::recordBatchActivity(
            $jobPosting->id,
            $firstStage->id,
            '2026-04-07',
            [
                ['job_application_id' => $passedCandidate->id, 'result' => 'passed', 'notes' => 'Proceed'],
                ['job_application_id' => $failedCandidate->id, 'result' => 'failed', 'notes' => 'Reject'],
            ],
            $this->user->id,
        );

        $passedCandidate->refresh();

        Carbon::setTestNow('2026-04-08 09:00:00');

        try {
            $passedCandidate->markAsHired('Accepted', $this->user->id);
        } finally {
            Carbon::setTestNow();
        }

        $otherCandidate->update([
            'status' => JobApplicationStatus::IN_PROGRESS,
        ]);

        $reportResponse = $this->getJson('/api/recruitment/progress-report?job_posting_id='.$jobPosting->id.'&date_from=2026-04-01&date_to=2026-04-30');
        $reportResponse
            ->assertOk()
            ->assertJsonPath('summary.total_positions_active', 1)
            ->assertJsonPath('summary.total_candidates_in_process', 0)
            ->assertJsonPath('summary.total_activities_this_period', 1)
            ->assertJsonPath('summary.total_hired_this_period', 1)
            ->assertJsonPath('summary.total_rejected_this_period', 1)
            ->assertJsonPath('activities.0.counts.total', 2)
            ->assertJsonPath('activities.0.counts.passed', 1)
            ->assertJsonPath('activities.0.counts.failed', 1)
            ->assertJsonPath('positions.0.total_applicants', 2)
            ->assertJsonPath('positions.0.in_progress', 0)
            ->assertJsonPath('positions.0.hired', 1)
            ->assertJsonPath('positions.0.hired_candidates.0.full_name', 'PASSED CANDIDATE')
            ->assertJsonPath('positions.0.rejected', 1);

        $timelineResponse = $this->getJson('/api/recruitment/progress-report/timeline?job_posting_id='.$jobPosting->id.'&date_from=2026-04-01&date_to=2026-04-30');
        $timelineResponse
            ->assertOk()
            ->assertJsonPath('timeline.0.activity.counts.total', 2)
            ->assertJsonPath('timeline.0.activity.counts.passed', 1)
            ->assertJsonPath('timeline.0.activity.counts.failed', 1);

        $overviewResponse = $this->getJson('/api/recruitment/progress-report/overview?job_posting_id='.$jobPosting->id.'&date_from=2026-04-01&date_to=2026-04-30');
        $overviewResponse
            ->assertOk()
            ->assertJsonPath('overview.0.total_applicants', 2)
            ->assertJsonPath('overview.0.in_progress', 0)
            ->assertJsonPath('overview.0.hired', 1)
            ->assertJsonPath('overview.0.hired_candidates.0.full_name', 'PASSED CANDIDATE')
            ->assertJsonPath('overview.0.rejected', 1)
            ->assertJsonPath('overview.0.latest_activity.summary', '2 Orang 1 Lolos 1 Tidak Lolos');
    }

    public function test_hired_candidate_withdrawn_before_onboarding_does_not_fulfill_manpower_need(): void
    {
        [$jobPosting, $firstStage] = $this->createFixtureForDivision('IT', 'withdrawn-hired-report');

        $jobPosting->requestManPower()->update([
            'tanggal_pengajuan'          => '2026-04-01',
            'jumlah_karyawan_dibutuhkan' => 1,
        ]);

        $candidate = $this->makeJobApplication($jobPosting, $firstStage, 'withdrawn-hired-report@example.com', 'Withdrawn Hired');
        $finalStage = $candidate->nextStageAfterCurrentStage();
        $this->assertNotNull($finalStage);
        $candidate->transitionToStage($finalStage->id, 'Move to final decision.');
        $candidate->refresh();
        $candidate->markAsHired('Accepted', $this->user->id, '2026-04-08');
        $candidate->markAsWithdrawn('Candidate resigned before onboarding.', $this->user->id, '2026-04-10');

        $response = $this->getJson('/api/recruitment/progress-report?job_posting_id='.$jobPosting->id.'&date_from=2026-04-01&date_to=2026-04-30');

        $response
            ->assertOk()
            ->assertJsonPath('summary.total_hired_this_period', 0)
            ->assertJsonPath('positions.0.needed', 1)
            ->assertJsonPath('positions.0.hired', 0)
            ->assertJsonPath('positions.0.fulfillment_percentage', 0)
            ->assertJsonCount(0, 'positions.0.hired_candidates');

        $overviewResponse = $this->getJson('/api/recruitment/progress-report/overview?job_posting_id='.$jobPosting->id.'&date_from=2026-04-01&date_to=2026-04-30');

        $overviewResponse
            ->assertOk()
            ->assertJsonPath('overview.0.hired', 0)
            ->assertJsonPath('overview.0.fulfillment_percentage', 0)
            ->assertJsonCount(0, 'overview.0.hired_candidates');
    }

    public function test_timeline_endpoint_keeps_multiple_activities_on_the_same_day(): void
    {
        [$firstPosting, $firstStage] = $this->createFixtureForDivision('IT', 'timeline-first');
        [$secondPosting, $secondStage] = $this->createFixtureForDivision('IT', 'timeline-second');

        $firstCandidate = $this->makeJobApplication($firstPosting, $firstStage, 'timeline-first@example.com', 'Timeline First');
        $secondCandidate = $this->makeJobApplication($secondPosting, $secondStage, 'timeline-second@example.com', 'Timeline Second');

        JobApplication::recordBatchActivity(
            $firstPosting->id,
            $firstStage->id,
            '2026-04-07',
            [
                ['job_application_id' => $firstCandidate->id, 'result' => 'pending', 'notes' => 'Pending'],
            ],
            $this->user->id,
        );

        JobApplication::recordBatchActivity(
            $secondPosting->id,
            $secondStage->id,
            '2026-04-07',
            [
                ['job_application_id' => $secondCandidate->id, 'result' => 'pending', 'notes' => 'Pending'],
            ],
            $this->user->id,
        );

        $timelineResponse = $this->getJson('/api/recruitment/progress-report/timeline?date_from=2026-04-01&date_to=2026-04-30');

        $timelineResponse
            ->assertOk()
            ->assertJsonPath('timeline.0.count', 2);

        $this->assertCount(2, $timelineResponse->json('timeline.0.activities'));
    }

    public function test_report_timeline_groups_duplicate_stage_updates_for_the_same_position(): void
    {
        [$jobPosting, $firstStage] = $this->createFixtureForDivision('IT', 'duplicate-stage-digest-report');

        $firstCandidate = $this->makeJobApplication($jobPosting, $firstStage, 'digest-first@example.com', 'Digest First');
        $secondCandidate = $this->makeJobApplication($jobPosting, $firstStage, 'digest-second@example.com', 'Digest Second');

        JobApplication::recordBatchActivity(
            $jobPosting->id,
            $firstStage->id,
            '2026-04-07',
            [
                ['job_application_id' => $firstCandidate->id, 'result' => 'passed', 'notes' => 'Proceed'],
            ],
            $this->user->id,
        );

        JobApplication::recordBatchActivity(
            $jobPosting->id,
            $firstStage->id,
            '2026-04-07',
            [
                ['job_application_id' => $secondCandidate->id, 'result' => 'passed', 'notes' => 'Proceed'],
            ],
            $this->user->id,
        );

        $report = app(RecruitmentProgressReportService::class)->build([
            'date_from'      => '2026-04-01',
            'date_to'        => '2026-04-30',
            'job_posting_id' => $jobPosting->id,
        ]);

        $this->assertSame(1, $report['summary']['total_activities_this_period']);
        $this->assertSame(1, $report['timeline']->first()['count']);
        $this->assertSame(2, $report['timeline']->first()['candidate_count']);
        $this->assertSame(2, $report['timeline']->first()['activities']->first()['activity_count']);
        $this->assertSame('2 Orang Lolos', $report['timeline']->first()['activities']->first()['summary']);
        $this->assertCount(2, $report['timeline']->first()['activities']->first()['entries']);
    }

    public function test_report_page_omits_stage_filter_and_ignores_legacy_stage_query(): void
    {
        [$itPosting, $itStage] = $this->createFixtureForDivision('IT', 'stage-option-it-report');
        [$financePosting] = $this->createFixtureForDivision('Finance', 'stage-option-finance-report');

        $component = Livewire::withQueryParams(['stage' => (string) $itStage->id])
            ->test(RecruitmentProgressReport::class)
            ->assertSee('Antrian MPP')
            ->assertSeeInOrder([
                'Aktivitas dari',
                'Snapshot MPP sampai',
                'Perusahaan',
                'Posisi / Lowongan',
            ])
            ->assertSee('Cari atau pilih perusahaan')
            ->assertSee('Cari posisi atau lowongan')
            ->assertDontSee('Tahap Aktivitas')
            ->assertSee($itPosting->title)
            ->assertSee($financePosting->title);

        $component
            ->set('jobPostingId', $itPosting->id)
            ->set('companyId', $financePosting->requestManPower?->company_id)
            ->assertSet('jobPostingId', null);
    }

    public function test_company_report_filter_ignores_soft_deleted_linked_manpower_requests(): void
    {
        $deletedCompany = Company::query()->create([
            'name' => 'PT Deleted Report Company',
        ]);
        $activeCompany = Company::query()->create([
            'name' => 'PT Active Report Company',
        ]);
        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Shared Report Pipeline',
        ]);

        $deletedRequest = RequestManPower::query()->create([
            'company_id'                 => $deletedCompany->id,
            'email_address'              => 'deleted-report-company@example.com',
            'nama_pengaju'               => 'Deleted Requester',
            'posisi_pengaju'             => 'Manager',
            'tanggal_pengajuan'          => '2026-04-01',
            'posisi_dibutuhkan'          => 'Shared Advisor',
            'lokasi_penempatan'          => 'Jakarta',
            'status_kebutuhan'           => StatusKebutuhan::NEW_HIRING,
            'divisi'                     => 'Sales',
            'level_pekerjaan'            => 'Staff',
            'jumlah_karyawan_dibutuhkan' => 1,
            'estimasi_tanggal_join'      => '2026-05-01',
            'requirements_kualifikasi'   => 'Requirement',
            'job_description'            => 'Job description',
            'status'                     => RequestManPowerStatus::APPROVED,
        ]);
        $activeRequest = RequestManPower::query()->create([
            'company_id'                 => $activeCompany->id,
            'email_address'              => 'active-report-company@example.com',
            'nama_pengaju'               => 'Active Requester',
            'posisi_pengaju'             => 'Manager',
            'tanggal_pengajuan'          => '2026-04-01',
            'posisi_dibutuhkan'          => 'Shared Advisor',
            'lokasi_penempatan'          => 'Jakarta',
            'status_kebutuhan'           => StatusKebutuhan::NEW_HIRING,
            'divisi'                     => 'Sales',
            'level_pekerjaan'            => 'Staff',
            'jumlah_karyawan_dibutuhkan' => 2,
            'estimasi_tanggal_join'      => '2026-05-01',
            'requirements_kualifikasi'   => 'Requirement',
            'job_description'            => 'Job description',
            'status'                     => RequestManPowerStatus::APPROVED,
        ]);

        $jobPosting = JobPosting::query()->create([
            'request_man_power_id'  => $deletedRequest->id,
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Shared Advisor Jakarta',
            'slug'                  => 'shared-advisor-jakarta',
            'description'           => 'Serve customers',
            'requirements'          => 'Retail experience',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $activeRequest->forceFill([
            'job_posting_id' => $jobPosting->id,
        ])->saveQuietly();
        $deletedRequest->delete();

        $deletedCompanyResponse = $this->getJson('/api/recruitment/progress-report?company_id='.$deletedCompany->id);
        $deletedCompanyResponse
            ->assertOk()
            ->assertJsonPath('summary.total_positions_active', 0)
            ->assertJsonCount(0, 'positions');

        $activeCompanyResponse = $this->getJson('/api/recruitment/progress-report?company_id='.$activeCompany->id);
        $activeCompanyResponse
            ->assertOk()
            ->assertJsonPath('summary.total_positions_active', 1)
            ->assertJsonPath('positions.0.needed', 2);
    }

    public function test_report_is_mpp_cycle_based_and_surfaces_cycle_health_risks(): void
    {
        [$jobPosting] = $this->createFixtureForDivision('IT', 'cycle-health-report');

        $jobPosting->requestManPower()->update([
            'status'                     => RequestManPowerStatus::APPROVED,
            'tanggal_pengajuan'          => '2026-04-01',
            'jumlah_karyawan_dibutuhkan' => 1,
        ]);
        $jobPosting->update([
            'is_published' => false,
        ]);

        $orphanPipeline = RekrutmenPipeline::query()->create([
            'name' => 'Orphan Cycle Pipeline',
        ]);

        JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $orphanPipeline->id,
            'title'                 => 'Orphan KPI Posting',
            'slug'                  => 'orphan-kpi-posting',
            'description'           => 'Should not be counted as MPP KPI.',
            'requirements'          => 'No MPP link.',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $response = $this->getJson('/api/recruitment/progress-report?date_from=2026-04-01&date_to=2026-04-30');

        $response
            ->assertOk()
            ->assertJsonPath('summary.total_positions_active', 1)
            ->assertJsonPath('summary.total_cycle_health_issues', 1)
            ->assertJsonPath('positions.0.job_posting_id', $jobPosting->id)
            ->assertJsonPath('positions.0.cycle_health.status', 'risk')
            ->assertJsonPath('positions.0.cycle_health.issues.0.key', 'posting_unpublished');

        $this->assertSame(
            [$jobPosting->id],
            collect($response->json('positions'))->pluck('job_posting_id')->all(),
        );

        Livewire::test(RecruitmentProgressReport::class)
            ->set('dateFrom', '2026-04-01')
            ->set('dateTo', '2026-04-30')
            ->call('setFocus', 'data-risk')
            ->assertSee($jobPosting->title)
            ->assertSee('Lowongan belum publish');
    }

    public function test_hr_kpi_counts_hired_headcount_and_mpp_fulfillment_from_closing_hire_pic(): void
    {
        [$jobPosting, $firstStage] = $this->createFixtureForDivision('IT', 'hr-kpi-fulfillment-report');
        $firstHr = $this->user;
        $closingHr = User::factory()->create([
            'name'      => 'Closing HR',
            'is_active' => true,
        ]);

        $jobPosting->requestManPower()->update([
            'tanggal_pengajuan'          => '2026-04-01',
            'jumlah_karyawan_dibutuhkan' => 2,
            'status'                     => RequestManPowerStatus::APPROVED,
        ]);

        $firstCandidate = $this->makeJobApplication($jobPosting, $firstStage, 'hr-kpi-first@example.com', 'HR KPI First');
        $secondCandidate = $this->makeJobApplication($jobPosting, $firstStage, 'hr-kpi-second@example.com', 'HR KPI Second');

        foreach ([$firstCandidate, $secondCandidate] as $candidate) {
            $finalStage = $candidate->nextStageAfterCurrentStage();
            $this->assertNotNull($finalStage);
            $candidate->transitionToStage($finalStage->id, 'Move to final decision.');
            $candidate->refresh();
        }

        $firstCandidate->markAsHired('Accepted first.', $firstHr->id, '2026-04-05');
        $secondCandidate->markAsHired('Accepted second.', $closingHr->id, '2026-04-08');

        $response = $this->getJson('/api/recruitment/progress-report?job_posting_id='.$jobPosting->id.'&date_from=2026-04-01&date_to=2026-04-30');

        $response
            ->assertOk()
            ->assertJsonPath('summary.total_hr_kpi_people', 2)
            ->assertJsonPath('summary.total_hr_kpi_hired_headcount', 2)
            ->assertJsonPath('summary.total_hr_kpi_fulfilled_mpp', 1)
            ->assertJsonPath('hr_kpis.0.performer_name', 'Closing HR')
            ->assertJsonPath('hr_kpis.0.hired_headcount', 1)
            ->assertJsonPath('hr_kpis.0.fulfilled_mpp', 1)
            ->assertJsonPath('hr_kpis.0.fulfilled_requests.0.request_id', $jobPosting->requestManPower->id)
            ->assertJsonPath('hr_kpis.1.performer_name', $firstHr->name)
            ->assertJsonPath('hr_kpis.1.hired_headcount', 1)
            ->assertJsonPath('hr_kpis.1.fulfilled_mpp', 0);

        Livewire::test(RecruitmentProgressReport::class)
            ->set('jobPostingId', $jobPosting->id)
            ->set('dateFrom', '2026-04-01')
            ->set('dateTo', '2026-04-30')
            ->assertDontSee('KPI HR Fulfillment MPP');
    }

    public function test_livewire_report_renders_same_activity_summary_without_querying_extra_state(): void
    {
        [$jobPosting, $firstStage] = $this->createFixtureForDivision('IT', 'qa-engineer-report');

        $firstCandidate = $this->makeJobApplication($jobPosting, $firstStage, 'livewire-1@example.com', 'Livewire One');
        $secondCandidate = $this->makeJobApplication($jobPosting, $firstStage, 'livewire-2@example.com', 'Livewire Two');

        JobApplication::recordBatchActivity(
            $jobPosting->id,
            $firstStage->id,
            '2026-04-07',
            [
                ['job_application_id' => $firstCandidate->id, 'result' => 'pending', 'notes' => 'Pending'],
                ['job_application_id' => $secondCandidate->id, 'result' => 'passed', 'notes' => 'Proceed'],
            ],
            $this->user->id,
        );

        $secondCandidate->refresh();

        Carbon::setTestNow('2026-04-08 09:00:00');

        try {
            $secondCandidate->markAsHired('Accepted', $this->user->id);
        } finally {
            Carbon::setTestNow();
        }

        $component = Livewire::test(RecruitmentProgressReport::class)
            ->set('jobPostingId', $jobPosting->id)
            ->set('dateFrom', '2026-04-01')
            ->set('dateTo', '2026-04-30')
            ->assertSee($jobPosting->title)
            ->assertSee('Antrian MPP')
            ->assertSee('Monitor kandidat aktif')
            ->assertSee('PT IT Core')
            ->assertSee('LIVEWIRE TWO');

        $component
            ->call('setFocus', 'updated')
            ->assertSee($firstStage->name)
            ->assertSee('2 Orang 1 Lolos 1 Menunggu');
    }

    public function test_current_hired_candidates_stay_visible_when_the_selected_period_has_no_updates(): void
    {
        [$jobPosting, $firstStage] = $this->createFixtureForDivision('IT', 'outside-period-hired-report');

        $jobPosting->requestManPower()->update([
            'tanggal_pengajuan'          => '2026-04-01',
            'jumlah_karyawan_dibutuhkan' => 1,
        ]);

        $candidate = $this->makeJobApplication($jobPosting, $firstStage, 'outside-period-hired@example.com', 'Outside Period Hired');
        $finalStage = $candidate->nextStageAfterCurrentStage();
        $this->assertNotNull($finalStage);
        $candidate->transitionToStage($finalStage->id, 'Move to final decision.');
        $candidate->refresh();
        $candidate->markAsHired('Accepted', $this->user->id, '2026-04-08');

        $response = $this->getJson('/api/recruitment/progress-report?job_posting_id='.$jobPosting->id.'&date_from=2026-05-01&date_to=2026-05-31');

        $response
            ->assertOk()
            ->assertJsonPath('summary.total_activities_this_period', 0)
            ->assertJsonPath('summary.total_hired_this_period', 0)
            ->assertJsonPath('summary.total_hr_kpi_hired_headcount', 0)
            ->assertJsonPath('summary.total_hr_kpi_fulfilled_mpp', 0)
            ->assertJsonCount(0, 'hr_kpis')
            ->assertJsonPath('positions.0.needed', 1)
            ->assertJsonPath('positions.0.hired', 1)
            ->assertJsonPath('positions.0.fulfillment_percentage', 100)
            ->assertJsonPath('positions.0.hired_candidates.0.full_name', 'OUTSIDE PERIOD HIRED');

        Livewire::test(RecruitmentProgressReport::class)
            ->set('jobPostingId', $jobPosting->id)
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->call('setFocus', 'fulfilled')
            ->assertSee($jobPosting->title)
            ->assertSee('Hired saat ini')
            ->assertSee('OUTSIDE PERIOD HIRED')
            ->assertSee('Hired 08 Apr 2026')
            ->assertSee('Update periode ini')
            ->assertSee('Tidak ada update aktivitas pada periode ini.');
    }

    public function test_mpp_snapshot_excludes_future_requests_and_future_hires(): void
    {
        [$snapshotPosting, $snapshotStage] = $this->createFixtureForDivision('IT', 'snapshot-mpp-report');
        [$futurePosting] = $this->createFixtureForDivision('Finance', 'future-mpp-report');

        $snapshotPosting->requestManPower()->update([
            'tanggal_pengajuan'          => '2025-04-01',
            'estimasi_tanggal_join'      => '2025-04-20',
            'jumlah_karyawan_dibutuhkan' => 2,
            'status'                     => RequestManPowerStatus::APPROVED,
        ]);

        $futurePosting->requestManPower()->update([
            'tanggal_pengajuan'          => '2026-04-01',
            'estimasi_tanggal_join'      => '2026-04-20',
            'jumlah_karyawan_dibutuhkan' => 1,
            'status'                     => RequestManPowerStatus::APPROVED,
        ]);

        $futureHiredCandidate = $this->makeJobApplication($snapshotPosting, $snapshotStage, 'snapshot-future-hired@example.com', 'Snapshot Future Hired');
        $finalStage = $futureHiredCandidate->nextStageAfterCurrentStage();
        $this->assertNotNull($finalStage);
        $futureHiredCandidate->transitionToStage($finalStage->id, 'Move to final decision.');
        $futureHiredCandidate->refresh();
        $futureHiredCandidate->markAsHired('Accepted after snapshot.', $this->user->id, '2026-05-05');

        $response = $this->getJson('/api/recruitment/progress-report?date_from=2025-01-01&date_to=2025-05-09');

        $response
            ->assertOk()
            ->assertJsonPath('summary.total_positions_active', 1)
            ->assertJsonCount(1, 'positions')
            ->assertJsonPath('positions.0.job_posting_id', $snapshotPosting->id)
            ->assertJsonPath('positions.0.needed', 2)
            ->assertJsonPath('positions.0.hired', 0)
            ->assertJsonPath('positions.0.request_fulfillments.0.snapshot_date', '2025-05-09')
            ->assertJsonPath('positions.0.request_fulfillments.0.remaining', 2)
            ->assertJsonPath('positions.0.request_fulfillments.0.estimate_missed', true);

        Livewire::test(RecruitmentProgressReport::class)
            ->set('dateFrom', '2025-01-01')
            ->set('dateTo', '2025-05-09')
            ->assertSee($snapshotPosting->title)
            ->assertDontSee($futurePosting->title)
            ->assertSee('0/2');
    }

    public function test_missed_estimated_join_request_stays_visible_as_outstanding_mpp_snapshot(): void
    {
        [$jobPosting] = $this->createFixtureForDivision('IT', 'missed-estimate-mpp-report');

        $jobPosting->requestManPower()->update([
            'tanggal_pengajuan'          => '2026-03-01',
            'estimasi_tanggal_join'      => '2026-03-15',
            'jumlah_karyawan_dibutuhkan' => 2,
            'status'                     => RequestManPowerStatus::APPROVED,
        ]);

        $response = $this->getJson('/api/recruitment/progress-report?job_posting_id='.$jobPosting->id.'&date_from=2026-04-01&date_to=2026-04-30');

        $response
            ->assertOk()
            ->assertJsonPath('summary.total_activities_this_period', 0)
            ->assertJsonPath('positions.0.needed', 2)
            ->assertJsonPath('positions.0.hired', 0)
            ->assertJsonPath('positions.0.request_fulfillments.0.request_id', $jobPosting->requestManPower->id)
            ->assertJsonPath('positions.0.request_fulfillments.0.request_date', '2026-03-01')
            ->assertJsonPath('positions.0.request_fulfillments.0.estimated_join', '2026-03-15')
            ->assertJsonPath('positions.0.request_fulfillments.0.snapshot_date', '2026-04-30')
            ->assertJsonPath('positions.0.request_fulfillments.0.needed', 2)
            ->assertJsonPath('positions.0.request_fulfillments.0.fulfilled', 0)
            ->assertJsonPath('positions.0.request_fulfillments.0.remaining', 2)
            ->assertJsonPath('positions.0.request_fulfillments.0.age_days', 60)
            ->assertJsonPath('positions.0.request_fulfillments.0.estimate_missed', true)
            ->assertJsonPath('positions.0.request_fulfillments.0.fulfillment_status', 'open');

        Livewire::test(RecruitmentProgressReport::class)
            ->set('jobPostingId', $jobPosting->id)
            ->set('dateFrom', '2026-04-01')
            ->set('dateTo', '2026-04-30')
            ->assertSee('MPP dari Request Man Power')
            ->assertSee('Snapshot s/d 30 Apr 2026')
            ->assertSee('60 hari')
            ->assertSee('2 / 0 / 2')
            ->assertSee('Est. join lewat, sisa 2 orang tetap dihitung.');
    }

    public function test_livewire_export_downloads_a_monthly_mpp_workbook_that_is_easy_to_read(): void
    {
        Excel::fake();
        Carbon::setTestNow('2026-04-07 10:00:00');

        try {
            [$aprilPosting, $aprilStage] = $this->createFixtureForDivision('IT', 'backend-engineer-export');
            [$mayPosting, $mayStage] = $this->createFixtureForDivision('Finance', 'finance-analyst-export');

            $aprilPosting->requestManPower()->update([
                'tanggal_pengajuan'          => '2026-04-02',
                'estimasi_tanggal_join'      => '2026-04-15',
                'posisi_dibutuhkan'          => 'Backend Engineer',
                'jumlah_karyawan_dibutuhkan' => 2,
                'status'                     => RequestManPowerStatus::APPROVED,
            ]);

            $mayPosting->requestManPower()->update([
                'tanggal_pengajuan'          => '2026-04-05',
                'estimasi_tanggal_join'      => '2026-05-15',
                'posisi_dibutuhkan'          => 'Finance Analyst',
                'jumlah_karyawan_dibutuhkan' => 1,
                'status'                     => RequestManPowerStatus::HOLD,
            ]);

            $aprilRequest = $aprilPosting->requestManPower()->firstOrFail();
            $linkedAprilRequest = RequestManPower::query()->create([
                'company_id'                 => $aprilRequest->company_id,
                'email_address'              => 'backend-engineer-linked-export@example.com',
                'nama_pengaju'               => 'Requester IT Linked',
                'posisi_pengaju'             => 'Manager',
                'tanggal_pengajuan'          => '2026-04-03',
                'posisi_dibutuhkan'          => 'Backend Engineer Batch 2',
                'lokasi_penempatan'          => 'Jakarta',
                'status_kebutuhan'           => StatusKebutuhan::NEW_HIRING,
                'divisi'                     => 'IT',
                'level_pekerjaan'            => 'Staff',
                'jumlah_karyawan_dibutuhkan' => 1,
                'estimasi_tanggal_join'      => '2026-04-20',
                'requirements_kualifikasi'   => 'Requirement',
                'job_description'            => 'Job description',
                'keterangan'                 => 'Testing',
                'status'                     => RequestManPowerStatus::APPROVED,
                'job_posting_id'             => $aprilPosting->id,
            ]);

            $aprilHiredCandidate = $this->makeJobApplication($aprilPosting, $aprilStage, 'april-hired@example.com', 'April Hired');
            $aprilPendingCandidate = $this->makeJobApplication($aprilPosting, $aprilStage, 'april-pending@example.com', 'April Pending');
            $mayCandidate = $this->makeJobApplication($mayPosting, $mayStage, 'may-pipeline@example.com', 'May Pipeline');

            JobApplication::recordBatchActivity(
                $aprilPosting->id,
                $aprilStage->id,
                '2026-04-07',
                [
                    ['job_application_id' => $aprilHiredCandidate->id, 'result' => 'passed', 'notes' => 'Proceed to offer'],
                    ['job_application_id' => $aprilPendingCandidate->id, 'result' => 'pending', 'notes' => 'Waiting for user'],
                ],
                $this->user->id,
            );

            $aprilHiredCandidate->refresh();
            $aprilHiredCandidate->markAsHired('Accepted', $this->user->id);
            $aprilHiredCandidate->refresh();
            $mayCandidate->refresh();

            Livewire::test(RecruitmentProgressReport::class)
                ->set('dateFrom', '2026-04-01')
                ->set('dateTo', '2026-04-30')
                ->call('exportExcel')
                ->assertFileDownloaded();

            Excel::assertDownloaded('recruitment-progress-mpp-20260401-to-20260430.xlsx', function (RecruitmentProgressReportExport $export) use ($aprilPosting, $aprilRequest, $linkedAprilRequest): bool {
                $sheets = $export->sheets();

                $this->assertCount(4, $sheets);
                $this->assertSame('Overview MPP', $sheets[0]->title());
                $this->assertSame('Ringkasan Bulanan', $sheets[1]->title());
                $this->assertSame('Detail Posisi', $sheets[2]->title());
                $this->assertSame('Aktivitas Rekrutmen', $sheets[3]->title());
                $this->assertWorkbookSheetMatchesOverviewMppLayout($sheets[1], 'K');
                $this->assertWorkbookSheetMatchesOverviewMppLayout($sheets[2], 'U');
                $this->assertWorkbookSheetMatchesOverviewMppLayout($sheets[3], 'O');

                $overviewRows = collect($sheets[0]->array())->values();
                $summaryRows = collect($sheets[1]->array())->slice(5)->values();
                $detailRows = collect($sheets[2]->array())->slice(5)->values();
                $activityRows = collect($sheets[3]->array())->slice(5)->values();

                $this->assertTrue($overviewRows->contains(fn (array $row): bool => ($row[0] ?? null) === 'OVERVIEW MPP BULAN APRIL 2026'));
                $this->assertFalse($overviewRows->contains(fn (array $row): bool => str_starts_with((string) ($row[0] ?? ''), 'KARYAWAN JOIN BULAN')));
                $this->assertSame([
                    'BULAN',
                    'ID PERMINTAAN MPP',
                    'ID LOWONGAN',
                    'BADAN USAHA',
                    'TANGGAL REQ',
                    'SNAPSHOT',
                    'UMUR MPP/DAY',
                    'KEBUTUHAN MPP',
                    'JOIN BULAN INI',
                    'JOIN S/D SNAPSHOT',
                    'SISA MPP',
                    'POSISI',
                    'PENEMPATAN',
                    'USER',
                    'PIC TERAKHIR / JOIN',
                    'REPLACEMENT/NEW HIRING',
                    'STATUS REQUEST',
                    'STATUS PEMENUHAN',
                    'KETERANGAN REPLACEMENT',
                    'TANGGAL UPDATE PROGRES',
                    'KARYAWAN JOIN BULAN INI',
                ], $overviewRows->first(fn (array $row): bool => ($row[0] ?? null) === 'BULAN'));

                $this->assertTrue($overviewRows->contains(fn (array $row): bool => ($row[3] ?? null) === 'PT FINANCE CORE'
                    && ($row[7] ?? null) === 1
                    && ($row[10] ?? null) === 1
                    && ($row[11] ?? null) === 'FINANCE ANALYST'
                    && ($row[16] ?? null) === 'HOLD'
                    && ($row[17] ?? null) === 'HOLD'));

                $this->assertTrue($overviewRows->contains(fn (array $row): bool => ($row[1] ?? null) === sprintf('MPP-UID-%06d', $aprilRequest->id)
                    && ($row[2] ?? null) === sprintf('LOWONGAN-UID-%06d', $aprilPosting->id)
                    && ($row[3] ?? null) === 'PT IT CORE'
                    && ($row[4] ?? null) === '02 Apr 2026'
                    && ($row[6] ?? null) === 28
                    && ($row[7] ?? null) === 2
                    && ($row[8] ?? null) === 1
                    && ($row[9] ?? null) === 1
                    && ($row[10] ?? null) === 1
                    && ($row[11] ?? null) === 'BACKEND ENGINEER'
                    && ($row[20] ?? null) === 'APRIL HIRED'));

                $this->assertTrue($overviewRows->contains(fn (array $row): bool => ($row[1] ?? null) === sprintf('MPP-UID-%06d', $linkedAprilRequest->id)
                    && ($row[2] ?? null) === sprintf('LOWONGAN-UID-%06d', $aprilPosting->id)
                    && ($row[3] ?? null) === 'PT IT CORE'
                    && ($row[4] ?? null) === '03 Apr 2026'
                    && ($row[7] ?? null) === 1
                    && ($row[8] ?? null) === 0
                    && ($row[10] ?? null) === 1
                    && ($row[11] ?? null) === mb_strtoupper($linkedAprilRequest->posisi_dibutuhkan)));

                $this->assertTrue($overviewRows->contains(fn (array $row): bool => ($row[0] ?? null) === 'TOTAL'
                    && ($row[7] ?? null) === 4
                    && ($row[8] ?? null) === 1
                    && ($row[9] ?? null) === 1
                    && ($row[10] ?? null) === 3));

                $this->assertTrue($summaryRows->contains(fn (array $row): bool => $row[0] === 'APRIL 2026'
                    && $row[2] === 3
                    && $row[3] === 4
                    && $row[4] === 1
                    && $row[5] === 1
                    && $row[6] === 3
                    && $row[7] === 1));

                $this->assertTrue($detailRows->contains(fn (array $row): bool => ($row[0] ?? null) === sprintf('MPP-UID-%06d', $aprilRequest->id)
                    && ($row[1] ?? null) === sprintf('LOWONGAN-UID-%06d', $aprilPosting->id)
                    && in_array('PT IT CORE', $row, true)
                    && in_array('BACKEND ENGINEER', $row, true)
                    && in_array('Pipeline belum cukup, perlu percepatan', $row, true)));

                $this->assertTrue($detailRows->contains(fn (array $row): bool => in_array('PT FINANCE CORE', $row, true)
                    && in_array('FINANCE ANALYST', $row, true)
                    && in_array('HOLD', $row, true)
                    && in_array('Hold - menunggu keputusan user', $row, true)));

                $this->assertTrue($activityRows->contains(fn (array $row): bool => ($row[2] ?? null) === 'PT IT CORE'
                    && ($row[3] ?? null) === sprintf('LOWONGAN-UID-%06d', $aprilPosting->id)
                    && ($row[4] ?? null) === $aprilPosting->title
                    && str_contains((string) ($row[5] ?? ''), sprintf('MPP-UID-%06d', $aprilRequest->id))
                    && str_contains((string) ($row[5] ?? ''), sprintf('MPP-UID-%06d', $linkedAprilRequest->id))
                    && str_contains((string) ($row[6] ?? ''), 'BACKEND ENGINEER')
                    && str_contains((string) ($row[6] ?? ''), 'BACKEND ENGINEER BATCH 2')
                    && ($row[10] ?? null) === 2
                    && ($row[11] ?? null) === 1
                    && ($row[13] ?? null) === 1));

                return true;
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array{0: JobPosting, 1: RekrutmenStage}
     */
    private function createFixtureForDivision(string $division, string $slug): array
    {
        $company = Company::query()->create([
            'name' => "PT {$division} Core",
        ]);

        $requestManPower = RequestManPower::query()->create([
            'company_id'                 => $company->id,
            'email_address'              => "{$slug}@example.com",
            'nama_pengaju'               => "Requester {$division}",
            'posisi_pengaju'             => 'Manager',
            'tanggal_pengajuan'          => '2026-04-01',
            'posisi_dibutuhkan'          => "Role {$division}",
            'lokasi_penempatan'          => 'Jakarta',
            'status_kebutuhan'           => StatusKebutuhan::NEW_HIRING,
            'divisi'                     => $division,
            'level_pekerjaan'            => 'Staff',
            'jumlah_karyawan_dibutuhkan' => 2,
            'estimasi_tanggal_join'      => '2026-05-01',
            'requirements_kualifikasi'   => 'Requirement',
            'job_description'            => 'Job description',
            'keterangan'                 => 'Testing',
            'status'                     => RequestManPowerStatus::PENDING,
        ]);

        $pipeline = RekrutmenPipeline::query()->create([
            'name' => "Pipeline {$division}",
        ]);

        $firstStage = RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'CV Screening',
            'order_column'          => 1,
        ]);

        RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Interview User',
            'order_column'          => 2,
        ]);

        $jobPosting = JobPosting::query()->create([
            'request_man_power_id'  => $requestManPower->id,
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => str($slug)->replace('-', ' ')->title()->toString(),
            'slug'                  => $slug,
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        return [$jobPosting, $firstStage];
    }

    private function makeJobApplication(JobPosting $jobPosting, RekrutmenStage $stage, string $email, string $fullName): JobApplication
    {
        $phoneNumber = '081'.str_pad((string) (abs(crc32($email)) % 1000000000), 9, '0', STR_PAD_LEFT);

        $application = JobApplication::query()->create([
            'job_posting_id'             => $jobPosting->id,
            'current_stage_id'           => $stage->id,
            'full_name'                  => $fullName,
            'email'                      => $email,
            'gender'                     => JobApplicationGender::Male,
            'birth_date'                 => '1995-01-10',
            'marital_status'             => JobApplicationMaritalStatus::Single,
            'address_ktp'                => 'Alamat KTP',
            'address_domicile'           => 'Alamat Domisili',
            'whatsapp_number'            => $phoneNumber,
            'active_phone'               => $phoneNumber,
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Saudara',
            'emergency_contact_phone'    => $phoneNumber,
            'status'                     => JobApplicationStatus::IN_PROGRESS,
        ]);

        $application->forceFill([
            'created_at' => '2026-04-05 09:00:00',
            'updated_at' => '2026-04-05 09:00:00',
        ])->saveQuietly();

        return $application->refresh();
    }

    private function assertWorkbookSheetMatchesOverviewMppLayout(object $sheetExport, string $lastColumn): void
    {
        $spreadsheet = new Spreadsheet;
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->fromArray($sheetExport->array(), null, 'A1');
        $sheetExport->styles($worksheet);

        $mergedCells = array_keys($worksheet->getMergeCells());

        $this->assertContains("A1:{$lastColumn}1", $mergedCells);
        $this->assertContains("A2:{$lastColumn}2", $mergedCells);
        $this->assertContains("A3:{$lastColumn}3", $mergedCells);
        $this->assertContains("A4:{$lastColumn}4", $mergedCells);
        $this->assertSame('FFF59D', $worksheet->getStyle('A5')->getFill()->getStartColor()->getRGB());
        $this->assertSame('FEF3C7', $worksheet->getStyle('A1')->getFill()->getStartColor()->getRGB());
        $this->assertSame('center', $worksheet->getStyle('A1')->getAlignment()->getHorizontal());
        $this->assertSame('A6', $worksheet->getFreezePane());
    }
}
