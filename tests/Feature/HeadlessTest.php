<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Mcp\Tools\DrawChart;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Support\AgentRuntime;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Support\Context\LaravelContext;
use Packstub\Agents\Support\Installed;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\Fixtures\Tools\RetireWidget;
use Packstub\Agents\Tests\Fixtures\Tools\WhoAmI;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use Packstub\Agents\Tests\TestCase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

// What a plain app's service provider does: the agent, how an ability is checked.
beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
});

it('boots without Filament, on the Laravel context', function () {
    expect($this)->toBeInstanceOf(TestCase::class)
        ->and(Installed::filament())->toBeFalse()
        ->and(Agents::context())->toBeInstanceOf(LaravelContext::class)
        ->and(Agents::inPanel())->toBeFalse()
        ->and(Agents::tenant())->toBeNull()
        ->and(Agents::resourceClasses())->toBe([])
        ->and(config('packstub-agents.panel'))->toBeNull();

    $user = $this->user();
    actingAs($user);

    expect(AgentRuntime::capture())->toBe(['panel' => null, 'tenant' => null, 'user' => $user->id, 'locale' => 'en', 'guard' => 'web']);
});

it('serves MCP with draw-chart as the only default tool, then the registered tools by token', function () {
    $user = $this->user();
    $this->widgets();
    $mcp = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
    $headers = fn (string $token) => ['Authorization' => 'Bearer '.$token] + $mcp;
    $list = fn (string $token) => postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $headers($token))->assertOk()->json('result.tools.*.name');
    $call = fn (string $tool, array $args, string $token) => postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]], $headers($token));

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 0, 'method' => 'tools/list'], $mcp)->assertStatus(401);

    // Nothing registered: the base server serves the generic tool alone — no show-table without a panel to embed a table in.
    expect(Agents::toolClasses())->toBe([DrawChart::class]);
    $read = $user->createToken('laptop', ['read'])->plainTextToken;
    expect($list($read))->toBe(['draw-chart']);

    // The app's own list, as a service provider registers it.
    Agents::useTools([WhoAmI::class, RetireWidget::class]);
    auth()->forgetGuards();
    expect($list($read))->toBe(['who-am-i']);

    // A tool runs as the token's user, on the guard the request authenticated on, in their locale.
    $user->forceFill(['locale' => 'de'])->save();
    auth()->forgetGuards();
    $seen = json_decode($call('who-am-i', [], $read)->assertOk()->assertJsonPath('result.isError', false)->json('result.content.0.text'), true);
    expect($seen)->toBe(['user' => $user->id, 'guard' => 'sanctum', 'tenant' => null, 'locale' => 'de']);

    auth()->forgetGuards();
    $call('retire-widget', ['id' => 1], $read)->assertOk()->assertJsonPath('error.message', 'Tool [retire-widget] not found.');

    // A scoped write token: the tools it names, and only those.
    auth()->forgetGuards();
    $scoped = $user->createToken('queue', ['read', 'write', 'tool:retire-widget'])->plainTextToken;
    expect($list($scoped))->toBe(['retire-widget']);
    auth()->forgetGuards();
    $call('retire-widget', ['id' => 1], $scoped)->assertOk()->assertJsonPath('result.isError', false);
    expect(Widget::query()->find(1)->status)->toBe('retired');
    auth()->forgetGuards();
    $call('who-am-i', [], $scoped)->assertOk()->assertJsonPath('error.message', 'Tool [who-am-i] not found.');
});

