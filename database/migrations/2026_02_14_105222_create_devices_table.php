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
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_id')->unique();
            $table->text('public_key');
            $table->string('api_token');
            $table->text('push_token')->nullable();
            $table->enum('platform', ['ios', 'android', 'web']);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('token_expires_at');
            $table->timestamps();

            $table->index('device_id');
            $table->index('api_token');
            $table->index('last_seen_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
