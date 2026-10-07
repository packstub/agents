<?php

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Mcp\Request;
use Packstub\Agents\Channels\Email\EmailChannel;
use Packstub\Agents\Channels\Email\InboundEmail;
use Packstub\Agents\Events\ToolAuthorized;
use Packstub\Agents\Exceptions\WorkspaceAccessDenied;
use Packstub\Agents\Exceptions\WorkspaceNotFound;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Support\AgentRun;
use Packstub\Agents\Support\AgentRuntime;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Models\Article;
use Packstub\Agents\Tests\Fixtures\Models\Team;
use Packstub\Agents\Tests\Fixtures\Tools\RetireWidget;
use Packstub\Agents\Tests\Fixtures\Tools\WhoAmI;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\postJson;

// A multi-workspace app without a panel: the MCP path carries the workspace.
defineEnvironment(fn ($app) => $app['config']->set('packstub-agents.mcp.path', 'mcp/{tenant}'));

beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::useTools([WhoAmI::class, RetireWidget::class]);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
    Agents::tenantModel(Team::class, 'slug');
});

it('resolves the workspace on the MCP path by slug, checks membership and holds the token to it', function () {
    $owner = $this->user(['locale' => 'ro']);
    $team = $this->team($owner, 'acme');
    $this->team($this->user(), 'globex');
    $mcp = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
    $headers = fn (string $token) => ['Authorization' => 'Bearer '.$token] + $mcp;
    $whoAmI = fn (string $slug, string $token) => postJson('/mcp/'.$slug, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'who-am-i', 'arguments' => []]], $headers($token));

    expect(Agents::context()->tenantModel())->toBe(Team::class)
        ->and(Agents::context()->findTenantBySlug('acme')?->is($team))->toBeTrue()
        ->and(Agents::context()->findTenant($team->id)?->is($team))->toBeTrue()
        ->and(AgentLimit::tenantModel())->toBe(Team::class)
        ->and(AgentLimit::tenantName($team->id))->toBe('Acme');

    // The workspace is entered for the call: the tool sees it, the person and their locale.
    $token = $owner->createToken('laptop', ['read', 'tenant:acme'])->plainTextToken;
    $seen = json_decode($whoAmI('acme', $token)->assertOk()->assertJsonPath('result.isError', false)->json('result.content.0.text'), true);
    expect($seen)->toBe(['user' => $owner->id, 'guard' => 'sanctum', 'tenant' => 'acme', 'locale' => 'ro']);

    // Not a member there, and no such workspace at all.
    auth()->forgetGuards();
    $whoAmI('globex', $token)->assertNotFound();
    auth()->forgetGuards();
    $whoAmI('nowhere', $token)->assertNotFound();

    // A member's token minted for another workspace is refused on this one.
    auth()->forgetGuards();
    $foreign = $owner->createToken('desk', ['read', 'tenant:globex'])->plainTextToken;
    $whoAmI('acme', $foreign)->assertForbidden();
});

it('captures the workspace tenantUsing() resolves and restores it in the worker, through the enteringTenant() hook and its undo', function () {
    $owner = $this->user();
    $team = $this->team($owner, 'acme');
    $entered = [];
    Agents::tenantUsing(fn () => $team);
    Agents::enteringTenant(function (Model $tenant) use (&$entered): Closure {
        $entered[] = 'enter '.$tenant->slug;

        return function () use (&$entered, $tenant): void {
            $entered[] = 'leave '.$tenant->slug;
        };
    });
    actingAs($owner);
    Queue::fake();

    expect(Agents::tenant()?->is($team))->toBeTrue()
        ->and(AgentRuntime::capture())->toBe(['panel' => null, 'tenant' => $team->id, 'user' => $owner->id, 'locale' => 'en', 'guard' => 'web']);

    $seen = null;
    Agents::useMiddleware([function (PendingStep $step, Closure $next) use (&$seen) {
        $seen = [auth()->id(), Agents::tenant()?->slug];

        return $next($step);
    }]);

    $conversation = app(AgentConversationStore::class)->startConversation($owner, 'How many widgets are live?');
    $turn = app(AgentTurns::class)->enqueue($conversation, $owner, ['prompt' => 'How many widgets are live?'], null, 'auto', null);
    expect($turn->tenant)->toBe((string) $team->id);

    // The worker has no request: the resolver knows nothing, the turn's snapshot does.
    Agents::tenantUsing(fn () => null);
    auth()->logout();
    expect(Agents::tenant())->toBeNull();

    WidgetAgent::fake(['Two widgets are live.']);
    Queue::pushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $turn->id)->first()->handle(app(AgentTurns::class));

    expect($turn->fresh()->status)->toBe(AgentTurn::DONE)
        ->and($seen)->toBe([$owner->id, 'acme'])
        ->and($entered)->toBe(['enter acme', 'leave acme'])
        ->and(Agents::tenant())->toBeNull()
        ->and(auth()->user())->toBeNull();
});

