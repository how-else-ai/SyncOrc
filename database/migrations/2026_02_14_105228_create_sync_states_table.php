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
        Schema::create('sync_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('group_id');
            $table->uuid('device_id');
            $table->string('state_version', 255);
            $table->string('ack_token_hash', 64);
            $table->json('vector_clock')->nullable();
            $table->boolean('is_acknowledged')->default(false);
            $table->timestamps();

            $table->index(['group_id', 'device_id']);
            $table->index(['group_id', 'device_id', 'state_version']);
            $table->index('is_acknowledged');

            $table->foreign('group_id')->references('id')->on('sync_groups')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_states');
    }
};
