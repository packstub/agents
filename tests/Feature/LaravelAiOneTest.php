<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Packstub\Agents\Ai\Middleware\AttachContext;
use Packstub\Agents\Ai\Middleware\EnforceBudget;
use Packstub\Agents\Exceptions\TurnRefused;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentAnswerVersion;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Support\AgentUsage;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use Packstub\Agents\Tests\Fixtures\WidgetResource;
use Packstub\Agents\Tests\Fixtures\WidgetServer;

use function Pest\Laravel\actingAs;

// What laravel/ai 1.0 changed under the engine: the steps a stored answer keeps, middleware around each model
// round-trip, inclusive token counts, a failed turn on record, a resume folded into the answer it paused on.
beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::useServer(WidgetServer::class);
    Agents::useResources([WidgetResource::class]);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
});

/** A message row in the 0.x vocabulary (tool_calls, tool_results, approval_state), as an app that upgrades has it. */
function legacyMessage(string $conversation, array $row): string
{
    $id = (string) Str::uuid7();

    DB::table('agent_conversation_messages')->insert($row + [
        'id' => $id, 'conversation_id' => $conversation, 'participant_type' => 'App\\Models\\User', 'participant_id' => 1, 'agent' => WidgetAgent::class,
        'role' => 'assistant', 'content' => '', 'attachments' => '[]', 'tool_calls' => '[]', 'tool_results' => '[]', 'usage' => '[]', 'meta' => '[]', 'approval_state' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('rewrites the messages an app stored before 1.0 into steps with a status, keeps the old columns, and restores an old version', function () {
    $user = $this->user();
    actingAs($user);

    // The table as 0.x created it.
    Schema::drop('agent_conversation_messages');
    Schema::create('agent_conversation_messages', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->string('conversation_id', 36)->index();
        $table->string('participant_type')->nullable();
        $table->unsignedBigInteger('participant_id')->nullable();
        $table->string('agent');
        $table->string('role', 25);
        $table->text('content');
        $table->text('attachments');
        $table->text('tool_calls');
        $table->text('tool_results');
        $table->text('usage');
        $table->text('meta');
        $table->text('approval_state')->nullable();
        $table->timestamps();
        $table->index(['participant_type', 'participant_id'], 'participant_index');
    });

    $conversation = app(AgentConversationStore::class)->startConversation($user, 'Renames');
    $call = fn (string $id, string $name) => ['id' => $id, 'name' => 'rename-widget', 'arguments' => ['id' => 1, 'name' => $name], 'result_id' => null];
    $question = legacyMessage($conversation, ['role' => 'user', 'content' => 'Rename Alpha, please.', 'participant_id' => $user->id, 'participant_type' => $user->getMorphClass()]);
    $answered = legacyMessage($conversation, [
        'content' => 'Renamed.', 'participant_id' => $user->id, 'participant_type' => $user->getMorphClass(), 'usage' => json_encode(['prompt_tokens' => 100, 'completion_tokens' => 20, 'cache_read_input_tokens' => 50, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 5]),
        'tool_calls' => json_encode([$call('c1', 'Alpha II'), $call('c2', 'Alpha III')]),
        'tool_results' => json_encode([$call('c1', 'Alpha II') + ['result' => 'The user rejected this tool call.', 'denied' => true], $call('c2', 'Alpha III') + ['result' => '{"renamed":true}']]),
        'approval_state' => json_encode(['pending' => []]),
    ]);
    $paused = legacyMessage($conversation, [
        'participant_id' => $user->id, 'participant_type' => $user->getMorphClass(),
        'tool_calls' => json_encode([$call('c3', 'Alpha IV')]),
        'approval_state' => json_encode(['pending' => ['c3' => 'Rename widget #1 to Alpha IV?']]),
    ]);

    (require __DIR__.'/../../database/migrations/2026_09_28_000001_add_steps_to_agent_conversation_messages_table.php')->up();

    expect(Schema::hasColumns('agent_conversation_messages', ['steps', 'status', 'tool_calls', 'tool_results', 'approval_state']))->toBeTrue()
        ->and(collect(Schema::getIndexes('agent_conversation_messages'))->firstWhere('name', 'participant_index')['columns'])->toBe(['participant_type', 'participant_id', 'agent']);

    $rows = ConversationMessage::query()->where('conversation_id', $conversation)->orderBy('id')->get()->keyBy('id');

    expect($rows[$question]->steps)->toBe([])
        ->and($rows[$question]->status)->toBe(MessageStatus::Completed)
        ->and($rows[$answered]->status)->toBe(MessageStatus::Completed)
        ->and($rows[$answered]->steps)->toHaveCount(1)
        ->and($rows[$answered]->steps[0]['content'])->toBe('Renamed.')
        ->and($rows[$answered]->tool_calls)->toBe([
            ['id' => 'c1', 'name' => 'rename-widget', 'arguments' => ['id' => 1, 'name' => 'Alpha II'], 'result_id' => null, 'result' => 'The user rejected this tool call.', 'denied' => true],
            ['id' => 'c2', 'name' => 'rename-widget', 'arguments' => ['id' => 1, 'name' => 'Alpha III'], 'result_id' => null, 'result' => '{"renamed":true}'],
        ])
        ->and($rows[$answered]->tool_results)->toHaveCount(2)
        ->and($rows[$paused]->status)->toBe(MessageStatus::Paused)
        ->and($rows[$paused]->tool_calls[0])->toMatchArray(['id' => 'c3', 'approval_reason' => 'Rename widget #1 to Alpha IV?'])
        ->and($rows[$paused]->tool_results)->toBe([])
        ->and(DB::table('agent_conversation_messages')->where('id', $answered)->value('tool_calls'))->toBeNull();

    // The transcript and the pending proposals read the rewritten rows as before; the old usage keys still count.
    $chat = AgentChat::for($user, $conversation);
    $messages = $chat->messages();
    expect($messages[1]['tools'][0])->toMatchArray(['id' => 'c1', 'rejected' => true, 'pending' => false])
        ->and($messages[1]['tools'][1])->toMatchArray(['id' => 'c2', 'result' => '{"renamed":true}', 'pending' => false])
        ->and($messages[2]['tools'][0])->toMatchArray(['id' => 'c3', 'pending' => true, 'question' => 'Rename widget #1 to Alpha IV?'])
        ->and(array_keys(app(AgentConversationStore::class)->pendingCalls($conversation, $user)))->toBe(['c3'])
        ->and(AgentUsage::in($rows[$answered]->usage))->toBe(150)
        ->and(AgentUsage::out($rows[$answered]->usage))->toBe(25);

    // A version kept before the upgrade holds a row in the old shape: putting it back writes it in the new one.
    $store = app(AgentConversationStore::class);
    $store->dropMessagesAfter($conversation, $question, keepVersion: false);
    AgentAnswerVersion::query()->create(['conversation_id' => $conversation, 'question_id' => $question, 'question' => 'Rename Alpha, please.', 'rows' => [[
        'id' => (string) Str::uuid7(), 'conversation_id' => $conversation, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'agent' => WidgetAgent::class,
        'role' => 'assistant', 'content' => 'An earlier answer.', 'attachments' => '[]', 'tool_calls' => json_encode([$call('c9', 'Alpha IX')]), 'tool_results' => json_encode([$call('c9', 'Alpha IX') + ['result' => 'ok']]),
        'usage' => '[]', 'meta' => '[]', 'approval_state' => null, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]]]);
    $version = $store->versionsOf($conversation, $question)->first();

    expect($store->restoreVersion($conversation, $question, $version->id))->toBeTrue();
    $restored = ConversationMessage::query()->where('conversation_id', $conversation)->orderBy('id')->get()->last();
    expect($restored->content)->toBe('An earlier answer.')
        ->and($restored->status)->toBe(MessageStatus::Completed)
        ->and($restored->tool_results[0])->toMatchArray(['id' => 'c9', 'result' => 'ok']);
});

it('checks the budget on the first step only and attaches the dynamic block to the question on every step, not on a resume', function () {
    $user = $this->user();
    actingAs($user);
    $agent = Agents::agent();
    $step = fn (int $number, array $messages) => new PendingStep($number, false, 'anthropic', 'claude-opus-5', null, $messages, [], null, null, invocationId: 'inv-1');
    $history = [new Message('user', 'Earlier question'), new Message('assistant', 'Earlier answer')];
    $question = new UserMessage('How many widgets are live?');
    $results = new ToolResultMessage(collect([new ToolResult('c1', 'list-widgets', [], '[]')]));

    // Over the prompt limit: refused before the provider is called; under it, counted once for the turn.
    config(['packstub-agents.limits.prompt_max_chars' => 10]);
    AgentLimits::flush();
    expect(fn () => (new EnforceBudget)->handle($step(0, [...$history, $question]), fn () => 'sent'))->toThrow(TurnRefused::class);
    config(['packstub-agents.limits.prompt_max_chars' => 1000, 'packstub-agents.limits.turns_per_minute' => 1]);
    AgentLimits::flush();
    expect((new EnforceBudget)->handle($step(0, [...$history, $question]), fn () => 'sent'))->toBe('sent')
        ->and((new EnforceBudget)->handle($step(1, [...$history, $question, new Message('assistant', ''), $results]), fn () => 'sent'))->toBe('sent') // the tool step of the same turn
        ->and(fn () => (new EnforceBudget)->handle($step(0, [...$history, $question]), fn () => 'sent'))->toThrow(TurnRefused::class); // the next turn, over the per-minute limit

    // The block rides with the question on the first step and again on the tool step; the history stays as it was.
    $middleware = new AttachContext($agent);
    $first = $middleware->handle($step(0, [...$history, $question]), fn (PendingStep $s) => $s);
    $second = $middleware->handle($step(1, [...$history, $question, new Message('assistant', ''), $results]), fn (PendingStep $s) => $s);

    expect($first->messages[2])->toBeInstanceOf(UserMessage::class)
        ->and($first->messages[2]->content)->toStartWith("## Now\n")->toEndWith("\n\nHow many widgets are live?")
        ->and($first->messages[0]->content)->toBe('Earlier question')
        ->and($second->messages[2]->content)->toBe($first->messages[2]->content)
        ->and($second->messages[4])->toBe($results);

    // A turn that resumes a proposal starts on a tool result: nothing to carry the block, so it goes without.
    $resume = (new AttachContext($agent))->handle($step(0, [...$history, $question, new Message('assistant', ''), $results]), fn (PendingStep $s) => $s);
    expect($resume->messages[2]->content)->toBe('How many widgets are live?');

    // The app's middleware sees every step of a turn, the question as typed, in the person's context.
    config(['packstub-agents.limits.turns_per_minute' => null]);
    AgentLimits::flush();
    $seen = [];
    Agents::useMiddleware([function (PendingStep $step, Closure $next) use (&$seen) {
        $seen[] = [$step->number, EnforceBudget::question($step), auth()->id()];

        return $next($step);
    }]);
    WidgetAgent::fake([new ToolCall('c1', 'list-widgets', ['limit' => 5]), 'Two widgets are live.']);
    $this->widgets();
    $turn = AgentChat::for($user)->send('How many widgets are live?');

    expect($turn->status)->toBe(AgentTurn::DONE, (string) $turn->error)
        ->and($seen)->toBe([[0, 'How many widgets are live?', $user->id], [1, null, $user->id]]);
});

it('keeps what a turn produced before the provider gave up, with the error, and offers to produce it again', function () {
    $user = $this->user();
    actingAs($user);
    $this->widgets();
    WidgetAgent::fake([new ToolCall('c1', 'list-widgets', ['limit' => 5]), fn () => throw new RuntimeException('The provider is overloaded.')]);

    $chat = AgentChat::for($user);
    $turn = $chat->send('How many widgets are live?');
    $messages = AgentChat::for($user, $chat->conversation())->messages();

    expect($turn->status)->toBe(AgentTurn::FAILED)
        ->and($turn->error)->toBe('The provider is overloaded.')
        ->and($messages)->toHaveCount(2)
        ->and($messages[1])->toMatchArray(['role' => 'assistant', 'failed' => true, 'error' => 'The provider is overloaded.', 'regenerable' => true])
        ->and($messages[1]['tools'][0])->toMatchArray(['tool' => 'list-widgets', 'readOnly' => true])
        ->and(ConversationMessage::query()->find($messages[1]['id'])->status)->toBe(MessageStatus::Failed)
        ->and($messages[0]['unanswered'])->toBeFalse();
});

it('stores the answer to a typed decision after the reply, and folds one made with the buttons into the paused answer', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    $store = app(AgentConversationStore::class);
    $turns = app(AgentTurns::class);
    $paused = function (string $conversation) use ($user, $alpha) {
        return ConversationMessage::query()->create([
            'id' => (string) Str::uuid7(), 'conversation_id' => $conversation, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
            'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => 'Shall I?', 'attachments' => [], 'usage' => [], 'meta' => [],
            'steps' => [['content' => 'Shall I?', 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => [], 'tool_calls' => [
                ['id' => 'c1', 'name' => 'rename-widget', 'arguments' => ['id' => $alpha->id, 'name' => 'Alpha II'], 'result_id' => null, 'approval_reason' => 'Rename?'],
            ]]],
            'status' => MessageStatus::Paused,
        ]);
    };

    // Typed: the reply is a row, the answer that follows is a row after it, the paused answer is settled.
    $conversation = $store->startConversation($user, 'Rename Alpha');
    $question = $store->storeQuestion($conversation, $user, WidgetAgent::class, 'Rename Alpha to Alpha II.');
    $row = $paused($conversation);
    WidgetAgent::fake(['Done, it is Alpha II now.']);
    $turn = $turns->enqueue($conversation, $user, ['prompt' => 'Yes, go ahead.'], null, 'auto', null);
    expect($turn->fresh()->status)->toBe(AgentTurn::DONE);

    $rows = ConversationMessage::query()->where('conversation_id', $conversation)->orderBy('id')->get();
    expect($rows->pluck('content')->all())->toBe(['Rename Alpha to Alpha II.', 'Shall I?', 'Yes, go ahead.', 'Done, it is Alpha II now.'])
        ->and($rows[1]->status)->toBe(MessageStatus::Completed)
        ->and($rows[1]->tool_calls[0])->toMatchArray(['id' => 'c1', 'approval_reason' => 'Rename?']) // under a fake laravel/ai does not run the approved call, so no result lands on it
        ->and($rows[3]->status)->toBe(MessageStatus::Completed)
        ->and(AgentChat::for($user, $conversation)->messages()->last()['text'])->toBe('Done, it is Alpha II now.');

    // With the buttons: no reply row, the answer carries on in the paused one.
    $conversation = $store->startConversation($user, 'Rename Alpha');
    $store->storeQuestion($conversation, $user, WidgetAgent::class, 'Rename Alpha to Alpha II.');
    $paused($conversation);
    WidgetAgent::fake(['Done, it is Alpha II now.']);
    expect(AgentChat::for($user, $conversation)->decide('c1', true)?->status)->toBe(AgentTurn::DONE);

    $rows = ConversationMessage::query()->where('conversation_id', $conversation)->orderBy('id')->get();
    expect($rows->pluck('content')->all())->toBe(['Rename Alpha to Alpha II.', 'Done, it is Alpha II now.'])
        ->and($rows[1]->status)->toBe(MessageStatus::Completed)
        ->and($rows[1]->tool_calls[0])->toMatchArray(['id' => 'c1'])
        ->and(AgentChat::for($user, $conversation)->messages()->last())->toMatchArray(['text' => 'Done, it is Alpha II now.', 'regenerable' => true]);
});

it('shows what the model thought before it answered, when the provider reports it', function () {
    $user = $this->user();
    actingAs($user);
    $conversation = app(AgentConversationStore::class)->startConversation($user, 'Widgets');
    app(AgentConversationStore::class)->storeQuestion($conversation, $user, WidgetAgent::class, 'How many?');
    ConversationMessage::query()->create([
        'id' => (string) Str::uuid7(), 'conversation_id' => $conversation, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => 'Two.', 'attachments' => [], 'usage' => [], 'meta' => [], 'status' => MessageStatus::Completed,
        'steps' => [
            ['content' => '', 'reasoning' => 'I should count the live ones.', 'replay_blocks' => [], 'provider_tool_calls' => [], 'tool_calls' => [['id' => 'c1', 'name' => 'list-widgets', 'arguments' => [], 'result_id' => null, 'result' => '[]']]],
            ['content' => 'Two.', 'reasoning' => 'Two are live.', 'replay_blocks' => [], 'provider_tool_calls' => [], 'tool_calls' => []],
        ],
    ]);

    $messages = AgentChat::for($user, $conversation)->messages();
    expect($messages[1]['reasoning'])->toBe("I should count the live ones.\n\nTwo are live.")
        ->and($messages[0]['reasoning'])->toBe('');
});

it('reads a usage written either way: inclusive counts from 1.0, the 0.x keys from before', function () {
    $inclusive = ['input_tokens' => 1500, 'output_tokens' => 320, 'cache_read_input_tokens' => 1000, 'cache_write_input_tokens' => null, 'reasoning_tokens' => 20];
    $legacy = ['prompt_tokens' => 500, 'completion_tokens' => 300, 'cache_read_input_tokens' => 1000, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 20];

    foreach ([$inclusive, $legacy] as $usage) {
        expect(AgentUsage::in($usage))->toBe(1500)
            ->and(AgentUsage::out($usage))->toBe(320)
            ->and(AgentUsage::total($usage))->toBe(1820)
            ->and(AgentUsage::priced($usage))->toBe(['uncached_in' => 500, 'cache_read' => 1000, 'cache_write' => 0, 'out' => 320]);
    }

    expect(AgentUsage::in(null))->toBeNull()
        ->and(AgentUsage::total(null))->toBe(0)
        ->and(AgentUsage::isInclusive($inclusive))->toBeTrue()
        ->and(AgentUsage::isInclusive($legacy))->toBeFalse();
});
