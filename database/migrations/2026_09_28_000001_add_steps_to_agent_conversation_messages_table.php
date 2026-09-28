<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packstub\Agents\Support\AgentConversationStore;

/**
 * laravel/ai 1.0 stores an answer's model round-trips as `steps` (each tool
 * result on the call that produced it) under a `status`, where 0.x wrote
 * `tool_calls`, `tool_results` and `approval_state`. Existing rows are
 * rewritten into steps; the old columns stay, empty, so nothing is dropped
 * inside a major (they go at 2.0). Decide or abandon any proposal still
 * waiting before you migrate: a pause is carried over, but a turn that paused
 * on the 0.x provider state cannot resume from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = 'agent_conversation_messages';

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'steps')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            $blueprint->longText('steps')->nullable();
            $blueprint->string('status', 25)->default('completed');

            foreach (['tool_calls', 'tool_results'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $blueprint->text($column)->nullable()->change();
                }
            }
        });

        DB::table($table)->where('role', 'user')->update(['steps' => '[]']);

        DB::table($table)->where('role', 'assistant')->orderBy('id')->chunkById(200, function ($rows) use ($table) {
            foreach ($rows as $row) {
                [$steps, $status] = AgentConversationStore::stepsFromLegacyRow((array) $row);

                DB::table($table)->where('id', $row->id)->update([
                    'steps' => json_encode($steps),
                    'status' => $status,
                    'tool_calls' => null,
                    'tool_results' => null,
                ]);
            }
        });

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropIndex('participant_index');
            $blueprint->index(['participant_type', 'participant_id', 'agent'], 'participant_index');
        });
    }

    public function down(): void
    {
        // The old columns were kept; the steps column stays too, so an answer stored since is not lost.
    }
};
