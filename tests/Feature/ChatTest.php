<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Models\AgentMessageFeedback;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Models\ConversationSummary;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentTokens;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Tools\RetireWidget;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use Packstub\Agents\Tests\Fixtures\WidgetResource;
use Packstub\Agents\Tests\Fixtures\WidgetServer;

use function Pest\Laravel\actingAs;

// A chat surface of the app's own — a JSON API, a widget, a command — over AgentChat, with the server's tools.
beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::useServer(WidgetServer::class);
    Agents::useResources([WidgetResource::class]);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
});

/** A conversation of the person with the given rows (role, content, tool calls…), ids ordered in time. */
function conversationWith(object $user, array $rows, string $title = 'Renames'): string
{
    $conversation = Conversation::query()->create(['id' => (string) Str::uuid(), 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'title' => $title]);
    $at = now()->subMinutes(10);

    foreach ($rows as $row) {
        usleep(1100);
        ConversationMessage::query()->create($row + [
            'id' => (string) Str::uuid7(), 'created_at' => $at = $at->addMinute(), 'conversation_id' => $conversation->id, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
            'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => '', 'attachments' => [], 'meta' => [], 'usage' => [], 'tool_calls' => [], 'tool_results' => [],
        ]);
    }

    return $conversation->id;
}

it('answers a question into a persisted conversation, rates the answer, and keeps one person\'s chats from another', function () {
    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake(['Two widgets are live.']);

    $chat = AgentChat::for($user);
    expect($chat->conversation())->toBeNull()
        ->and($chat->messages())->toBeEmpty()
        ->and($chat->history())->toBeNull()
        ->and($chat->suggestions())->toContain('What needs attention today?')
        ->and($chat->idle())->toBeTrue();

    $turn = $chat->send('How many widgets are live?');

    expect($turn)->toBeInstanceOf(AgentTurn::class)
        ->and($turn->status)->toBe(AgentTurn::DONE) // the sync queue ran it
        ->and($chat->conversation())->not->toBeNull()
        ->and($chat->title())->toBe('Fake response for prompt: How many widgets are live?') // the provider titles a new chat after its first answer
        ->and($chat->suggestions())->toBe([]);

    $messages = $chat->messages();
    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toMatchArray(['role' => 'user', 'text' => 'How many widgets are live?', 'editable' => true, 'unanswered' => false])
        ->and($messages[1]['role'])->toBe('assistant')
        ->and($messages[1]['html'])->toContain('Two widgets are live')
        ->and($messages[1]['regenerable'])->toBeTrue()
        ->and($messages[1]['rating'])->toBeNull()
        ->and($chat->live())->toBe(['active' => null, 'queued' => [], 'ended' => null]);

    $history = $chat->history();
    expect($history['turns']['count'])->toBe(1)
        ->and($history['meter'])->toBeFalse()
        ->and($history['notice'])->toBeFalse();

    $chat->rate($messages[1]['id'], 'up');
    expect(AgentMessageFeedback::query()->where('message_id', $messages[1]['id'])->value('rating'))->toBe('up')
        ->and($chat->messages()[1]['rating'])->toBe('up');

    // Another person never sees this conversation; the same person opens it again by id.
    expect(AgentChat::for($this->user())->owns($chat->conversation()))->toBeFalse()
        ->and(AgentChat::for($user)->owns($chat->conversation()))->toBeTrue()
        ->and(AgentChat::for($user, $chat->conversation())->messages())->toHaveCount(2);
});

it('keeps a question the provider could not answer, says so, and answers it on retry', function () {
    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake([fn () => throw new RuntimeException('AI provider [gemini] is overloaded.')]);

    $chat = AgentChat::for($user);
    $turn = $chat->send('How many widgets are live?');

    expect($turn->status)->toBe(AgentTurn::FAILED)
        ->and($turn->error)->toBe('AI provider [gemini] is overloaded.')
        ->and($chat->messages())->toHaveCount(1)
        ->and($chat->messages()->last()['unanswered'])->toBeTrue()
        ->and($chat->live()['ended'])->toMatchArray(['status' => AgentTurn::FAILED, 'error' => 'AI provider [gemini] is overloaded.', 'decision' => false]);

    WidgetAgent::fake(['Two widgets are live.']);
    expect($chat->retry()?->status)->toBe(AgentTurn::DONE);

    $messages = AgentChat::for($user, $chat->conversation())->messages();
    expect($messages)->toHaveCount(2)
        ->and($messages[1]['html'])->toContain('Two widgets are live');

    // Nothing to retry once the question is answered.
    expect(AgentChat::for($user, $chat->conversation())->retry())->toBeNull()
        ->and(ConversationMessage::query()->where('conversation_id', $chat->conversation())->count())->toBe(2);
});

