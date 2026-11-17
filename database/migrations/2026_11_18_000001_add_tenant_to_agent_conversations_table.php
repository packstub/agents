<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workspace a conversation was started in, so one person who belongs to
 * two workspaces sees in each only the chats started there. Null in an app
 * without workspaces, and on the conversations stored before this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_conversations', 'tenant')) {
                $table->string('tenant')->nullable()->after('participant_id'); // the workspace key, when the app has workspaces
                $table->index(['participant_type', 'participant_id', 'tenant']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->dropIndex(['participant_type', 'participant_id', 'tenant']);
            $table->dropColumn('tenant');
        });
    }
};
