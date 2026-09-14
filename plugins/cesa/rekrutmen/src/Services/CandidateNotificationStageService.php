<?php

namespace Cesa\Rekrutmen\Services;

use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\NotificationDelivery;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Illuminate\Database\Eloquent\Builder;

class CandidateNotificationStageService
{
    /**
     * @return array{original_stage_id: ?int, original_status: string, target_stage_id: ?int, target_status: string}|null
     */
    public function snapshot(JobApplication $application, ?string $templateKey): ?array
    {
        if (! $templateKey) {
            return null;
        }

        $status = (string) $application->getRawOriginal('status');
        $stage = $this->targetStage($application, $templateKey);
        if (! $stage && ! in_array($templateKey, ['rejection', 'hired'], true)) {
            return null;
        }

        return [
            'original_stage_id' => $application->current_stage_id ? (int) $application->current_stage_id : null,
            'original_status'   => $status,
            'target_stage_id'   => $stage ? (int) $stage->id : ($application->current_stage_id ? (int) $application->current_stage_id : null),
            'target_status'     => match ($templateKey) {
                'rejection' => 'rejected',
                'hired'     => 'hired',
                default     => $status === 'rejected' ? 'in_progress' : $status,
            },
        ];
    }

    public function applyAfterSuccess(NotificationDelivery $delivery): void
    {
        if (! $delivery->application_id || ! $delivery->stage_snapshot || ! $delivery->scheduled_notification_id) {
            return;
        }

        $delivery->getConnection()->transaction(function () use ($delivery): void {
            $application = JobApplication::query()->whereKey($delivery->application_id)->lockForUpdate()->first();
            $siblings = NotificationDelivery::query()
                ->where('scheduled_notification_id', $delivery->scheduled_notification_id)
                ->where('application_id', $delivery->application_id);

            if ($siblings->clone()->whereNotNull('stage_applied_at')->exists()) {
                return;
            }

            $snapshot = $delivery->stage_snapshot;
            if ($application
                && $application->current_stage_id == $snapshot['original_stage_id']
                && $application->getRawOriginal('status') === $snapshot['original_status']) {
                $application->forceFill([
                    'current_stage_id' => $snapshot['target_stage_id'],
                    'status'           => $snapshot['target_status'],
                ])->save();
            }

            $siblings->update(['stage_applied_at' => now()]);
        });
    }

    protected function targetStage(JobApplication $application, string $templateKey): ?RekrutmenStage
    {
        $query = RekrutmenStage::query()
            ->where('rekrutmen_pipeline_id', $application->jobPosting?->rekrutmen_pipeline_id ?? 1)
            ->orderBy('order_column');

        $patterns = match ($templateKey) {
            'screening' => ['screening'],
            'interview_hr', 'interview' => ['interview hr'],
            'psikotes'         => ['psikotes'],
            'kompetensi'       => ['kompetensi', 'skill'],
            'interview_user'   => ['interview user'],
            'background_check' => ['backgro', 'check'],
            'offering'         => ['offering'],
            'hired'            => ['hired'],
            default            => [],
        };

        if ($patterns === []) {
            return null;
        }

        $stage = $query->clone()->where(function (Builder $builder) use ($patterns): void {
            foreach ($patterns as $pattern) {
                $builder->orWhere('name', 'like', '%'.$pattern.'%');
            }
        })->first();

        if (! $stage && in_array($templateKey, ['interview_hr', 'interview'], true)) {
            return $query->where('name', 'like', '%interview%')->first();
        }

        return $stage;
    }
}