it('reads a decided proposal as approved or rejected and a waiting one as pending, and rejects with a reason', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    $call = fn (string $id, string $name) => ['id' => $id, 'name' => 'rename-widget', 'arguments' => ['id' => $alpha->id, 'name' => $name]];

    $id = conversationWith($user, [
        ['role' => 'user', 'content' => 'Rename Alpha, please.'],
        ['tool_calls' => [$call('c1', 'Alpha II')], 'tool_results' => [$call('c1', 'Alpha II') + ['result' => 'The user rejected this tool call.', 'denied' => true]], 'approval_state' => ['pending' => []]],
        ['tool_calls' => [$call('c2', 'Alpha III')], 'tool_results' => [$call('c2', 'Alpha III') + ['result' => '{"renamed":true}']], 'approval_state' => ['pending' => []]],
        ['tool_calls' => [$call('c3', 'Alpha IV')], 'tool_results' => [], 'approval_state' => ['pending' => ['c3' => ['name' => 'rename-widget']]]],
    ]);

    expect(AgentChat::writeToolNames())->toBe(['rename-widget']);

    $chat = AgentChat::for($user, $id);
    $tools = $chat->messages()->skip(1)->map(fn (array $m) => $m['tools'][0])->values();

    expect($tools[0])->toMatchArray(['id' => 'c1', 'tool' => 'rename-widget', 'name' => 'Rename Widget', 'question' => "Rename widget #{$alpha->id} to Alpha II?", 'pending' => false, 'rejected' => true, 'readOnly' => false, 'held' => null])
        ->and($tools[1])->toMatchArray(['id' => 'c2', 'question' => "Rename widget #{$alpha->id} to Alpha III?", 'pending' => false, 'rejected' => false, 'result' => '{"renamed":true}'])
        ->and($tools[2])->toMatchArray(['id' => 'c3', 'question' => "Rename widget #{$alpha->id} to Alpha IV?", 'pending' => true, 'rejected' => false, 'result' => null])
        ->and($chat->messages()->last()['regenerable'])->toBeFalse(); // a waiting proposal is answered with a decision, not produced again

    // Rejecting hands the model a reason instead of a bare "no", so the turn continues and the model can answer.
    WidgetAgent::fake(['Understood, I left the name as it is.']);
    expect($chat->decide('c3', false)?->status)->toBe(AgentTurn::DONE);

    WidgetAgent::assertPrompted(function ($prompt) {
        $decision = $prompt->approvalDecisions?->get('c3');

        return $decision?->isRejected() && $decision->result === AgentChat::rejectionResult();
    });
    expect(ConversationMessage::query()->where('conversation_id', $id)->where('content', 'like', '%left the name%')->exists())->toBeTrue();
});

it('shows a decision on one of two proposals as held on its row until the other is decided', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha, $beta] = $this->widgets();
    Queue::fake();

    $id = conversationWith($user, [
        ['role' => 'user', 'content' => 'Rename Alpha and Beta, please.'],
        ['tool_calls' => [
            ['id' => 'c1', 'name' => 'rename-widget', 'arguments' => ['id' => $alpha->id, 'name' => 'Alpha II']],
            ['id' => 'c2', 'name' => 'rename-widget', 'arguments' => ['id' => $beta->id, 'name' => 'Beta II']],
        ], 'approval_state' => ['pending' => ['c1' => 'Rename Alpha?', 'c2' => 'Rename Beta?']]],
    ]);

    $chat = AgentChat::for($user, $id);
    $turn = $chat->decide('c1', true);
    Queue::assertNothingPushed();
    expect($turn->status)->toBe(AgentTurn::QUEUED);

    $tools = AgentChat::for($user, $id)->messages()->last()['tools'];
    expect($tools[0])->toMatchArray(['id' => 'c1', 'pending' => true, 'held' => true])
        ->and($tools[1])->toMatchArray(['id' => 'c2', 'pending' => true, 'held' => null])
        ->and(AgentChat::for($user, $id)->live()['queued'])->toBe([]); // a held decision is not a queued question

    // The second decision joins the waiting turn, which starts with both.
    expect(AgentChat::for($user, $id)->decide('c2', false)?->decisions())->toBe(['c1' => true, 'c2' => false]);
    Queue::assertPushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $turn->id);
});