it('keys budgets and limits by the workspace tenantUsing() resolves', function () {
    $owner = $this->user();
    $acme = $this->team($owner, 'acme');
    $globex = $this->team($owner, 'globex');
    actingAs($owner);
    $current = $acme;
    Agents::tenantUsing(function () use (&$current) {
        return $current;
    });

    AgentLimit::query()->create(['scope' => 'tenant', 'scope_id' => $acme->id, 'enabled' => false]);
    AgentLimit::query()->create(['scope' => 'tenant', 'scope_id' => $globex->id, 'turns_per_minute' => 1]);

    expect(AgentLimits::effective()['enabled'])->toBeFalse()
        ->and(AgentBudget::refusal('Hi'))->toBe(__(':name is switched off for this workspace.', ['name' => 'Ask Widgets']))
        ->and(AgentModels::enabled())->toBeFalse();

    // The burst limit is counted per workspace and person.
    $current = $globex;
    expect(AgentLimits::effective()['enabled'])->toBeTrue()
        ->and(AgentModels::enabled())->toBeTrue()
        ->and(AgentBudget::refusal('Hi'))->toBeNull();
    AgentBudget::hit();
    expect(AgentBudget::refusal('Hi'))->toBe(__('Too many questions in a row — give it a minute.'));

    $current = $this->team($owner, 'initech');
    expect(AgentBudget::refusal('Hi'))->toBeNull();
});

it('refuses to enter a workspace the person is not a member of, on every path', function () {
    Mail::fake();
    config()->set('packstub-agents.email.enabled', true);
    config()->set('packstub-agents.email.secret', 'hook-secret');
    $owner = $this->user(['email' => 'ada@example.com']);
    $acme = $this->team($owner, 'acme');
    $globex = $this->team($this->user(), 'globex');
    $entered = [];
    Agents::enteringTenant(function (Model $tenant) use (&$entered): ?Closure {
        $entered[] = $tenant->slug;

        return null;
    });
    $ran = 0;
    Agents::useMiddleware([function (PendingStep $step, Closure $next) use (&$ran) {
        $ran++;

        return $next($step);
    }]);
    WidgetAgent::fake(['Two widgets are live.']);

    // AgentRun: the app named a workspace that is not the person's.
    expect(fn () => AgentRun::as($owner)->in($globex)->ask('How many widgets are live?'))
        ->toThrow(WorkspaceAccessDenied::class, 'You are not a member of this workspace.')
        ->and($entered)->toBe([])
        ->and($ran)->toBe(0)
        ->and(auth()->check())->toBeFalse() // nothing stays signed in
        ->and(Agents::tenant())->toBeNull();

    // A tenant named without a person: the one already signed in is the actor, and is checked the same way.
    actingAs($owner);
    expect(fn () => AgentRuntime::enter(['tenant' => $globex->getKey()]))
        ->toThrow(WorkspaceAccessDenied::class)
        ->and($entered)->toBe([])
        ->and(auth()->user()?->is($owner))->toBeTrue() // still signed in, as before the call
        ->and(Agents::tenant())->toBeNull();
    auth()->logout();

    // The email channel: the sender picked another workspace's address — dropped, no reply, the provider is not retried.
    expect(EmailChannel::receive(new InboundEmail(from: 'ada@example.com', subject: 'Widgets', text: 'How many?', messageId: '<m1@test>', tenant: 'globex')))->toBeNull()
        ->and(AgentTurn::query()->count())->toBe(0)
        ->and($entered)->toBe([]);
    postJson('/agents/email', ['from' => 'ada@example.com', 'subject' => 'Widgets', 'text' => 'How many?', 'tenant' => 'globex'], ['X-Agent-Secret' => 'hook-secret'])->assertOk()->assertJson(['answered' => false]);
    Mail::assertNothingSent();

    // The same person in their own workspace is answered.
    $answer = AgentRun::as($owner)->in($acme)->ask('How many widgets are live?');
    expect($answer->text)->toBe('Two widgets are live.')
        ->and($entered)->toBe(['acme', 'acme']) // the run, then the in-process job
        ->and($ran)->toBe(1);

    // The worker: membership revoked between the question and the turn — the turn fails with the line, nothing runs.
    Agents::tenantUsing(fn () => $acme);
    actingAs($owner);
    Queue::fake();
    $conversation = app(AgentConversationStore::class)->startConversation($owner, 'Still there?');
    $turn = app(AgentTurns::class)->enqueue($conversation, $owner, ['prompt' => 'Still there?'], null, 'auto', null);
    auth()->logout();
    Agents::tenantUsing(fn () => null);
    $acme->update(['owner_id' => $globex->owner_id]);

    Queue::pushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $turn->id)->first()->handle(app(AgentTurns::class));

    expect($turn->fresh()->status)->toBe(AgentTurn::FAILED)
        ->and($turn->fresh()->error)->toBe('You are not a member of this workspace.')
        ->and($entered)->toBe(['acme', 'acme'])
        ->and($ran)->toBe(1)
        ->and(auth()->user())->toBeNull();
});

