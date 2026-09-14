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
        if (! Schema::hasTable('rekrutmen_scheduled_notifications')) {
            Schema::create('rekrutmen_scheduled_notifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('creator_id')->nullable()->index();
                $table->json('application_ids');
                $table->json('channels');
                $table->string('subject');
                $table->text('body_message');
                $table->string('schedule')->nullable();
                $table->string('venue_or_method')->nullable();
                $table->string('action_url')->nullable();
                $table->string('action_label')->nullable();
                $table->text('special_note')->nullable();
                $table->string('badge_text')->nullable();
                $table->string('info_box_title')->nullable();
                $table->string('attachment_path')->nullable();
                $table->string('attachment_name')->nullable();
                $table->string('attachment_mime')->nullable();
                $table->dateTime('scheduled_at')->index();
                $table->string('status', 30)->default('pending')->index();
                $table->dateTime('sent_at')->nullable();
                $table->json('results')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rekrutmen_scheduled_notifications');
    }
};