it('runs a queued turn as the person who asked, on the default guard, in their locale, and cleans up after', function () {
    $user = $this->user();
    actingAs($user);
    app()->setLocale('de');
    Queue::fake();

    $seen = null;
    Agents::useMiddleware([function (AgentPrompt $prompt, Closure $next) use (&$seen) {
        $seen = [auth()->id(), auth()->getDefaultDriver(), app()->getLocale(), Agents::tenant()];

        return $next($prompt);
    }]);

    $conversation = app(AgentConversationStore::class)->startConversation($user, 'How many widgets are live?');
    $turn = app(AgentTurns::class)->enqueue($conversation, $user, ['prompt' => 'How many widgets are live?'], null, 'auto', null);

    $job = Queue::pushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $turn->id)->first();
    expect($turn->panel)->toBeNull()
        ->and($turn->guard)->toBe('web')
        ->and($job->runtime)->toBe(['panel' => null, 'guard' => 'web', 'tenant' => null, 'user' => $user->id, 'locale' => 'de']);

    // The worker knows nothing of the request.
    auth()->logout();
    app()->setLocale('en');
    expect(auth()->user())->toBeNull();

    WidgetAgent::fake(['Two widgets are live.']);
    $job->handle(app(AgentTurns::class));

    expect($turn->fresh()->status)->toBe(AgentTurn::DONE)
        ->and($seen)->toBe([$user->id, 'web', 'de', null])
        ->and(auth()->user())->toBeNull()
        ->and(app()->getLocale())->toBe('en');
});

it('runs a queued turn on the guard it was asked on, not the default one', function () {
    config()->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'users']);
    $user = $this->user();
    Auth::guard('staff')->setUser($user);
    Auth::shouldUse('staff');
    Queue::fake();

    $seen = null;
    Agents::useMiddleware([function (AgentPrompt $prompt, Closure $next) use (&$seen) {
        $seen = [auth()->id(), auth()->getDefaultDriver(), Auth::guard('web')->user()];

        return $next($prompt);
    }]);

    $conversation = app(AgentConversationStore::class)->startConversation($user, 'Who am I?');
    $turn = app(AgentTurns::class)->enqueue($conversation, $user, ['prompt' => 'Who am I?'], null, 'auto', null);
    $job = Queue::pushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $turn->id)->first();
    expect($turn->guard)->toBe('staff')
        ->and($job->runtime['guard'])->toBe('staff');

    // The worker starts on the default guard, with nobody signed in anywhere.
    Auth::guard('staff')->forgetUser();
    Auth::shouldUse('web');
    auth()->forgetGuards();
    expect(auth()->user())->toBeNull();

    // Whoever reads the turn outside a request finds the person through the turn's guard.
    expect(app(AgentTurns::class)->participant($turn)?->is($user))->toBeTrue();

    WidgetAgent::fake(['You are Ada.']);
    $job->handle(app(AgentTurns::class));

    expect($turn->fresh()->status)->toBe(AgentTurn::DONE)
        ->and($seen)->toBe([$user->id, 'staff', null])
        ->and(auth()->getDefaultDriver())->toBe('web')
        ->and(Auth::guard('staff')->user())->toBeNull();
});

it('enforces the budget and the operator rows in a single workspace', function () {
    $user = $this->user();
    actingAs($user);

    expect(AgentModels::enabled())->toBeTrue()->and(AgentBudget::refusal('Hi'))->toBeNull();

    // The operator's global row, and the burst limit keyed by "central" and the person.
    AgentLimit::query()->create(['scope' => 'global', 'turns_per_minute' => 1, 'prompt_max_chars' => 5]);
    AgentLimits::flush();

    expect(AgentBudget::refusal('far too long'))->toBe(__('That question is too long (max :n characters).', ['n' => 5]));
    AgentBudget::hit();
    expect(AgentBudget::refusal('Hi'))->toBe(__('Too many questions in a row — give it a minute.'));

    AgentLimit::query()->create(['scope' => 'user', 'scope_id' => $user->id, 'enabled' => false]);
    AgentLimits::flush();
    expect(AgentLimits::effective()['enabled'])->toBeFalse()
        ->and(AgentBudget::refusal('Hi'))->toBe(__(':name is switched off for this workspace.', ['name' => 'Ask Widgets']));
});

it('registers the poll endpoint under chat.path and chat.middleware for the conversation\'s own participant', function () {
    $user = $this->user();
    $other = $this->user();
    $conversation = app(AgentConversationStore::class)->startConversation($user, 'Hi');

    expect(Route::has('packstub-agents.turn'))->toBeTrue()
        ->and(route('packstub-agents.turn', ['conversation' => $conversation]))->toBe(url('/agents/chat/'.$conversation.'/turn'));

    $url = route('packstub-agents.turn', ['conversation' => $conversation]);

    getJson($url)->assertStatus(401);

    actingAs($other);
    getJson($url)->assertNotFound();

    actingAs($user);
    getJson($url)->assertOk()->assertJsonPath('active', null)->assertJsonStructure(['active', 'version']);
});