it('refuses to enter a workspace with nobody acting, unless the caller says the system itself acts', function () {
    $owner = $this->user();
    $acme = $this->team($owner, 'acme');
    $globex = $this->team($this->user(), 'globex');
    $entered = [];
    Agents::enteringTenant(function (Model $tenant) use (&$entered): Closure {
        $entered[] = $tenant->slug;

        return function () use (&$entered): void {
            $entered[] = 'left';
        };
    });

    // A tenant with no user given and nobody signed in: nothing was checked, so nothing is entered.
    expect(fn () => AgentRuntime::enter(['tenant' => $acme->getKey()]))
        ->toThrow(WorkspaceAccessDenied::class, 'You are not a member of this workspace.')
        ->and($entered)->toBe([])
        ->and(auth()->check())->toBeFalse()
        ->and(Agents::tenant())->toBeNull();

    // No workspace at all stays as it was: an app without workspaces enters nothing and needs nobody.
    $leave = AgentRuntime::enter(['tenant' => null, 'locale' => 'de']);
    expect(app()->getLocale())->toBe('de')->and($entered)->toBe([]);
    $leave();
    expect(app()->getLocale())->toBe('en');

    // The app opts in for a job of its own: the system acts, the hook runs and is undone on leaving.
    $leave = AgentRuntime::enter(['tenant' => $acme->getKey(), 'system' => true]);
    expect(Agents::tenant()?->is($acme))->toBeTrue()
        ->and($entered)->toBe(['acme'])
        ->and(auth()->check())->toBeFalse();
    $leave();
    expect($entered)->toBe(['acme', 'left'])->and(Agents::tenant())->toBeNull();

    // system does not stand in for a membership check once someone acts: a non-member is still refused.
    expect(fn () => AgentRuntime::enter(['tenant' => $globex->getKey(), 'user' => $owner, 'system' => true]))
        ->toThrow(WorkspaceAccessDenied::class)
        ->and($entered)->toBe(['acme', 'left'])
        ->and(auth()->check())->toBeFalse();

    // The console embeds a workspace's knowledge base as the system: --tenant still enters it.
    config(['packstub-agents.knowledge_base' => ['model' => Article::class, 'content' => 'body', 'url' => 'link'] + config('packstub-agents.knowledge_base')]);
    Article::query()->create(['title' => 'Refund policy', 'body' => 'Paid within 14 days.']);
    Embeddings::fake(fn ($prompt) => array_map(fn () => [0.9, 0.8], $prompt->inputs));

    artisan('packstub-agents:embed', ['--tenant' => 'acme'])->expectsOutputToContain('Embedded 1 document.')->assertSuccessful();
    expect($entered)->toBe(['acme', 'left', 'acme', 'left'])->and(Agents::tenant())->toBeNull();
});

