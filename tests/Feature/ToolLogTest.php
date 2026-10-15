<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request as AiRequest;
use Laravel\Mcp\Request;
use Packstub\Agents\Ai\ApprovableTool;
use Packstub\Agents\Ai\TurnTool;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentToolCall;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentToolLog;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\Fixtures\Tools\ListWidgets;
use Packstub\Agents\Tests\Fixtures\Tools\RenameWidget;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use Packstub\Agents\Tests\Fixtures\WidgetResource;
use Packstub\Agents\Tests\Fixtures\WidgetServer;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

// The tool log (config `tool_log`): one agent_tool_calls row per call, from an agent turn and from MCP alike.
beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::useServer(WidgetServer::class);
    Agents::useResources([WidgetResource::class]);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
    Agents::roleLabelUsing(fn () => Abilities::$role);
});

/** A conversation whose last answer proposes renaming Alpha (call c1), paused for the decision, and the turn that proposed it. */
function proposal(object $user, Widget $alpha): array
{
    $store = app(AgentConversationStore::class);
    $conversation = $store->startConversation($user, 'Rename Alpha');
    $store->storeQuestion($conversation, $user, WidgetAgent::class, 'Rename Alpha to Alpha II.');
    ConversationMessage::query()->create([
        'id' => (string) Str::uuid7(), 'conversation_id' => $conversation, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => 'Shall I?', 'attachments' => [], 'usage' => [], 'meta' => [],
        'steps' => [['content' => 'Shall I?', 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => [], 'tool_calls' => [
            ['id' => 'c1', 'name' => 'rename-widget', 'arguments' => ['id' => $alpha->id, 'name' => 'Alpha II'], 'result_id' => null, 'approval_reason' => 'Rename?'],
        ]]],
        'status' => MessageStatus::Paused,
    ]);

    $turn = AgentTurn::query()->create([
        'id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'status' => AgentTurn::DONE, 'input' => ['prompt' => 'Rename Alpha to Alpha II.'],
    ]);

    return [$conversation, $turn];
}

it('records a chat write from the model\'s call through the person\'s approval to the result, on one row', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    Abilities::$allowed = ['widgets.view', 'widgets.manage'];
    [$conversation, $proposing] = proposal($user, $alpha);
    $log = app(AgentToolLog::class);
    $arguments = ['id' => $alpha->id, 'name' => 'Alpha II'];

    // What the stream says while the turn runs: the model's call, then the proposal it waits under.
    $log->called($proposing, new ToolCall('c1', 'rename-widget', $arguments));
    $log->proposed($proposing, new PendingApproval('c1', 'rename-widget', $arguments, "Rename widget #{$alpha->id} to Alpha II?"));

    $row = AgentToolCall::query()->sole();
    expect($row)->toMatchArray([
        'call_id' => 'c1', 'channel' => 'agent', 'turn_id' => $proposing->id, 'conversation_id' => $conversation,
        'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'tool' => 'rename-widget', 'arguments' => $arguments,
        'status' => AgentToolCall::PROPOSED, 'question' => "Rename widget #{$alpha->id} to Alpha II?", 'decision' => null,
    ]);

    // The click: the decision turn records it (under a fake, laravel/ai does not run the approved call).
    WidgetAgent::fake(['Done.']);
    $decision = AgentChat::for($user, $conversation)->decide('c1', true);
    expect($decision->status)->toBe(AgentTurn::DONE)
        ->and($row->fresh())->toMatchArray(['status' => AgentToolCall::APPROVED, 'decision' => 'approved', 'decided_by' => null, 'finished_at' => null])
        ->and($row->fresh()->decided_at)->not->toBeNull();

    // The approved call runs through the bridge under its provider id, inside the decision turn, and ends the row.
    $log->during($decision);
    $answer = (new ApprovableTool(app(RenameWidget::class)))->handle(new AiRequest($arguments, 'c1'));
    $log->during(null);

    $row = $row->fresh();
    expect(json_decode($answer, true)['renamed'])->toBeTrue()
        ->and(AgentToolCall::query()->count())->toBe(1)
        ->and($row)->toMatchArray(['status' => AgentToolCall::SUCCEEDED, 'ability' => 'widgets.manage', 'token_id' => null, 'error' => null, 'refused_by' => null])
        ->and($row->result)->toBe(json_decode($answer, true))
        ->and($row->duration_ms)->toBeInt()
        ->and($row->started_at)->not->toBeNull()
        ->and($row->finished_at)->not->toBeNull()
        ->and($row->turn->id)->toBe($proposing->id)
        ->and($row->participant->is($user))->toBeTrue();
});