it('names a missing worker on the status line once a turn has waited for one', function () {
    $user = $this->user();
    actingAs($user);
    Queue::fake();

    $conversation = app(AgentConversationStore::class)->startConversation($user, 'Hi');
    $turn = app(AgentTurns::class)->enqueue($conversation, $user, ['prompt' => 'Hi'], null, 'auto', null);
    $url = route('packstub-agents.turn', ['conversation' => $conversation]);
    $hint = __('No queue worker has taken this turn yet. Run php artisan queue:work, or set AGENT_TURN_DRIVER=sync to answer inside the request.');

    // Handed to the queue, nobody has taken it: "Thinking…" while the wait is short, the hint once it is not.
    expect($turn->status)->toBe(AgentTurn::PENDING);
    getJson($url)->assertOk()->assertJsonPath('active.statusText', __('Thinking…'));

    $this->travel(AgentTurns::workerWait() + 1)->seconds();
    getJson($url)->assertOk()->assertJsonPath('active.id', $turn->id)->assertJsonPath('active.statusText', $hint);

    // A job that reports progress is running: what it says, not the hint. The sync driver never waits for a worker.
    app(AgentTurns::class)->claim($turn);
    app(AgentTurns::class)->snapshot($turn, null, 'Reading widgets…');
    getJson($url)->assertOk()->assertJsonPath('active.statusText', 'Reading widgets…');

    AgentTurn::query()->whereKey($turn->id)->update(['status' => AgentTurn::PENDING, 'status_text' => null, 'updated_at' => now()->subMinute()]);
    expect(app(AgentTurns::class)->awaitingWorker($turn->fresh()))->toBeTrue();
    config(['packstub-agents.chat.driver' => 'sync']);
    expect(app(AgentTurns::class)->awaitingWorker($turn->fresh()))->toBeFalse()
        ->and(app(AgentTurns::class)->statusText($turn->fresh()))->toBe(__('Thinking…'));
});

it('scaffolds the agent with a hint for a service provider, not a panel', function () {
    $path = app_path('Ai/Agents/Assistant.php');
    File::delete($path);

    try {
        $this->artisan('packstub-agents:agent')
            ->expectsOutputToContain('Agents::useAgent(\App\Ai\Agents\Assistant::class)')
            ->assertSuccessful();

        expect(File::get($path))->toContain('class Assistant extends Agent');
    } finally {
        File::delete($path);
    }
});

// Two proposals in one answer, the way a model that confirms two orders at once pauses: what a typed reply and
// one-at-a-time decisions do to them.
function pausedAnswer(object $user, string $conversation): ConversationMessage
{
    return ConversationMessage::query()->create([
        'id' => (string) Str::uuid7(), 'conversation_id' => $conversation, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => '', 'attachments' => [], 'usage' => [], 'meta' => [],
        'tool_calls' => [
            ['id' => 'c1', 'name' => 'retire-widget', 'arguments' => ['id' => 1], 'result_id' => 'call_1'],
            ['id' => 'c2', 'name' => 'retire-widget', 'arguments' => ['id' => 2], 'result_id' => 'call_2'],
        ],
        'tool_results' => [], 'approval_state' => ['pending' => ['c1' => 'Retire widget Alpha?', 'c2' => 'Retire widget Beta?']],
    ]);
}

