<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rekrutmen_whatsapp_accounts', function (Blueprint $table): void {
            $table->string('connection_request_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('rekrutmen_whatsapp_accounts', function (Blueprint $table): void {
            $table->dropUnique(['connection_request_key']);
            $table->dropColumn('connection_request_key');
        });
    }
};
