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
        Schema::create('cached_payloads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cache_id')->unique();
            $table->uuid('from_device_id');
            $table->uuid('to_device_id');
            $table->uuid('group_id');
            $table->binary('encrypted_data');
            $table->string('state_version');
            $table->integer('size_bytes');
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['to_device_id', 'expires_at']);
            $table->index('group_id');
            $table->index('expires_at');

            $table->foreign('from_device_id')->references('id')->on('devices')->onDelete('cascade');
            $table->foreign('to_device_id')->references('id')->on('devices')->onDelete('cascade');
            $table->foreign('group_id')->references('id')->on('sync_groups')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cached_payloads');
    }
};
