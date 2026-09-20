<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wag_integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id')->nullable()->unique();
            $table->string('url')->nullable();
            $table->text('token')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('event_cursor')->nullable();
            $table->boolean('default_selection_required')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('wag_event_inbox', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event');
            $table->string('installation_id');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index('processed_at');
        });
        Schema::create('wag_message_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id');
            $table->string('request_key');
            $table->string('payload_hash', 64);
            $table->json('payload');
            $table->string('hub_message_id')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wag_message_requests');
        Schema::dropIfExists('wag_event_inbox');
        Schema::dropIfExists('wag_integrations');
    }
};