it('takes a typed "Yes, go ahead." over pending proposals as their approval, and "no" as their rejection', function () {
    $user = $this->user();
    actingAs($user);
    Queue::fake();
    $store = app(AgentConversationStore::class);
    $turns = app(AgentTurns::class);

    $conversation = $store->startConversation($user, 'Retire Alpha and Beta');
    $paused = pausedAnswer($user, $conversation);
    expect(array_keys($store->pendingCalls($conversation, $user)))->toBe(['c1', 'c2']);

    // The reply is recorded like a question and the turn runs as the decision on both proposals.
    $turn = $turns->enqueue($conversation, $user, ['prompt' => 'Yes, go ahead.'], null, 'auto', null);
    expect($turn->status)->toBe(AgentTurn::PENDING)
        ->and($turn->prompt())->toBeNull()
        ->and($turn->decisions())->toBe(['c1' => true, 'c2' => true])
        ->and($turn->input['said'])->toBe('Yes, go ahead.')
        ->and(ConversationMessage::query()->find($turn->message_id)?->content)->toBe('Yes, go ahead.')
        ->and($paused->fresh()->tool_results)->toBe([]);
    Queue::assertPushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $turn->id);

    $conversation = $store->startConversation($user, 'Retire Alpha and Beta');
    pausedAnswer($user, $conversation);
    $turn = $turns->enqueue($conversation, $user, ['prompt' => 'No, leave them.'], null, 'auto', null);
    expect($turn->decisions())->toBe(['c1' => false, 'c2' => false]);

    foreach (['Yes, go ahead.' => true, 'yes please' => true, 'Go ahead!' => true, 'Ok' => true, 'Sure, confirm it.' => true, 'Da, te rog.' => true, 'Ja, mach das.' => true, 'Sí' => true, 'да' => true,
        'No' => false, 'no thanks' => false, 'Cancel' => false, 'Nein, lieber nicht.' => false, 'Nu acum' => false, 'нет' => false,
        'What about Beta?' => null, 'Show me the orders first' => null, 'yes and also retire Gamma and Delta and Epsilon please' => null, '' => null] as $text => $decision) {
        expect(AgentTurns::decisionInText($text))->toBe($decision, $text);
    }
});

it('declines the pending proposals with a note when the person asks something else instead', function () {
    $user = $this->user();
    actingAs($user);
    Queue::fake();
    $store = app(AgentConversationStore::class);

    $conversation = $store->startConversation($user, 'Retire Alpha and Beta');
    $paused = pausedAnswer($user, $conversation);

    $turn = app(AgentTurns::class)->enqueue($conversation, $user, ['prompt' => 'What about Gamma?'], null, 'auto', null);

    $paused->refresh();
    expect($turn->prompt())->toBe('What about Gamma?')
        ->and($turn->status)->toBe(AgentTurn::PENDING)
        ->and(collect($paused->tool_results)->pluck('denied', 'id')->all())->toBe(['c1' => true, 'c2' => true])
        ->and($paused->tool_results[0]['result'])->toBe(AgentTurns::supersededResult())
        ->and($paused->approval_state['pending'])->toBe([])
        ->and($store->pendingCalls($conversation, $user))->toBe([])
        ->and($store->declinePending($conversation, $user, 'again'))->toBe(0);
});

it('holds a decision on one of two proposals until the other is decided, then runs both together', function () {
    $user = $this->user();
    actingAs($user);
    Queue::fake();
    $store = app(AgentConversationStore::class);
    $turns = app(AgentTurns::class);

    $conversation = $store->startConversation($user, 'Retire Alpha and Beta');
    pausedAnswer($user, $conversation);

    // Approve on Alpha: nothing runs yet, laravel/ai applies the decisions of one pause together.
    $first = $turns->enqueue($conversation, $user, ['decisions' => ['c1' => true]], null, 'auto', null);
    expect($first->status)->toBe(AgentTurn::QUEUED)
        ->and($turns->active($conversation))->toBeNull()
        ->and($turns->queued($conversation)->pluck('id')->all())->toBe([$first->id]);
    Queue::assertNothingPushed();

    // Reject on Beta joins the waiting turn, which now starts with both.
    $second = $turns->enqueue($conversation, $user, ['decisions' => ['c2' => false]], null, 'auto', null);
    expect($second->id)->toBe($first->id)
        ->and($second->status)->toBe(AgentTurn::PENDING)
        ->and($second->decisions())->toBe(['c1' => true, 'c2' => false])
        ->and(AgentTurn::query()->forConversation($conversation)->count())->toBe(1);
    Queue::assertPushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $first->id);
});
