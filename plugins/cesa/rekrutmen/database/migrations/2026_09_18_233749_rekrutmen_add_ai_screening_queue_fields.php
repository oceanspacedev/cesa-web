<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rekrutmen_job_applications')) {
            return;
        }

        Schema::table('rekrutmen_job_applications', function (Blueprint $table): void {
            if (! Schema::hasColumn('rekrutmen_job_applications', 'ai_match_score')) {
                $table->unsignedTinyInteger('ai_match_score')->nullable();
            }
            if (! Schema::hasColumn('rekrutmen_job_applications', 'ai_recommendation')) {
                $table->string('ai_recommendation')->nullable();
            }
            if (! Schema::hasColumn('rekrutmen_job_applications', 'ai_summary')) {
                $table->text('ai_summary')->nullable();
            }
            if (! Schema::hasColumn('rekrutmen_job_applications', 'ai_analyzed_at')) {
                $table->timestamp('ai_analyzed_at')->nullable();
            }
            $table->string('ai_screening_status', 24)->default('pending');
            $table->text('ai_screening_error')->nullable();
            $table->uuid('ai_screening_token')->nullable();
            $table->char('ai_screening_fingerprint', 64)->nullable();
            $table->timestamp('ai_screening_requested_at')->nullable();
            $table->timestamp('ai_screening_started_at')->nullable();
            $table->index(['job_posting_id', 'ai_screening_status'], 'rekrutmen_applications_ai_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('rekrutmen_job_applications', function (Blueprint $table): void {
            $table->dropIndex('rekrutmen_applications_ai_status_index');
            $table->dropColumn([
                'ai_screening_status', 'ai_screening_error', 'ai_screening_token',
                'ai_screening_fingerprint', 'ai_screening_requested_at', 'ai_screening_started_at',
            ]);
        });
    }
};
