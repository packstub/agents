<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tool call, from an agent turn and from an MCP client alike:
 * who ran it and where, the arguments, the approval decision of a write
 * proposed in the chat, and how it ended with the result the model read
 * (config `tool_log`). Not deleted with the conversation; pruned after
 * tool_log.keep_days. Lives next to the conversations — in the tenant
 * database of a database-per-tenant app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->string('call_id'); // the provider's tool call id; a generated one for an MCP or direct call
            $table->string('channel', 16); // agent | mcp | direct
            $table->string('turn_id', 36)->nullable();
            $table->string('conversation_id', 36)->nullable();
            $table->string('participant_type')->nullable();
            $table->unsignedBigInteger('participant_id')->nullable();
            $table->unsignedBigInteger('token_id')->nullable(); // the agent access token of an MCP call
            $table->string('tenant')->nullable(); // the workspace key, when the app has workspaces
            $table->string('panel')->nullable();
            $table->string('tool');
            $table->string('ability')->nullable(); // the ability the tool needs; null = any member
            $table->json('arguments')->nullable();
            $table->string('status', 16); // called | proposed | approved | running | succeeded | failed | refused | rejected
            $table->text('question')->nullable(); // the proposal as the person saw it ("Rename widget #7 to Alpha II?")
            $table->string('decision', 16)->nullable(); // approved | rejected | superseded
            $table->string('decided_by', 16)->nullable(); // words | classifier | app; null = the buttons or a decide() call
            $table->timestamp('decided_at')->nullable();
            $table->string('refused_by', 16)->nullable(); // workspace | role | token
            $table->json('result')->nullable(); // what the model read, when tool_log.results is on
            $table->text('error')->nullable(); // the refusal or the error the model read
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'call_id']);
            $table->index(['tenant', 'created_at']);
            $table->index('created_at'); // pruning
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_calls');
    }
};
