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
            return;
        }

        if (Schema::hasColumn('rekrutmen_scheduled_notifications', 'whatsapp_account_id')) {
            return;
        }

        Schema::table('rekrutmen_scheduled_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('whatsapp_account_id')->nullable()->after('channels');
            $table->index('whatsapp_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('rekrutmen_scheduled_notifications')) {
            return;
        }

        if (! Schema::hasColumn('rekrutmen_scheduled_notifications', 'whatsapp_account_id')) {
            return;
        }

        Schema::table('rekrutmen_scheduled_notifications', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_account_id']);
            $table->dropColumn('whatsapp_account_id');
        });
    }
};
