<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The arguments a proposed write ran with when the person corrected them on the approval card before approving;
 * `arguments` keeps the call as the model proposed it. Null when it ran as proposed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_tool_calls') && ! Schema::hasColumn('agent_tool_calls', 'approved_arguments')) {
            Schema::table('agent_tool_calls', function (Blueprint $table) {
                $table->json('approved_arguments')->nullable()->after('arguments');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agent_tool_calls', 'approved_arguments')) {
            Schema::table('agent_tool_calls', function (Blueprint $table) {
                $table->dropColumn('approved_arguments');
            });
        }
    }
};
