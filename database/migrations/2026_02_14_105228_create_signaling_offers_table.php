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
        Schema::create('signaling_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('offer_id')->unique();
            $table->uuid('from_device_id');
            $table->uuid('to_device_id');
            $table->text('offer_data');
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['to_device_id', 'expires_at']);
            $table->index('from_device_id');

            $table->foreign('from_device_id')->references('id')->on('devices')->onDelete('cascade');
            $table->foreign('to_device_id')->references('id')->on('devices')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signaling_offers');
    }
};