it('asks membership again on every tool call, so a turn under way stops running tools once the person was removed', function () {
    $owner = $this->user();
    $acme = $this->team($owner, 'acme');
    $other = $this->user();
    Event::fake([ToolAuthorized::class]);

    // Directly: a member's call runs, the same call after the removal is refused with the line, before run().
    $leave = AgentRuntime::enter(['tenant' => $acme->getKey(), 'user' => $owner->getKey()]);
    $whoAmI = app(WhoAmI::class);
    expect(json_decode((string) $whoAmI->handle(new Request([]))->content(), true)['tenant'])->toBe('acme');

    $acme->update(['owner_id' => $other->getKey()]);
    $refused = $whoAmI->handle(new Request([]));
    expect($refused->isError())->toBeTrue()
        ->and((string) $refused->content())->toBe('You are not a member of this workspace.');
    Event::assertDispatched(ToolAuthorized::class, fn (ToolAuthorized $e) => $e->tool instanceof WhoAmI && $e->allowed);
    Event::assertDispatched(ToolAuthorized::class, fn (ToolAuthorized $e) => $e->tool instanceof WhoAmI && ! $e->allowed
        && $e->refusedBy === 'workspace' && $e->refusal === 'You are not a member of this workspace.');
    $leave();
    $acme->update(['owner_id' => $owner->getKey()]);

    // In a turn: the first call runs, the person is removed between the steps, the second call is refused.
    Agents::tenantUsing(fn () => $acme);
    actingAs($owner);
    Agents::useMiddleware([function (PendingStep $step, Closure $next) use ($acme, $other) {
        if ($step->number === 1) {
            $acme->update(['owner_id' => $other->getKey()]);
        }

        return $next($step);
    }]);
    WidgetAgent::fake([new ToolCall('c1', 'who-am-i', []), new ToolCall('c2', 'who-am-i', []), 'Done.']);

    $chat = AgentChat::for($owner);
    $turn = $chat->send('Who am I?');
    $messages = AgentChat::for($owner, $chat->conversation())->messages();
    $tools = $messages[1]['tools'];

    expect($turn->status)->toBe(AgentTurn::DONE, (string) $turn->error)
        ->and($tools)->toHaveCount(2)
        ->and($tools[0])->toMatchArray(['tool' => 'who-am-i'])
        ->and(json_decode($tools[0]['result'], true)['tenant'])->toBe('acme')
        ->and($tools[1])->toMatchArray(['tool' => 'who-am-i'])
        ->and($tools[1]['result'])->toContain('You are not a member of this workspace.')
        ->and($tools[1]['result'])->not->toContain('acme');
});

it('asks nothing about membership without a workspace', function () {
    $owner = $this->user();
    actingAs($owner);
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    expect(Agents::tenant())->toBeNull()
        ->and(WhoAmI::membershipRefusal())->toBeNull()
        ->and($queries)->toBe(0);

    $leave = AgentRuntime::enter(['tenant' => null, 'user' => $owner->getKey()]);
    expect(json_decode((string) app(WhoAmI::class)->handle(new Request([]))->content(), true))->toMatchArray(['user' => $owner->id, 'tenant' => null]);
    $leave();
});

it('counts the daily and monthly budgets per workspace on a shared database, from the turns each one ended', function () {
    $owner = $this->user();
    $other = $this->user();
    $acme = $this->team($owner, 'acme');
    $globex = $this->team($owner, 'globex');
    actingAs($owner);
    $current = $acme;
    Agents::tenantUsing(function () use (&$current) {
        return $current;
    });
    $ended = function (?Team $team, object $user, string $status = AgentTurn::DONE, int $tokens = 100, ?string $reason = null, $at = null) {
        AgentTurn::query()->create([
            'id' => (string) Str::uuid7(), 'conversation_id' => (string) Str::uuid7(), 'participant_type' => $user::class, 'participant_id' => $user->id,
            'status' => $status, 'input' => ['prompt' => 'Hi'], 'tenant' => $team ? (string) $team->id : null, 'usage' => ['input_tokens' => $tokens, 'output_tokens' => 0],
            'finish_reason' => $reason, 'finished_at' => $at ?? now(),
        ]);
    };

    // Acme used two answers (one stopped half-way) and had a turn refused; Globex nothing.
    $ended($acme, $owner);
    $ended($acme, $other, AgentTurn::STOPPED, 50);
    $ended($acme, $owner, AgentTurn::FAILED, 0, 'refused');
    AgentLimit::query()->create(['scope' => 'global', 'turns_per_day' => 2, 'tokens_per_month' => 1000, 'user_tokens_per_day' => 120]);
    AgentLimits::flush();

    expect(AgentBudget::turnsToday())->toBe(2)
        ->and(AgentBudget::tokensThisMonth())->toBe(150)
        ->and(AgentBudget::tokensToday($owner->id))->toBe(100)
        ->and(AgentBudget::refusal('Hi'))->toBe(__('This workspace reached today\'s limit of :n answers. It resets at midnight.', ['n' => 2]))
        ->and(AgentBudget::summary()['turns_today'])->toBe(2);

    // Globex is untouched by Acme's turns, and the owner's own tokens there start from zero.
    $current = $globex;
    expect(AgentBudget::turnsToday())->toBe(0)
        ->and(AgentBudget::tokensThisMonth())->toBe(0)
        ->and(AgentBudget::tokensToday($owner->id))->toBe(0)
        ->and(AgentBudget::refusal('Hi'))->toBeNull();

    // A turn in Globex counts against the owner there only: 100 in Acme and 30 in Globex both stay under the 120 per workspace.
    $ended($globex, $owner, AgentTurn::DONE, 30);
    expect(AgentBudget::tokensToday($owner->id))->toBe(30)->and(AgentBudget::refusal('Hi'))->toBeNull();
    $current = $acme;
    expect(AgentBudget::tokensToday($owner->id))->toBe(100);

    // Outside every workspace only the turns without one count, and a turn that ended before today is not today's.
    $current = null;
    expect(AgentBudget::turnsToday())->toBe(0)->and(AgentBudget::tokensThisMonth())->toBe(0);
    $ended(null, $owner, AgentTurn::DONE, 7, at: now()->startOfDay()->subSecond());
    $ended(null, $owner, AgentTurn::DONE, 9);
    expect(AgentBudget::turnsToday())->toBe(1)->and(AgentBudget::tokensToday())->toBe(9);
});

