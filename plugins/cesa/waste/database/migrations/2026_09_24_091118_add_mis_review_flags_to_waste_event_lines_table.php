<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_event_lines', function (Blueprint $table): void {
            $table->boolean('sm_checked')->nullable()->after('line_role');
            $table->boolean('audit_checked')->nullable()->after('sm_checked');
        });
    }

    public function down(): void
    {
        Schema::table('waste_event_lines', function (Blueprint $table): void {
            $table->dropColumn(['sm_checked', 'audit_checked']);
        });
    }
};
