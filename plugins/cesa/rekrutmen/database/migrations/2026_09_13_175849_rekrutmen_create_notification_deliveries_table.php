<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rekrutmen_scheduled_notifications', function (Blueprint $table): void {
            $table->string('request_key', 200)->nullable()->unique();
            $table->string('payload_hash', 64)->nullable();
            $table->string('template_key')->nullable();
        });

        Schema::create('rekrutmen_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scheduled_notification_id')->nullable()->index();
            $table->unsignedBigInteger('application_id')->nullable()->index();
            $table->unsignedBigInteger('approval_id')->nullable()->index();
            $table->string('request_key', 100)->unique();
            $table->string('channel', 20);
            $table->string('recipient')->nullable();
            $table->string('recipient_name')->nullable();
            $table->unsignedBigInteger('whatsapp_account_id')->nullable()->index();
            $table->json('payload');
            $table->json('stage_snapshot')->nullable();
            $table->timestamp('stage_applied_at')->nullable();
            $table->text('stage_error')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->index();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestamps();
            $table->unique(['scheduled_notification_id', 'application_id', 'channel'], 'rekrutmen_delivery_recipient_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rekrutmen_notification_deliveries');
        Schema::table('rekrutmen_scheduled_notifications', function (Blueprint $table): void {
            $table->dropUnique(['request_key']);
            $table->dropColumn(['request_key', 'payload_hash', 'template_key']);
        });
    }
};