it('refuses a workspace key that matches nothing instead of running without a workspace, on every path', function () {
    Mail::fake();
    config()->set('packstub-agents.email.enabled', true);
    config()->set('packstub-agents.email.secret', 'hook-secret');
    $owner = $this->user(['email' => 'ada@example.com']);
    $acme = $this->team($owner, 'acme');
    $entered = [];
    Agents::enteringTenant(function (Model $tenant) use (&$entered): ?Closure {
        $entered[] = $tenant->slug;

        return null;
    });
    $ran = 0;
    Agents::useMiddleware([function (PendingStep $step, Closure $next) use (&$ran) {
        $ran++;

        return $next($step);
    }]);
    WidgetAgent::fake(['Two widgets are live.']);

    // The context: a key of a workspace that is gone is refused, and refused as a WorkspaceAccessDenied too.
    actingAs($owner);
    expect(fn () => AgentRuntime::enter(['tenant' => 999]))
        ->toThrow(WorkspaceNotFound::class, 'This workspace no longer exists.')
        ->and(fn () => AgentRuntime::enter(['tenant' => 999, 'user' => $owner->getAuthIdentifier()]))
        ->toThrow(WorkspaceAccessDenied::class)
        ->and($entered)->toBe([])
        ->and(auth()->user()?->is($owner))->toBeTrue() // still signed in, as before the call
        ->and(Agents::tenant())->toBeNull();
    auth()->logout();

    // The email channel: an unknown slug (or key) is dropped like a workspace the sender is not in — no reply.
    expect(EmailChannel::receive(new InboundEmail(from: 'ada@example.com', subject: 'Widgets', text: 'How many?', messageId: '<m1@test>', tenant: 'initech')))->toBeNull()
        ->and(EmailChannel::receive(new InboundEmail(from: 'ada@example.com', subject: 'Widgets', text: 'How many?', messageId: '<m2@test>', tenant: '999')))->toBeNull()
        ->and(AgentTurn::query()->count())->toBe(0)
        ->and($ran)->toBe(0)
        ->and($entered)->toBe([]);
    postJson('/agents/email', ['from' => 'ada@example.com', 'subject' => 'Widgets', 'text' => 'How many?', 'tenant' => 'initech'], ['X-Agent-Secret' => 'hook-secret'])->assertOk()->assertJson(['answered' => false]);
    Mail::assertNothingSent();

    // The worker: the workspace was deleted between the question and the turn — the turn fails with the line, nothing runs.
    Agents::tenantUsing(fn () => $acme);
    actingAs($owner);
    Queue::fake();
    $conversation = app(AgentConversationStore::class)->startConversation($owner, 'Still there?');
    $turn = app(AgentTurns::class)->enqueue($conversation, $owner, ['prompt' => 'Still there?'], null, 'auto', null);
    auth()->logout();
    Agents::tenantUsing(fn () => null);
    $acme->delete();

    Queue::pushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $turn->id)->first()->handle(app(AgentTurns::class));

    expect($turn->fresh()->status)->toBe(AgentTurn::FAILED)
        ->and($turn->fresh()->error)->toBe('This workspace no longer exists.')
        ->and($entered)->toBe([])
        ->and($ran)->toBe(0)
        ->and(auth()->user())->toBeNull();
});
