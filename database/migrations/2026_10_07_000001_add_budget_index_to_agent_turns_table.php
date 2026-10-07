<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The budget counters (turns and tokens per day and per month) are counted
 * from agent_turns per workspace since 1.7.2; this index serves them. Fresh
 * installs get it from the create migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('agent_turns', ['tenant', 'finished_at'])) {
            return;
        }

        Schema::table('agent_turns', function (Blueprint $table) {
            $table->index(['tenant', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->dropIndex(['tenant', 'finished_at']);
        });
    }
};
