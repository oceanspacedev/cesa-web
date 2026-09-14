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
        if (! Schema::hasTable('rekrutmen_whatsapp_settings')) {
            Schema::create('rekrutmen_whatsapp_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('enabled')->default(false);
                $table->string('endpoint')->nullable();
                $table->text('api_key')->nullable();
                $table->unsignedInteger('timeout')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('rekrutmen_whatsapp_accounts')) {
            Schema::create('rekrutmen_whatsapp_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('phone_number', 30)->nullable();
                $table->string('route_key')->default('default');
                $table->string('endpoint')->nullable();
                $table->text('api_key')->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->string('status', 30)->default('unknown');
                $table->timestamp('last_checked_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['is_active', 'is_default']);
                $table->index('status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rekrutmen_whatsapp_accounts');
        Schema::dropIfExists('rekrutmen_whatsapp_settings');
    }
};