it('records a rejected proposal as rejected and finished, without it ever running', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    [$conversation, $proposing] = proposal($user, $alpha);
    app(AgentToolLog::class)->proposed($proposing, new PendingApproval('c1', 'rename-widget', ['id' => $alpha->id, 'name' => 'Alpha II'], 'Rename?'));

    WidgetAgent::fake(['Left as it is.']);
    AgentChat::for($user, $conversation)->decide('c1', false);

    $row = AgentToolCall::query()->sole();
    expect($row)->toMatchArray(['status' => AgentToolCall::REJECTED, 'decision' => 'rejected', 'result' => null])
        ->and($row->finished_at)->not->toBeNull()
        ->and($alpha->fresh()->name)->toBe('Alpha');

    // A finished row is never written again: the same id run later starts a row of its own.
    Abilities::$allowed = ['widgets.manage'];
    app(AgentToolLog::class)->during(AgentTurn::query()->where('conversation_id', $conversation)->latest('created_at')->first());
    (new ApprovableTool(app(RenameWidget::class)))->handle(new AiRequest(['id' => $alpha->id, 'name' => 'Alpha III'], 'c1'));

    expect($row->fresh()->status)->toBe(AgentToolCall::REJECTED)
        ->and(AgentToolCall::query()->count())->toBe(2)
        ->and(AgentToolCall::query()->latest('id')->first()->status)->toBe(AgentToolCall::SUCCEEDED);
});

it('records a decision on a proposal it never saw proposed, from the call the conversation kept', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    [$conversation] = proposal($user, $alpha);

    WidgetAgent::fake(['Done.']);
    AgentChat::for($user, $conversation)->decide('c1', true);

    expect(AgentToolCall::query()->sole())->toMatchArray([
        'call_id' => 'c1', 'channel' => 'agent', 'conversation_id' => $conversation, 'tool' => 'rename-widget',
        'arguments' => ['id' => $alpha->id, 'name' => 'Alpha II'], 'status' => AgentToolCall::APPROVED, 'decision' => 'approved',
    ]);
});

it('records a read the model ran in a turn as succeeded, with what the model read', function () {
    $user = $this->user();
    actingAs($user);
    $this->widgets();
    Abilities::$allowed = ['widgets.view'];
    [, $turn] = proposal($user, Widget::query()->first());
    $log = app(AgentToolLog::class);

    $log->during($turn);
    $log->called($turn, new ToolCall('r1', 'list-widgets', ['limit' => 1]));
    $answer = (new TurnTool(app(ListWidgets::class)))->handle(new AiRequest(['limit' => 1], 'r1'));
    $log->during(null);

    expect(AgentToolCall::query()->sole())->toMatchArray([
        'call_id' => 'r1', 'channel' => 'agent', 'turn_id' => $turn->id, 'tool' => 'list-widgets', 'ability' => 'widgets.view',
        'arguments' => ['limit' => 1], 'status' => AgentToolCall::SUCCEEDED, 'result' => json_decode($answer, true),
    ]);
});

