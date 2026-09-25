<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Conversations a person pinned to the top of their list. Lives next to the conversations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_pinned_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('conversation_id', 36);
            $table->string('participant_type')->nullable();
            $table->unsignedBigInteger('participant_id')->nullable();
            $table->timestamps();

            $table->unique('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_pinned_conversations');
    }
};
