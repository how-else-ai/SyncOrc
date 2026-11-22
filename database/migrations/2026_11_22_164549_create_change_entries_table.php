<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('devices')->onDelete('cascade');
            $table->string('entity_type'); // e.g. 'note', 'contact', 'calendar_event'
            $table->string('entity_id'); // local id of the entity on the source device
            $table->string('change_type'); // create | update | delete
            $table->string('content_hash', 64); // sha-256 of the payload — server never sees content
            $table->integer('version'); // per-device monotonic version
            $table->json('vector_clock')->nullable(); // optional CRDT vector clock for conflict resolution
            $table->string('payload_location')->nullable(); // P2P URL where the encrypted payload can be fetched
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['device_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_entries');
    }
};