it('records MCP calls with the access token, and keeps them when the conversation is deleted', function () {
    $user = $this->user();
    [$alpha] = $this->widgets();
    Agents::useTools([ListWidgets::class, RenameWidget::class]);
    Abilities::$allowed = ['widgets.view', 'widgets.manage'];
    $token = $user->createToken('laptop', ['read', 'write']);
    $headers = ['Authorization' => 'Bearer '.$token->plainTextToken, 'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'rename-widget', 'arguments' => ['id' => $alpha->id, 'name' => 'Alpha MCP']]], $headers)
        ->assertOk()->assertJsonPath('result.isError', false);
    auth()->forgetGuards();
    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'rename-widget', 'arguments' => ['id' => 999, 'name' => 'Nobody']]], $headers)
        ->assertOk()->assertJsonPath('result.isError', true);

    [$renamed, $missing] = AgentToolCall::query()->orderBy('id')->get();
    expect($renamed)->toMatchArray([
        'channel' => 'mcp', 'turn_id' => null, 'conversation_id' => null, 'participant_id' => $user->id, 'token_id' => $token->accessToken->id,
        'tool' => 'rename-widget', 'ability' => 'widgets.manage', 'arguments' => ['id' => $alpha->id, 'name' => 'Alpha MCP'], 'status' => AgentToolCall::SUCCEEDED,
    ])
        ->and($renamed->call_id)->toBeString()->not->toBeEmpty()
        ->and($renamed->result['renamed'])->toBeTrue()
        ->and($missing)->toMatchArray(['channel' => 'mcp', 'status' => AgentToolCall::FAILED, 'error' => 'No widget matches 999.', 'result' => null]);

    // A chat's calls outlive the chat.
    actingAs($user);
    [$conversation, $turn] = proposal($user, $alpha);
    app(AgentToolLog::class)->called($turn, new ToolCall('c9', 'list-widgets', []));
    app(AgentConversationStore::class)->deleteConversation($conversation);
    expect(AgentToolCall::query()->where('conversation_id', $conversation)->count())->toBe(1);
});

it('records a refusal by the role and by the token with the message the model read', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    Abilities::$allowed = ['widgets.view'];
    Abilities::$role = 'Viewer';

    app(RenameWidget::class)->handle(new Request(['id' => $alpha->id, 'name' => 'X']));

    Abilities::$allowed = ['widgets.view', 'widgets.manage'];
    $token = $user->createToken('laptop', ['read']);
    $user->withAccessToken($token->accessToken);
    app(RenameWidget::class)->handle(new Request(['id' => $alpha->id, 'name' => 'Y']));

    [$role, $read] = AgentToolCall::query()->orderBy('id')->get();
    expect($role)->toMatchArray(['channel' => 'direct', 'status' => AgentToolCall::REFUSED, 'refused_by' => 'role', 'token_id' => null, 'duration_ms' => null])
        ->and($role->error)->toContain('Your role (Viewer)')
        ->and($read)->toMatchArray(['status' => AgentToolCall::REFUSED, 'refused_by' => 'token', 'error' => 'This access token is read-only.', 'token_id' => $token->accessToken->id])
        ->and($alpha->fresh()->name)->toBe('Alpha');
});

it('redacts, caps or leaves out what it stores, as configured, and stores nothing when switched off', function () {
    $user = $this->user();
    actingAs($user);
    $this->widgets();
    Abilities::$allowed = ['widgets.view', 'widgets.manage'];
    config(['packstub-agents.redact.enabled' => true]);

    // Redacted: the card in the arguments and in the result.
    $alpha = Widget::query()->create(['name' => 'Card 4242 4242 4242 4242', 'status' => 'live', 'price' => 5]);
    app(RenameWidget::class)->handle(new Request(['id' => $alpha->id, 'name' => 'Paid with 4111 1111 1111 1111']));
    $row = AgentToolCall::query()->latest('id')->first();
    expect($row->arguments['name'])->toBe('Paid with [redacted]')
        ->and(json_encode($row->result))->not->toContain('4111')->toContain('[redacted]');

    // Capped: the first bytes of a result larger than max_bytes, marked as cut.
    config(['packstub-agents.tool_log.max_bytes' => 40]);
    app(ListWidgets::class)->handle(new Request([]));
    $row = AgentToolCall::query()->latest('id')->first();
    expect($row->result)->toMatchArray(['truncated' => true])
        ->and($row->result['bytes'])->toBeGreaterThan(40)
        ->and(strlen($row->result['excerpt']))->toBeLessThanOrEqual(40);

    // Without results: the outcome alone.
    config(['packstub-agents.tool_log.max_bytes' => 65536, 'packstub-agents.tool_log.results' => false]);
    app(ListWidgets::class)->handle(new Request([]));
    expect(AgentToolCall::query()->latest('id')->first())->toMatchArray(['status' => AgentToolCall::SUCCEEDED, 'result' => null]);

    // Off: nothing.
    config(['packstub-agents.tool_log.enabled' => false]);
    $count = AgentToolCall::query()->count();
    app(ListWidgets::class)->handle(new Request([]));
    expect(AgentToolCall::query()->count())->toBe($count);
});

