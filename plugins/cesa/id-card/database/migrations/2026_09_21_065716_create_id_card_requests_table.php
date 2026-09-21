<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_card_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name');
            $table->text('shipping_address');
            $table->string('business_entity', 3);
            $table->string('position', 20);
            $table->string('photo');
            $table->string('phone', 20);
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['business_entity', 'created_at']);
            $table->index(['position', 'created_at']);
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_card_requests');
    }
};
