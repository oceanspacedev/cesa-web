<?php

namespace Cesa\Rekrutmen\Tests\Feature\Models;

use Cesa\Rekrutmen\Enums\ActivityEntryResult;
use Cesa\Rekrutmen\Enums\JobApplicationGender;
use Cesa\Rekrutmen\Enums\JobApplicationMaritalStatus;
use Cesa\Rekrutmen\Enums\JobApplicationStatus;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class JobApplicationWorkflowTest extends RekrutmenTestCase
{
    public function test_transition_to_stage_records_history_and_validates_pipeline(): void
    {
        [$jobPosting, $firstStage, $secondStage] = $this->createPipelineFixture('Main');
        [, $foreignStage] = $this->createPipelineFixture('Foreign');

        $application = $this->makeJobApplication($jobPosting, $firstStage);

        $application->transitionToStage($secondStage->id, 'Move to interview');

        $application->refresh();

        $this->assertSame($secondStage->id, $application->current_stage_id);
        $this->assertNotNull($application->position);
        $this->assertDatabaseHas('rekrutmen_job_application_histories', [
            'job_application_id' => $application->id,
            'from_stage_id'      => $firstStage->id,
            'to_stage_id'        => $secondStage->id,
            'status'             => JobApplicationStatus::IN_PROGRESS->value,
            'notes'              => 'Move to interview',
        ]);

        $this->expectException(InvalidArgumentException::class);

        $application->transitionToStage($foreignStage->id);
    }

    public function test_passing_current_stage_records_passed_activity_and_moves_to_next_stage(): void
    {
        [$jobPosting, $firstStage, $secondStage] = $this->createPipelineFixture('Pass Current Stage');

        $application = $this->makeJobApplication($jobPosting, $firstStage, 'pass-current-stage@example.com');

        $nextStage = $application->passCurrentStage('2026-04-16', 'Lolos interview awal.');
        $application->refresh();

        $this->assertSame($secondStage->id, $nextStage->id);
        $this->assertSame($secondStage->id, $application->current_stage_id);
        $this->assertSame(JobApplicationStatus::IN_PROGRESS, $application->status);
        $this->assertDatabaseHas('rekrutmen_job_application_histories', [
            'job_application_id' => $application->id,
            'from_stage_id'      => $firstStage->id,
            'to_stage_id'        => $secondStage->id,
            'activity_type'      => $firstStage->activityKey(),
            'result'             => 'passed',
            'activity_date'      => '2026-04-16 00:00:00',
            'status'             => JobApplicationStatus::IN_PROGRESS->value,
            'notes'              => 'Lolos interview awal.',
        ]);
        $this->assertDatabaseMissing('rekrutmen_job_application_histories', [
            'job_application_id' => $application->id,
            'from_stage_id'      => $firstStage->id,
            'to_stage_id'        => $secondStage->id,
            'status'             => JobApplicationStatus::IN_PROGRESS->value,
            'notes'              => 'Lolos interview awal.',
            'activity_type'      => null,
        ]);
        $this->assertSame(2, $application->histories()->count());
    }

    public function test_candidate_can_only_be_marked_hired_from_final_pipeline_stage(): void
    {
        [$jobPosting, $firstStage, $secondStage] = $this->createPipelineFixture('Final Stage Accept');

        $application = $this->makeJobApplication($jobPosting, $firstStage, 'final-stage-accept@example.com');

        $this->assertFalse($application->canMarkAsHired());

        try {
            $application->markAsHired('Accepted before final stage.');
            $this->fail('Candidate was marked hired before reaching the final decision stage.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                __('rekrutmen::filament/resources/job-application.workflow_errors.terminal_stage_locked'),
                $exception->getMessage(),
            );
        }

        $application->transitionToStage($secondStage->id, 'Move to final evaluation.');
        $application->refresh();

        $this->assertTrue($application->canMarkAsHired());

        $application->markAsRejected('Rejected at final stage.');
        $application->refresh();

        $this->assertFalse($application->canMarkAsHired());
    }

    public function test_marking_candidate_as_hired_and_rejected_records_history(): void
    {
        [$jobPosting, $firstStage, $secondStage] = $this->createPipelineFixture('Decision');

        $hiredApplication = $this->makeJobApplication($jobPosting, $firstStage, 'hired@example.com');
        $hiredApplication->transitionToStage($secondStage->id, 'Move to final decision.');
        $hiredApplication->refresh();
        $hiredApplication->markAsHired('Accepted', activityDate: '2026-04-17');
        $hiredApplication->refresh();

        $this->assertSame(JobApplicationStatus::HIRED, $hiredApplication->status);
        $this->assertDatabaseHas('rekrutmen_job_application_histories', [
            'job_application_id' => $hiredApplication->id,
            'from_stage_id'      => $secondStage->id,
            'to_stage_id'        => $secondStage->id,
            'result'             => ActivityEntryResult::ACCEPTED->value,
            'activity_date'      => '2026-04-17 00:00:00',
            'activity_title'     => JobApplication::generateBatchActivityTitle(__('rekrutmen::filament/resources/job-application.table.actions.mark_hired'), '2026-04-17'),
            'status'             => JobApplicationStatus::HIRED->value,
            'notes'              => 'Accepted',
        ]);

        $rejectedApplication = $this->makeJobApplication($jobPosting, $firstStage, 'rejected@example.com');
        $rejectedApplication->markAsRejected('Rejected', activityDate: '2026-04-18');
        $rejectedApplication->refresh();

        $this->assertSame(JobApplicationStatus::REJECTED, $rejectedApplication->status);
        $this->assertDatabaseHas('rekrutmen_job_application_histories', [
            'job_application_id' => $rejectedApplication->id,
            'from_stage_id'      => $firstStage->id,
            'to_stage_id'        => $firstStage->id,
            'result'             => ActivityEntryResult::REJECTED->value,
            'activity_date'      => '2026-04-18 00:00:00',
            'activity_title'     => JobApplication::generateBatchActivityTitle(__('rekrutmen::filament/resources/job-application.table.actions.mark_rejected'), '2026-04-18'),
            'status'             => JobApplicationStatus::REJECTED->value,
            'notes'              => 'Rejected',
        ]);
    }

    public function test_hired_candidate_can_be_marked_withdrawn_before_onboarding(): void
    {
        [$jobPosting, $firstStage, $secondStage] = $this->createPipelineFixture('Hired Withdrawn');

        $application = $this->makeJobApplication($jobPosting, $firstStage, 'hired-withdrawn@example.com');
        $application->transitionToStage($secondStage->id, 'Move to final decision.');
        $application->refresh();
        $application->markAsHired('Accepted', activityDate: '2026-04-17');
        $application->refresh();

        $this->assertSame(JobApplicationStatus::HIRED, $application->status);
        $this->assertTrue($application->canMarkAsWithdrawn());

        $application->markAsWithdrawn('Candidate resigned before onboarding.', activityDate: '2026-04-19');
        $application->refresh();

        $this->assertSame(JobApplicationStatus::WITHDRAWN, $application->status);
        $this->assertFalse($application->canMarkAsWithdrawn());
        $this->assertDatabaseHas('rekrutmen_job_application_histories', [
            'job_application_id' => $application->id,
            'from_stage_id'      => $secondStage->id,
            'to_stage_id'        => $secondStage->id,
            'result'             => null,
            'activity_date'      => '2026-04-19 00:00:00',
            'status'             => JobApplicationStatus::WITHDRAWN->value,
            'notes'              => 'Candidate resigned before onboarding.',
        ]);
    }

    public function test_candidate_can_be_marked_hired_after_reaching_hired_stage_when_available(): void
    {
        [$jobPosting, $firstStage, $secondStage] = $this->createPipelineFixture('Decision Hired Stage');

        $hiredStage = RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $jobPosting->rekrutmen_pipeline_id,
            'name'                  => 'Hired',
            'order_column'          => 3,
        ]);

        $application = $this->makeJobApplication($jobPosting, $firstStage, 'hired-stage@example.com');
        $application->transitionToStage($secondStage->id, 'Move to final decision.');
        $application->refresh();

        $this->assertFalse($application->canMarkAsHired());

        $application->transitionToStage($hiredStage->id, 'Move to hired stage.');
        $application->refresh();

        $this->assertTrue($application->canMarkAsHired());

        $application->markAsHired('Accepted');
        $application->refresh();

        $this->assertSame(JobApplicationStatus::HIRED, $application->status);
        $this->assertSame($hiredStage->id, $application->current_stage_id);
        $this->assertDatabaseHas('rekrutmen_job_application_histories', [
            'job_application_id' => $application->id,
            'from_stage_id'      => $hiredStage->id,
            'to_stage_id'        => $hiredStage->id,
            'status'             => JobApplicationStatus::HIRED->value,
            'notes'              => 'Accepted',
        ]);
    }

    public function test_marking_candidate_as_hired_requires_notes(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Required Notes');

        $hiredApplication = $this->makeJobApplication($jobPosting, $firstStage, 'required-hired@example.com');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('rekrutmen::filament/resources/job-application.workflow_errors.decision_note_required'));
        $hiredApplication->markAsHired();
    }

    public function test_marking_candidate_as_rejected_requires_notes(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Required Notes Reject');

        $rejectedApplication = $this->makeJobApplication($jobPosting, $firstStage, 'required-rejected@example.com');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('rekrutmen::filament/resources/job-application.workflow_errors.decision_note_required'));
        $rejectedApplication->markAsRejected();
    }

    public function test_sync_current_stage_to_job_posting_uses_first_stage_of_target_pipeline(): void
    {
        [$firstJobPosting, $firstStage] = $this->createPipelineFixture('Alpha');
        [$secondJobPosting, $targetStage] = $this->createPipelineFixture('Beta');

        $application = $this->makeJobApplication($firstJobPosting, $firstStage);

        DB::table('rekrutmen_job_applications')
            ->where('id', $application->id)
            ->update([
                'job_posting_id' => $secondJobPosting->id,
            ]);

        $application->refresh();

        $application->syncCurrentStageToJobPosting(notes: 'Sync stage');
        $application->refresh();

        $this->assertSame($targetStage->id, $application->current_stage_id);
        $this->assertDatabaseHas('rekrutmen_job_application_histories', [
            'job_application_id' => $application->id,
            'from_stage_id'      => $firstStage->id,
            'to_stage_id'        => $targetStage->id,
            'status'             => JobApplicationStatus::IN_PROGRESS->value,
            'notes'              => 'Sync stage',
        ]);
    }

    public function test_updating_job_posting_automatically_realigns_stage_to_target_pipeline(): void
    {
        [$firstJobPosting, $firstStage] = $this->createPipelineFixture('Auto Alpha');
        [$secondJobPosting, $targetStage] = $this->createPipelineFixture('Auto Beta');

        $application = $this->makeJobApplication($firstJobPosting, $firstStage, 'auto-sync@example.com');

        $application->update([
            'job_posting_id' => $secondJobPosting->id,
        ]);

        $application->refresh();

        $this->assertSame($targetStage->id, $application->current_stage_id);
    }

    public function test_hired_application_with_foreign_stage_is_normalized_to_hired_stage(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Normalize Hired');
        [, $foreignStage] = $this->createPipelineFixture('Normalize Foreign');

        $hiredStage = RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $jobPosting->rekrutmen_pipeline_id,
            'name'                  => 'Hired',
            'order_column'          => 3,
        ]);

        $application = $this->makeJobApplication($jobPosting, $firstStage, 'normalize-hired@example.com');

        $application->update([
            'current_stage_id' => $foreignStage->id,
            'status'           => JobApplicationStatus::HIRED,
        ]);

        $application->refresh();

        $this->assertSame(JobApplicationStatus::HIRED, $application->status);
        $this->assertSame($hiredStage->id, $application->current_stage_id);
    }

    public function test_new_application_gets_position_for_board_ordering(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Position');

        $firstApplication = $this->makeJobApplication($jobPosting, $firstStage, 'first@example.com');
        $secondApplication = $this->makeJobApplication($jobPosting, $firstStage, 'second@example.com');

        $this->assertNotNull($firstApplication->position);
        $this->assertNotNull($secondApplication->position);
        $this->assertTrue((float) $secondApplication->position > (float) $firstApplication->position);
    }

    public function test_board_position_ordering_is_scoped_to_job_posting(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Position Scope');

        $otherJobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $jobPosting->rekrutmen_pipeline_id,
            'title'                 => 'Other Developer Position Scope',
            'slug'                  => 'other-developer-position-scope-'.str()->lower(str()->random(4)),
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $firstApplication = $this->makeJobApplication($jobPosting, $firstStage, 'first-scoped-position@example.com');
        $otherPostingApplication = $this->makeJobApplication($otherJobPosting, $firstStage, 'other-scoped-position@example.com');
        $secondApplication = $this->makeJobApplication($jobPosting, $firstStage, 'second-scoped-position@example.com');

        $this->assertSame((string) $firstApplication->position, (string) $otherPostingApplication->position);
        $this->assertTrue((float) $secondApplication->position > (float) $firstApplication->position);
    }

    public function test_new_application_records_initial_history_when_created_from_admin_flow(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Initial History');

        $application = $this->makeJobApplication($jobPosting, $firstStage, 'initial-history@example.com');

        $this->assertDatabaseHas('rekrutmen_job_application_histories', [
            'job_application_id' => $application->id,
            'from_stage_id'      => null,
            'to_stage_id'        => $firstStage->id,
            'status'             => JobApplicationStatus::IN_PROGRESS->value,
            'notes'              => __('rekrutmen::filament/resources/job-application.workflow_notes.submitted'),
        ]);
        $this->assertSame(1, $application->histories()->count());
    }

    public function test_terminal_status_application_does_not_record_initial_submission_history(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Terminal Create');

        $application = JobApplication::query()->create([
            'job_posting_id'             => $jobPosting->id,
            'current_stage_id'           => $firstStage->id,
            'full_name'                  => 'Candidate Final',
            'email'                      => 'terminal@example.com',
            'gender'                     => JobApplicationGender::Male,
            'birth_date'                 => '1995-01-10',
            'marital_status'             => JobApplicationMaritalStatus::Single,
            'address_ktp'                => 'Alamat KTP',
            'address_domicile'           => 'Alamat Domisili',
            'whatsapp_number'            => '081234567890',
            'active_phone'               => '081234567891',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Saudara',
            'emergency_contact_phone'    => '081234567892',
            'status'                     => JobApplicationStatus::HIRED,
        ]);

        $this->assertSame(0, $application->histories()->count());
    }

    public function test_duplicate_email_cannot_be_created_for_the_same_job_posting(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Duplicate Create');

        $this->makeJobApplication($jobPosting, $firstStage, 'duplicate@example.com');

        $this->expectException(ValidationException::class);

        $this->makeJobApplication($jobPosting, $firstStage, ' DUPLICATE@example.com ');
    }

    public function test_duplicate_whatsapp_cannot_be_created_for_the_same_job_posting(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Duplicate Whatsapp Create');

        $this->makeJobApplication($jobPosting, $firstStage, 'duplicate-whatsapp-1@example.com', [
            'whatsapp_number' => '081234567890',
        ]);

        $this->expectException(ValidationException::class);

        $this->makeJobApplication($jobPosting, $firstStage, 'duplicate-whatsapp-2@example.com', [
            'whatsapp_number' => '+62 81234567890',
        ]);
    }

    public function test_application_email_cannot_be_updated_to_duplicate_for_the_same_job_posting(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Duplicate Update');

        $firstApplication = $this->makeJobApplication($jobPosting, $firstStage, 'first@example.com');
        $secondApplication = $this->makeJobApplication($jobPosting, $firstStage, 'second@example.com');

        $this->expectException(ValidationException::class);

        $secondApplication->update([
            'email' => ' FIRST@example.com ',
        ]);
    }

    public function test_application_whatsapp_cannot_be_updated_to_duplicate_for_the_same_job_posting(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Duplicate Whatsapp Update');

        $firstApplication = $this->makeJobApplication($jobPosting, $firstStage, 'first-whatsapp@example.com', [
            'whatsapp_number' => '081234567890',
        ]);
        $secondApplication = $this->makeJobApplication($jobPosting, $firstStage, 'second-whatsapp@example.com', [
            'whatsapp_number' => '081234567899',
        ]);

        $this->expectException(ValidationException::class);

        $secondApplication->update([
            'whatsapp_number' => '081234567890',
        ]);
    }

    public function test_restoring_duplicate_email_application_is_blocked(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Duplicate Restore');

        $trashedApplication = $this->makeJobApplication($jobPosting, $firstStage, 'restore@example.com');
        $trashedApplication->delete();

        $replacementApplication = $this->makeJobApplication($jobPosting, $firstStage, 'restore@example.com');

        $this->expectException(ValidationException::class);

        $trashedApplication->restore();

        $replacementApplication->refresh();
    }

    public function test_restoring_duplicate_whatsapp_application_is_blocked(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Duplicate Whatsapp Restore');

        $trashedApplication = $this->makeJobApplication($jobPosting, $firstStage, 'restore-whatsapp-1@example.com', [
            'whatsapp_number' => '081234567890',
        ]);
        $trashedApplication->delete();

        $replacementApplication = $this->makeJobApplication($jobPosting, $firstStage, 'restore-whatsapp-2@example.com', [
            'whatsapp_number' => '+62 81234567890',
        ]);

        $this->expectException(ValidationException::class);

        $trashedApplication->restore();

        $replacementApplication->refresh();
    }

    public function test_soft_deleted_stage_is_not_counted_as_an_available_pipeline_stage(): void
    {
        [$jobPosting, $firstStage, $secondStage] = $this->createPipelineFixture('Soft Deleted Stage');

        $firstStage->delete();
        $secondStage->delete();

        $jobPosting->rekrutmenPipeline->refresh();

        $this->assertSame(0, $jobPosting->rekrutmenPipeline->activeStages()->count());
        $this->assertNull(JobApplication::resolveInitialStageIdForJobPostingId($jobPosting->id));
    }

    public function test_pipeline_stage_order_must_be_unique_within_the_same_pipeline(): void
    {
        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Unique Stage Order',
        ]);

        RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening',
            'order_column'          => 1,
        ]);

        $this->expectException(QueryException::class);

        RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Interview',
            'order_column'          => 1,
        ]);
    }

    public function test_database_default_status_matches_supported_enum_values(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Default Status');

        $applicationId = DB::table('rekrutmen_job_applications')->insertGetId([
            'job_posting_id'             => $jobPosting->id,
            'current_stage_id'           => $firstStage->id,
            'position'                   => null,
            'full_name'                  => 'Candidate Default Status',
            'gender'                     => JobApplicationGender::Male->value,
            'birth_date'                 => '1995-01-10',
            'marital_status'             => JobApplicationMaritalStatus::Single->value,
            'address_ktp'                => 'Alamat KTP',
            'address_domicile'           => 'Alamat Domisili',
            'whatsapp_number'            => '081234567890',
            'active_phone'               => '081234567891',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Saudara',
            'emergency_contact_phone'    => '081234567892',
            'email'                      => 'default-status@example.com',
            'active_email'               => 'default-status@example.com',
            'photo_path'                 => null,
            'resume_path'                => null,
            'created_at'                 => now(),
            'updated_at'                 => now(),
        ]);

        $this->assertSame(
            JobApplicationStatus::IN_PROGRESS->value,
            DB::table('rekrutmen_job_applications')->where('id', $applicationId)->value('status')
        );
    }

    public function test_legacy_duplicate_record_can_be_updated_without_reclaiming_active_email(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Legacy Duplicate');

        $this->makeJobApplication($jobPosting, $firstStage, 'legacy@example.com');

        $legacyDuplicateId = DB::table('rekrutmen_job_applications')->insertGetId([
            'job_posting_id'             => $jobPosting->id,
            'current_stage_id'           => $firstStage->id,
            'position'                   => null,
            'full_name'                  => 'Legacy Candidate',
            'gender'                     => JobApplicationGender::Male->value,
            'birth_date'                 => '1995-01-10',
            'marital_status'             => JobApplicationMaritalStatus::Single->value,
            'address_ktp'                => 'Alamat KTP',
            'address_domicile'           => 'Alamat Domisili',
            'whatsapp_number'            => '081234567890',
            'active_phone'               => '081234567891',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Saudara',
            'emergency_contact_phone'    => '081234567892',
            'email'                      => 'LEGACY@example.com',
            'active_email'               => null,
            'photo_path'                 => null,
            'resume_path'                => null,
            'status'                     => JobApplicationStatus::IN_PROGRESS->value,
            'created_at'                 => now(),
            'updated_at'                 => now(),
        ]);

        $legacyDuplicate = JobApplication::query()->findOrFail($legacyDuplicateId);

        $legacyDuplicate->update([
            'full_name' => 'Legacy Candidate Updated',
        ]);

        $legacyDuplicate->refresh();

        $this->assertSame('LEGACY CANDIDATE UPDATED', $legacyDuplicate->full_name);
        $this->assertNull(
            DB::table('rekrutmen_job_applications')
                ->where('id', $legacyDuplicateId)
                ->value('active_email')
        );
    }

    public function test_surviving_legacy_duplicate_reclaims_active_email_when_owner_is_deleted(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Legacy Reclaim');

        $retainedApplication = $this->makeJobApplication($jobPosting, $firstStage, 'legacy-reclaim@example.com');

        $legacyDuplicateId = DB::table('rekrutmen_job_applications')->insertGetId([
            'job_posting_id'             => $jobPosting->id,
            'current_stage_id'           => $firstStage->id,
            'position'                   => null,
            'full_name'                  => 'Legacy Candidate',
            'gender'                     => JobApplicationGender::Male->value,
            'birth_date'                 => '1995-01-10',
            'marital_status'             => JobApplicationMaritalStatus::Single->value,
            'address_ktp'                => 'Alamat KTP',
            'address_domicile'           => 'Alamat Domisili',
            'whatsapp_number'            => '081234567890',
            'active_phone'               => '081234567891',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Saudara',
            'emergency_contact_phone'    => '081234567892',
            'email'                      => 'LEGACY-RECLAIM@example.com',
            'active_email'               => null,
            'photo_path'                 => null,
            'resume_path'                => null,
            'status'                     => JobApplicationStatus::IN_PROGRESS->value,
            'created_at'                 => now(),
            'updated_at'                 => now(),
        ]);

        $retainedApplication->delete();

        $this->assertSame(
            'legacy-reclaim@example.com',
            DB::table('rekrutmen_job_applications')
                ->where('id', $legacyDuplicateId)
                ->value('active_email')
        );

        $this->expectException(ValidationException::class);

        $this->makeJobApplication($jobPosting, $firstStage, 'legacy-reclaim@example.com');
    }

    public function test_surviving_legacy_duplicate_reclaims_active_email_when_owner_changes_email(): void
    {
        [$jobPosting, $firstStage] = $this->createPipelineFixture('Legacy Email Change');

        $retainedApplication = $this->makeJobApplication($jobPosting, $firstStage, 'legacy-email-change@example.com');

        $legacyDuplicateId = DB::table('rekrutmen_job_applications')->insertGetId([
            'job_posting_id'             => $jobPosting->id,
            'current_stage_id'           => $firstStage->id,
            'position'                   => null,
            'full_name'                  => 'Legacy Candidate',
            'gender'                     => JobApplicationGender::Male->value,
            'birth_date'                 => '1995-01-10',
            'marital_status'             => JobApplicationMaritalStatus::Single->value,
            'address_ktp'                => 'Alamat KTP',
            'address_domicile'           => 'Alamat Domisili',
            'whatsapp_number'            => '081234567890',
            'active_phone'               => '081234567891',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Saudara',
            'emergency_contact_phone'    => '081234567892',
            'email'                      => 'LEGACY-EMAIL-CHANGE@example.com',
            'active_email'               => null,
            'photo_path'                 => null,
            'resume_path'                => null,
            'status'                     => JobApplicationStatus::IN_PROGRESS->value,
            'created_at'                 => now(),
            'updated_at'                 => now(),
        ]);

        $retainedApplication->update([
            'email' => 'replacement@example.com',
        ]);

        $this->assertSame(
            'legacy-email-change@example.com',
            DB::table('rekrutmen_job_applications')
                ->where('id', $legacyDuplicateId)
                ->value('active_email')
        );

        $this->expectException(ValidationException::class);

        $this->makeJobApplication($jobPosting, $firstStage, 'legacy-email-change@example.com');
    }

    /**
     * @return array{0: JobPosting, 1: RekrutmenStage, 2: RekrutmenStage}
     */
    private function createPipelineFixture(string $suffix): array
    {
        $pipeline = RekrutmenPipeline::query()->create([
            'name' => "Pipeline {$suffix}",
        ]);

        $firstStage = RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => "Screening {$suffix}",
            'order_column'          => 1,
        ]);

        $secondStage = RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => "Interview {$suffix}",
            'order_column'          => 2,
        ]);

        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => "Developer {$suffix}",
            'slug'                  => 'developer-'.str()->slug($suffix).'-'.str()->lower(str()->random(4)),
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        return [$jobPosting, $firstStage, $secondStage];
    }

    private function makeJobApplication(JobPosting $jobPosting, RekrutmenStage $stage, string $email = 'candidate@example.com', array $overrides = []): JobApplication
    {
        $defaultWhatsappNumber = '08'.str_pad((string) (abs(crc32($email)) % 1000000000), 9, '0', STR_PAD_LEFT);

        return JobApplication::query()->create([
            'job_posting_id'             => $jobPosting->id,
            'current_stage_id'           => $stage->id,
            'full_name'                  => 'Candidate One',
            'email'                      => $email,
            'gender'                     => JobApplicationGender::Male,
            'birth_date'                 => '1995-01-10',
            'marital_status'             => JobApplicationMaritalStatus::Single,
            'address_ktp'                => 'Alamat KTP',
            'address_domicile'           => 'Alamat Domisili',
            'whatsapp_number'            => $defaultWhatsappNumber,
            'active_phone'               => '081234567891',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Saudara',
            'emergency_contact_phone'    => '081234567892',
            'status'                     => JobApplicationStatus::IN_PROGRESS,
            ...$overrides,
        ]);
    }
}
