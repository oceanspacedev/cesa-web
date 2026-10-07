<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('purpose', 32);
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('token_hash', 64)->unique();
            $table->timestamps();

            $table->index(['purpose', 'token_hash']);
            $table->index(['tokenable_type', 'tokenable_id']);
        });

        $now = now();

        DB::table('waste_reports')
            ->whereNotNull('progress_token_hash')
            ->orderBy('id')
            ->each(function (object $report) use ($now): void {
                DB::table('waste_access_tokens')->insertOrIgnore([
                    'purpose'        => 'progress',
                    'tokenable_type' => 'Cesa\\Waste\\Models\\WasteReport',
                    'tokenable_id'   => $report->id,
                    'token_hash'     => $report->progress_token_hash,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            });

        DB::table('waste_approvals')
            ->whereNotNull('token_hash')
            ->orderBy('id')
            ->each(function (object $approval) use ($now): void {
                DB::table('waste_access_tokens')->insertOrIgnore([
                    'purpose'        => 'approval',
                    'tokenable_type' => 'Cesa\\Waste\\Models\\WasteApproval',
                    'tokenable_id'   => $approval->id,
                    'token_hash'     => $approval->token_hash,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_access_tokens');
    }
};
