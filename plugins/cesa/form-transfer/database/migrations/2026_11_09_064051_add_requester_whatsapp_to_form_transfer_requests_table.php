<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('form_transfer_requests') || Schema::hasColumn('form_transfer_requests', 'requester_whatsapp')) {
            return;
        }

        Schema::table('form_transfer_requests', function (Blueprint $table): void {
            $table->string('requester_whatsapp', 32)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('form_transfer_requests') || ! Schema::hasColumn('form_transfer_requests', 'requester_whatsapp')) {
            return;
        }

        Schema::table('form_transfer_requests', function (Blueprint $table): void {
            $table->dropColumn('requester_whatsapp');
        });
    }
};
