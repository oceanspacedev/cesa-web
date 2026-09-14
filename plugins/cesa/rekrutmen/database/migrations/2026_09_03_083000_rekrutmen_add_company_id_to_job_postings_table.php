<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('rekrutmen_job_postings') && ! Schema::hasColumn('rekrutmen_job_postings', 'company_id')) {
            Schema::table('rekrutmen_job_postings', function (Blueprint $table): void {
                $table->foreignId('company_id')
                    ->nullable()
                    ->after('rekrutmen_pipeline_id')
                    ->constrained('companies')
                    ->nullOnDelete();

                $table->index('company_id');
            });

            if (Schema::hasTable('rekrutmen_request_man_powers') && Schema::hasColumn('rekrutmen_request_man_powers', 'company_id')) {
                $jobPostings = DB::table('rekrutmen_job_postings')->get(['id', 'request_man_power_id']);
                foreach ($jobPostings as $job) {
                    $companyId = null;

                    if ($job->request_man_power_id) {
                        $companyId = DB::table('rekrutmen_request_man_powers')
                            ->where('id', $job->request_man_power_id)
                            ->value('company_id');
                    }

                    if (! $companyId) {
                        $companyId = DB::table('rekrutmen_request_man_powers')
                            ->where('job_posting_id', $job->id)
                            ->whereNotNull('company_id')
                            ->value('company_id');
                    }

                    if ($companyId) {
                        DB::table('rekrutmen_job_postings')
                            ->where('id', $job->id)
                            ->update(['company_id' => $companyId]);
                    }
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rekrutmen_job_postings') && Schema::hasColumn('rekrutmen_job_postings', 'company_id')) {
            Schema::table('rekrutmen_job_postings', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('company_id');
            });
        }
    }
};
