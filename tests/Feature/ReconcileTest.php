<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentTurns;

// A turn whose worker died fails on the next poll; a poll with nothing stale writes nothing (it ran an UPDATE over the
// conversation's active turns, which deadlocked on MySQL with the job saving a finished turn).
it('fails only the turns whose worker stopped writing, and writes nothing on a normal poll', function () {
    $user = $this->user();
    $conversation = (string) Str::uuid7();
    $turn = fn (string $status) => AgentTurn::query()->create(['id' => (string) Str::uuid7(), 'conversation_id' => $conversation, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'status' => $status, 'input' => ['prompt' => 'Which widgets are live?']]);

    $stale = $turn(AgentTurn::RUNNING);
    $this->travel(AgentTurns::jobTimeout() + 61)->seconds();
    $running = $turn(AgentTurn::RUNNING);

    $updates = 0;
    DB::listen(function ($query) use (&$updates) {
        $updates += str_starts_with(strtolower($query->sql), 'update') ? 1 : 0;
    });

    app(AgentTurns::class)->state($conversation);

    expect($stale->fresh()->status)->toBe(AgentTurn::FAILED)
        ->and($stale->fresh()->error)->toBe(__('The answer was interrupted — the worker stopped before it was done.'))
        ->and($running->fresh()->status)->toBe(AgentTurn::RUNNING)
        ->and($updates)->toBe(1);

    // Nothing stale now: polling again only reads.
    $updates = 0;
    app(AgentTurns::class)->state($conversation);
    app(AgentTurns::class)->reconcile($conversation);

    expect($updates)->toBe(0)
        ->and($running->fresh()->status)->toBe(AgentTurn::RUNNING);
});
