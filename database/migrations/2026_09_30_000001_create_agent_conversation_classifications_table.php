<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each chat is about, how the person sounds and whether it was resolved,
 * written by the ClassifierAgent side agent after an answer (config
 * `classify`, off by default). Lives next to the conversations — in the
 * tenant database of a database-per-tenant app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_conversation_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('conversation_id', 36)->unique();
            $table->string('topic', 60)->index();
            $table->string('sentiment', 10); // positive | neutral | negative
            $table->boolean('resolved')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_conversation_classifications');
    }
};
