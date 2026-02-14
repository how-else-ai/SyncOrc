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
        Schema::create('group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('group_id');
            $table->uuid('device_id');
            $table->integer('position')->nullable();
            $table->timestamp('joined_at')->useCurrent();

            $table->unique(['group_id', 'device_id']);
            $table->index('group_id');
            $table->index('device_id');
            $table->index(['group_id', 'position']);

            $table->foreign('group_id')->references('id')->on('sync_groups')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_members');
    }
};
