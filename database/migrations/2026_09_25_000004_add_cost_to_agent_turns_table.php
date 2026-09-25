<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a turn cost in money, from the token usage and the prices in
 * config `pricing` at the time it ended (null when the model has no price).
 * Installs from 1.4.0 get the column here; the create migration already has
 * it for newer installs, so it is guarded. Folded away at 2.0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_turns', 'cost')) {
                $table->decimal('cost', 12, 6)->nullable()->after('duration_ms');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->dropColumn('cost');
        });
    }
};
