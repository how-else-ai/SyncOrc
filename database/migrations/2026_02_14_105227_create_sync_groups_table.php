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
        Schema::create('sync_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('group_id')->unique();
            $table->enum('group_type', ['pair', 'chain', 'group']);
            $table->timestamps();

            $table->index('group_id');
            $table->index('group_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_groups');
    }
};
