<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rating may carry the person's note ("wrong customer") and the turn that
 * produced the answer, so the operator's turn log shows the rating. Installs
 * from 1.4.0 get the columns here; the create migration already has them for
 * newer installs, so every column is guarded. Folded away at 2.0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_message_feedback', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_message_feedback', 'note')) {
                $table->string('note', 1000)->nullable()->after('rating');
            }
            if (! Schema::hasColumn('agent_message_feedback', 'turn_id')) {
                $table->string('turn_id', 36)->nullable()->after('note')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_message_feedback', function (Blueprint $table) {
            $table->dropColumn(['note', 'turn_id']);
        });
    }
};