it('queues follow-ups behind the running turn, edits or removes them, and stops the answer', function () {
    $user = $this->user();
    actingAs($user);
    Queue::fake();

    $chat = AgentChat::for($user);
    $first = $chat->send('First');
    expect($first->status)->toBe(AgentTurn::PENDING)
        ->and($chat->idle())->toBeFalse()
        ->and($chat->live()['active']['id'])->toBe($first->id)
        ->and($chat->messages()->last()['unanswered'])->toBeFalse() // being answered
        ->and($chat->decide('c1', true))->toBeNull() // no decisions while a turn runs
        ->and($chat->regenerate())->toBeNull();

    $second = $chat->send('Second');
    $third = $chat->send('Third');
    expect(array_column($chat->live()['queued'], 'text'))->toBe(['Second', 'Third']);

    expect($chat->editQueued($second->id))->toBe('Second')
        ->and($chat->removeQueued($third->id))->toBeTrue()
        ->and($chat->removeQueued($third->id))->toBeFalse()
        ->and($chat->live()['queued'])->toBe([]);

    $chat->stop();
    expect(app(AgentTurns::class)->stopRequested($first->fresh()))->toBeTrue();
});

it('regenerates the last answer and resends an edited question', function () {
    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake(['Two.']);

    $chat = AgentChat::for($user);
    $chat->send('How many?');
    $question = ConversationMessage::query()->where('conversation_id', $chat->conversation())->where('role', 'user')->sole();

    WidgetAgent::fake(['Two of them.']);
    expect($chat->regenerate()?->status)->toBe(AgentTurn::DONE)
        ->and(ConversationMessage::query()->where('conversation_id', $chat->conversation())->orderBy('id')->pluck('content')->all())->toBe(['How many?', 'Two of them.']);

    WidgetAgent::fake(['Two are live.']);
    expect($chat->resend('How many are live?')?->status)->toBe(AgentTurn::DONE)
        ->and($chat->resend('   '))->toBeNull()
        ->and(ConversationMessage::query()->where('conversation_id', $chat->conversation())->orderBy('id')->pluck('content')->all())->toBe(['How many are live?', 'Two are live.'])
        ->and($question->fresh()->content)->toBe('How many are live?');
});

it('sends nothing for an empty question or when the agent is off', function () {
    $user = $this->user();
    actingAs($user);

    expect(AgentChat::for($user)->send('   '))->toBeNull()
        ->and(Conversation::query()->count())->toBe(0);

    config(['packstub-agents.enabled' => false]);
    expect(AgentChat::for($user)->send('Hi'))->toBeNull()
        ->and(AgentChat::for($user)->suggestions())->toBe([])
        ->and(Conversation::query()->count())->toBe(0);
});

it('phrases a proposal, folds a result, names a cut-short reason and a duration, and lists the history parts', function () {
    actingAs($this->user());

    expect(AgentChat::question(app(RetireWidget::class), 'retire-widget', ['id' => 12]))->toBe('Retire Widget 12?')
        ->and(AgentChat::question(null, 'archive-widget', ['id' => 3, 'reason' => 'old']))->toBe('Archive Widget 3?')
        ->and(AgentChat::question(null, 'archive-widget', []))->toBe('Archive Widget?')
        ->and(AgentChat::resultText('{"renamed":true,"widget":{"id":1}}'))->toBe("{\n    \"renamed\": true,\n    \"widget\": {\n        \"id\": 1\n    }\n}")
        ->and(AgentChat::resultText('plain text'))->toBe('plain text')
        ->and(AgentChat::resultText(null))->toBeNull()
        ->and(AgentChat::cutShortText('length'))->toBe(__('The answer hit the model\'s length limit.'))
        ->and(AgentChat::cutShortText('other'))->toBe(__('The provider closed the stream before the answer was complete.'))
        ->and(AgentChat::duration(4_400))->toBe('4 s')
        ->and(AgentChat::duration(90_000))->toBe('1.5 min')
        ->and(array_keys(AgentChat::breakdownLabels()))->toBe(['summary', 'questions', 'answers', 'tool_calls', 'tool_results', 'tool_results_pruned']);
});

it('turns a chart result into a Chart.js payload and a table result into the resource to embed', function () {
    expect(AgentChat::chartFromResult(json_encode(['chart' => ['type' => 'pie', 'title' => 'By status', 'labels' => ['live', 'draft'], 'datasets' => [['label' => 'Widgets', 'data' => [2, 1]]]]])))
        ->toMatchArray(['type' => 'pie', 'title' => 'By status'])
        ->and(AgentChat::chartFromResult(['chart' => ['type' => 'radar', 'labels' => ['a'], 'datasets' => [['data' => [1]]]]])['type'])->toBe('bar')
        ->and(AgentChat::chartFromResult(['chart' => ['labels' => [], 'datasets' => []]]))->toBeNull()
        ->and(AgentChat::chartFromResult('not json'))->toBeNull()
        ->and(AgentChat::tableFromResult(['table' => ['resource' => 'widgets', 'filters' => ['status' => ['live']], 'title' => 'Live widgets']]))->toBe(['resource' => 'widgets', 'filters' => ['status' => ['live']], 'title' => 'Live widgets'])
        ->and(AgentChat::tableFromResult(['table' => ['resource' => 'orders']]))->toBeNull();
});

