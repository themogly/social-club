<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operational liveness marker. The scheduler writes one heartbeat per component
     * every few minutes (system:heartbeat); the health panel reads the latest and
     * flags it when it goes stale — the failure mode of a cron/queue is SILENCE, so we
     * make silence visible. One row per component (updateOrCreate), never unbounded.
     */
    public function up(): void
    {
        Schema::create('heartbeat_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('component')->unique();     // scheduler | queue | ...
            $table->timestamp('ran_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heartbeat_logs');
    }
};
