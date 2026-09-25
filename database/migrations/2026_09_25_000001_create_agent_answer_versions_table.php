<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Earlier answers to a question, kept when the answer is produced again or
 * the question edited: the rows that followed the question, as they were,
 * so the chat can page through the versions and put one back. Lives next to
 * the conversations (the tenant database of a database-per-tenant app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_answer_versions', function (Blueprint $table) {
            $table->id();
            $table->string('conversation_id', 36);
            $table->string('question_id', 36); // the question the rows answered
            $table->text('question'); // the question's text at the time (an edit rewrites the row)
            $table->json('rows'); // the message rows after the question, as stored
            $table->timestamps();

            $table->index(['conversation_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_answer_versions');
    }
};
