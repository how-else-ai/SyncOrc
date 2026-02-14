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
        Schema::create('pairing_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pairing_code', 10)->unique();
            $table->uuid('initiator_device_id');
            $table->text('initiator_public_key');
            $table->text('qr_data');
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('pairing_code');
            $table->index('expires_at');

            $table->foreign('initiator_device_id')->references('id')->on('devices')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pairing_requests');
    }
};
