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
        if (Schema::hasTable('rekrutmen_scheduled_notifications') && ! Schema::hasColumn('rekrutmen_scheduled_notifications', 'candidate_schedules')) {
            Schema::table('rekrutmen_scheduled_notifications', function (Blueprint $table) {
                $table->json('candidate_schedules')->nullable()->after('schedule');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rekrutmen_scheduled_notifications') && Schema::hasColumn('rekrutmen_scheduled_notifications', 'candidate_schedules')) {
            Schema::table('rekrutmen_scheduled_notifications', function (Blueprint $table) {
                $table->dropColumn('candidate_schedules');
            });
        }
    }
};
