<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('waste_outlets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('waste_brands')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('slug', 100);
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['brand_id', 'code']);
            $table->unique(['brand_id', 'slug']);
        });

        Schema::create('waste_brand_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('waste_brands')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->unique(['brand_id', 'user_id']);
        });

        Schema::create('waste_outlet_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('waste_outlets')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->unique(['outlet_id', 'user_id']);
        });

        Schema::create('waste_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('waste_brands')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->string('item_type')->nullable();
            $table->string('unit', 32)->nullable();
            $table->string('source_status', 32)->default('review');
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['brand_id', 'code']);
            $table->index(['brand_id', 'is_active']);
        });

        Schema::create('waste_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained('waste_brands')->nullOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['brand_id', 'code']);
        });

        Schema::create('waste_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('waste_brands')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['brand_id', 'code']);
        });

        Schema::create('waste_workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('waste_brands')->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('waste_outlets')->cascadeOnDelete();
            $table->string('name');
            $table->json('steps');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->index(['brand_id', 'outlet_id', 'is_active']);
        });

        Schema::create('waste_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('submission_key', 64)->nullable()->unique();
            $table->foreignId('brand_id')->constrained('waste_brands')->restrictOnDelete();
            $table->foreignId('outlet_id')->constrained('waste_outlets')->restrictOnDelete();
            $table->date('event_date');
            $table->string('reporter_name');
            $table->string('reporter_phone', 40);
            $table->string('reporter_email')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->string('manage_token_hash', 64)->nullable()->index();
            $table->string('progress_token_hash', 64)->nullable()->index();
            $table->unsignedInteger('token_version')->default(1);
            $table->unsignedBigInteger('latest_version_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->index(['brand_id', 'outlet_id', 'event_date']);
        });

        Schema::create('waste_report_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('waste_reports')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 24)->default('pending')->index();
            $table->text('rejection_reason')->nullable();
            $table->json('workflow_snapshot');
            $table->timestamps();
            $table->unique(['report_id', 'version_number']);
        });

        Schema::create('waste_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('waste_report_versions')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('section')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('waste_categories')->nullOnDelete();
            $table->string('category_name');
            $table->text('reason');
            $table->foreignId('pip_item_id')->nullable()->constrained('waste_items')->nullOnDelete();
            $table->string('pip_item_code')->nullable();
            $table->string('pip_item_name')->nullable();
            $table->string('pip_unit', 32)->nullable();
            $table->decimal('pip_quantity', 18, 4)->nullable();
            $table->timestamps();
            $table->unique(['version_id', 'sequence']);
        });

        Schema::create('waste_event_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('waste_events')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('waste_items')->nullOnDelete();
            $table->string('item_code');
            $table->string('item_name');
            $table->string('unit', 32);
            $table->decimal('quantity', 18, 4);
            $table->string('line_role', 24)->default('direct');
            $table->timestamps();
            $table->index(['item_code', 'unit']);
        });

        Schema::create('waste_evidences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('waste_events')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('waste_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('waste_report_versions')->cascadeOnDelete();
            $table->unsignedInteger('step_order');
            $table->string('label');
            $table->string('approver_name');
            $table->string('approver_email')->nullable();
            $table->string('approver_phone', 40)->nullable();
            $table->string('token_hash', 64)->nullable()->index();
            $table->string('status', 24)->default('waiting')->index();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['version_id', 'step_order']);
        });

        Schema::create('waste_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('waste_reports')->cascadeOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('waste_report_versions')->nullOnDelete();
            $table->string('event', 64);
            $table->string('actor_type', 32)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['report_id', 'created_at']);
        });

        Schema::create('waste_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('waste_reports')->cascadeOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('waste_report_versions')->nullOnDelete();
            $table->string('channel', 24);
            $table->string('type', 64);
            $table->string('recipient');
            $table->json('payload')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['report_id', 'type', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_notification_deliveries');
        Schema::dropIfExists('waste_activity_logs');
        Schema::dropIfExists('waste_approvals');
        Schema::dropIfExists('waste_evidences');
        Schema::dropIfExists('waste_event_lines');
        Schema::dropIfExists('waste_events');
        Schema::dropIfExists('waste_report_versions');
        Schema::dropIfExists('waste_reports');
        Schema::dropIfExists('waste_workflows');
        Schema::dropIfExists('waste_categories');
        Schema::dropIfExists('waste_sections');
        Schema::dropIfExists('waste_items');
        Schema::dropIfExists('waste_outlet_user');
        Schema::dropIfExists('waste_brand_user');
        Schema::dropIfExists('waste_outlets');
        Schema::dropIfExists('waste_brands');
    }
};
