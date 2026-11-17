<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The workspace a conversation was started in, so one person who belongs to
 * two workspaces sees in each only the chats started there. Null in an app
 * without workspaces. Existing conversations take the workspace of their
 * first turn that has one; a conversation without such a turn stays null.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conversations = config('ai.conversations.tables.conversations', 'agent_conversations');

        if (! Schema::hasColumn($conversations, 'tenant')) {
            Schema::table($conversations, function (Blueprint $table) {
                $table->string('tenant')->nullable()->after('participant_id'); // the workspace key, when the app has workspaces
                $table->index(['participant_type', 'participant_id', 'tenant']);
            });
        }

        if (Schema::hasTable('agent_turns')) {
            DB::table($conversations)
                ->whereNull('tenant')
                ->update(['tenant' => DB::table('agent_turns')
                    ->select('tenant')
                    ->whereColumn('agent_turns.conversation_id', $conversations.'.id')
                    ->whereNotNull('tenant')
                    ->orderBy('created_at')
                    ->limit(1)]);
        }
    }

    public function down(): void
    {
        $conversations = config('ai.conversations.tables.conversations', 'agent_conversations');

        Schema::table($conversations, function (Blueprint $table) {
            $table->dropIndex(['participant_type', 'participant_id', 'tenant']);
            $table->dropColumn('tenant');
        });
    }
};