it('lists the models by provider with what each label leaves out', function () {
    expect(AgentChat::modelMenu())->toHaveKey('anthropic')
        ->and(AgentChat::modelMenu()['anthropic']['fast'])->toBe(['label' => 'Fast', 'detail' => 'Test Claude Fast']);

    // An entry named after its model says its key instead; one that already does says nothing more.
    config(['packstub-agents.models.anthropic' => [
        'auto' => ['label' => null, 'model' => 'claude-opus-5', 'effort' => null],
        'deep' => ['label' => null, 'model' => 'claude-opus-5', 'effort' => 'xhigh'],
    ]]);
    expect(AgentChat::modelMenu()['anthropic'])->toBe([
        'auto' => ['label' => 'Claude Opus 5', 'detail' => 'Auto'],
        'deep' => ['label' => 'Claude Opus 5 · Deep', 'detail' => null],
    ]);
});

it('deletes a conversation with its messages and summary, keeping its turns in the log', function () {
    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake(['Two.']);

    $chat = AgentChat::for($user);
    $chat->send('How many?');
    $id = $chat->conversation();
    ConversationSummary::query()->create(['conversation_id' => $id, 'content' => 'A count.', 'through_message_id' => null]);

    app(AgentConversationStore::class)->deleteConversation($id);

    expect(Conversation::query()->whereKey($id)->exists())->toBeFalse()
        ->and(ConversationMessage::query()->where('conversation_id', $id)->count())->toBe(0)
        ->and(ConversationSummary::query()->where('conversation_id', $id)->count())->toBe(0)
        ->and(AgentTurn::query()->forConversation($id)->count())->toBe(1);
});

it('mints an access token narrowed to the tools the role allows, the workspace and an expiry', function () {
    $user = $this->user();
    actingAs($user);

    expect(AgentTokens::availableTools())->toHaveKeys(['list-widgets', 'rename-widget', 'draw-chart'])
        ->and(AgentTokens::availableTools()['rename-widget'])->toMatchArray(['title' => 'Rename Widget', 'readOnly' => false])
        ->and(AgentTokens::availableTools()['list-widgets']['readOnly'])->toBeTrue()
        ->and(AgentTokens::toolTitles())->toMatchArray(['rename-widget' => 'Rename Widget'])
        ->and(array_keys(AgentTokens::expiryOptions()))->toBe(['never', 7, 30, 90, 365]) // PHP keys
        ->and(AgentTokens::expiresAt('never'))->toBeNull()
        ->and(AgentTokens::expiresAt('30')?->isSameDay(now()->addDays(30)))->toBeTrue()
        ->and(AgentTokens::mcpUrl())->toBe(url('/mcp'))
        ->and(AgentTokens::serverSlug())->toBe('ask-widgets');

    // A write tool is only scoped on a token that may write; a tool the role does not allow is never scoped.
    expect(AgentTokens::abilities(['read'], ['rename-widget', 'list-widgets', 'nope']))->toBe(['read', 'tool:list-widgets'])
        ->and(AgentTokens::abilities(['read', 'write'], ['rename-widget'], 'acme'))->toBe(['read', 'write', 'tool:rename-widget', 'tenant:acme']);

    $plain = AgentTokens::mint($user, str_repeat('Claude Code on my laptop ', 4), ['read', 'write'], ['rename-widget'], '30', 'acme');
    $token = $user->tokens()->sole();

    expect($plain)->toContain('|')
        ->and(strlen($token->name))->toBe(60)
        ->and($token->abilities)->toBe(['read', 'write', 'tool:rename-widget', 'tenant:acme'])
        ->and($token->expires_at?->isSameDay(now()->addDays(30)))->toBeTrue();

    // A role without the write ability sees no write tool to scope to.
    Abilities::$allowed = ['widgets.view'];
    expect(AgentTokens::availableTools())->not->toHaveKey('rename-widget')
        ->and(AgentTokens::toolTitles())->toHaveKey('rename-widget')
        ->and(AgentTokens::abilities(['read', 'write'], ['rename-widget']))->toBe(['read', 'write']);
});

it('labels a turn status', function () {
    expect(AgentTurn::statusLabel(AgentTurn::QUEUED))->toBe(__('Queued'))
        ->and(AgentTurn::statusLabel(AgentTurn::FAILED))->toBe(__('Failed'))
        ->and(AgentTurn::statusLabel('other'))->toBe('other');
});