it('never fails a call because the log could not be written, and reports why', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    Abilities::$allowed = ['widgets.manage'];
    Exceptions::fake();
    // A table that is not there (an app that did not migrate yet): a real database error, inside the test's transaction.
    AgentToolCall::creating(fn (AgentToolCall $row) => $row->setTable('agent_tool_calls_missing'));

    $response = app(RenameWidget::class)->handle(new Request(['id' => $alpha->id, 'name' => 'Alpha II']));

    expect($response->isError())->toBeFalse()
        ->and($alpha->fresh()->name)->toBe('Alpha II');
    Exceptions::assertReported(fn (QueryException $e) => str_contains($e->getMessage(), 'agent_tool_calls_missing'));
});

it('prunes rows older than keep_days, and keeps them all without it', function () {
    $user = $this->user();
    actingAs($user);
    $this->widgets();
    Abilities::$allowed = ['widgets.view'];

    app(ListWidgets::class)->handle(new Request([]));
    app(ListWidgets::class)->handle(new Request([]));
    AgentToolCall::query()->orderBy('id')->first()->forceFill(['created_at' => now()->subDays(91)])->save();

    config(['packstub-agents.tool_log.keep_days' => null]);
    $this->artisan('model:prune', ['--model' => [AgentToolCall::class]])->assertSuccessful();
    expect(AgentToolCall::query()->count())->toBe(2);

    config(['packstub-agents.tool_log.keep_days' => 90]);
    $this->artisan('model:prune', ['--model' => [AgentToolCall::class]])->assertSuccessful();
    expect(AgentToolCall::query()->count())->toBe(1);
});

it('records what a turn streams: the read it ran, the write it proposed, then the approval', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    Abilities::$allowed = ['widgets.view', 'widgets.manage'];
    $store = app(AgentConversationStore::class);
    $conversation = $store->startConversation($user, 'Rename Alpha');

    WidgetAgent::fake([
        new ToolCall('r1', 'list-widgets', ['limit' => 1]),
        new ToolCall('w1', 'rename-widget', ['id' => $alpha->id, 'name' => 'Alpha II']),
    ]);
    $asked = AgentChat::for($user, $conversation)->send('Rename Alpha to Alpha II.');

    [$read, $write] = AgentToolCall::query()->orderBy('id')->get();
    expect($read)->toMatchArray(['call_id' => 'r1', 'channel' => 'agent', 'turn_id' => $asked->id, 'tool' => 'list-widgets', 'status' => AgentToolCall::SUCCEEDED])
        ->and($read->result['total'])->toBe(2)
        ->and($write)->toMatchArray(['call_id' => 'w1', 'turn_id' => $asked->id, 'status' => AgentToolCall::PROPOSED, 'question' => "Rename widget #{$alpha->id} to Alpha II?"]);

    WidgetAgent::fake(['Renamed.']);
    AgentChat::for($user, $conversation)->decide('w1', true);

    // Under a fake laravel/ai does not run the approved call; the bridge's part is the first test's.
    expect($write->fresh())->toMatchArray(['status' => AgentToolCall::APPROVED, 'decision' => 'approved', 'turn_id' => $asked->id])
        ->and(AgentToolCall::query()->count())->toBe(2);
});
