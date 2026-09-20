<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rekrutmen_whatsapp_accounts', function (Blueprint $table): void {
            $table->string('hub_session_id')->nullable()->unique();
            $table->string('hub_installation_id')->nullable();
            $table->unsignedBigInteger('hub_revision')->default(0);
            $table->string('hub_status')->nullable();
            $table->string('desired_state')->nullable();
            $table->timestamp('hub_observed_at')->nullable();
            $table->boolean('hub_stale')->default(true);
            $table->boolean('engine_available')->default(false);
            $table->json('hub_snapshot')->nullable();
            $table->string('pending_operation_id')->nullable();
            $table->string('pending_operation_action')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rekrutmen_whatsapp_accounts', function (Blueprint $table): void {
            $table->dropUnique(['hub_session_id']);
            $table->dropColumn(['hub_session_id', 'hub_installation_id', 'hub_revision', 'hub_status', 'desired_state', 'hub_observed_at', 'hub_stale', 'engine_available', 'hub_snapshot', 'pending_operation_id', 'pending_operation_action']);
        });
    }
};
