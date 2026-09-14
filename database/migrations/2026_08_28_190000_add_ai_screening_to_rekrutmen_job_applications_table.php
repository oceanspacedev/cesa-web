<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('rekrutmen_job_applications')) {
            Schema::table('rekrutmen_job_applications', function (Blueprint $table) {
                if (! Schema::hasColumn('rekrutmen_job_applications', 'ai_match_score')) {
                    $table->unsignedTinyInteger('ai_match_score')->nullable()->after('status');
                    $table->string('ai_recommendation')->nullable()->after('ai_match_score');
                    $table->text('ai_summary')->nullable()->after('ai_recommendation');
                    $table->timestamp('ai_analyzed_at')->nullable()->after('ai_summary');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rekrutmen_job_applications', function (Blueprint $table) {
            if (Schema::hasColumn('rekrutmen_job_applications', 'ai_match_score')) {
                $table->dropColumn(['ai_match_score', 'ai_recommendation', 'ai_summary', 'ai_analyzed_at']);
            }
        });
    }
};
