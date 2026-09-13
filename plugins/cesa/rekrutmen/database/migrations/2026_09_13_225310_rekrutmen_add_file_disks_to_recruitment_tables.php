<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rekrutmen_job_applications', function (Blueprint $table): void {
            $table->string('resume_disk')->nullable();
            $table->string('photo_disk')->nullable();
        });
        Schema::table('rekrutmen_job_postings', function (Blueprint $table): void {
            $table->string('thumbnail_disk')->nullable();
        });
        Schema::table('rekrutmen_scheduled_notifications', function (Blueprint $table): void {
            $table->string('attachment_disk')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rekrutmen_scheduled_notifications', function (Blueprint $table): void {
            $table->dropColumn('attachment_disk');
        });
        Schema::table('rekrutmen_job_postings', function (Blueprint $table): void {
            $table->dropColumn('thumbnail_disk');
        });
        Schema::table('rekrutmen_job_applications', function (Blueprint $table): void {
            $table->dropColumn(['resume_disk', 'photo_disk']);
        });
    }
};
