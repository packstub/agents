<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\PendingStep;
use Packstub\Agents\Channels\Email\EmailChannel;
use Packstub\Agents\Channels\Email\InboundEmail;
use Packstub\Agents\Contracts\AgentContext;
use Packstub\Agents\Exceptions\WorkspaceAccessDenied;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Support\AgentRun;
use Packstub\Agents\Support\AgentRuntime;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Support\Context\LaravelContext;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Models\Team;
use Packstub\Agents\Tests\Fixtures\Tools\RetireWidget;
use Packstub\Agents\Tests\Fixtures\Tools\WhoAmI;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Pest\Laravel\actingAs;
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

it('records a refused turn as failed without entering as the person, whatever the context refuses', function () {
    $owner = $this->user();
    $acme = $this->team($owner, 'acme');
    Agents::tenantUsing(fn () => $acme);

    // A context that refuses the person on every entry, with or without a workspace (a panel that no longer admits
    // them): the record-keeping entry must not be made as them, or it would be refused again and the turn stuck.
    $entries = [];
    $refuseAll = false;
    app()->instance(AgentContext::class, new class($entries, $refuseAll) extends LaravelContext
    {
        public function __construct(public array &$entries, public bool &$refuseAll) {}

        public function enter(array $context): Closure
        {
            $this->entries[] = ['tenant' => $context['tenant'] ?? null, 'user' => $context['user'] ?? null];

            if ($this->refuseAll || ($context['user'] ?? null) !== null) {
                throw WorkspaceAccessDenied::make();
            }

            return parent::enter($context);
        }
    });

    actingAs($owner);
    Queue::fake();
    $conversation = app(AgentConversationStore::class)->startConversation($owner, 'Still there?');
    $first = app(AgentTurns::class)->enqueue($conversation, $owner, ['prompt' => 'Still there?'], null, 'auto', null);
    $second = app(AgentTurns::class)->enqueue($conversation, $owner, ['prompt' => 'And now?'], null, 'auto', null);
    auth()->logout();
    $entries = [];

    // handle(): refused as the person, recorded as nobody and outside the workspace; the next turn is started.
    Queue::pushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $first->id)->first()->handle(app(AgentTurns::class));

    expect($first->fresh()->status)->toBe(AgentTurn::FAILED)
        ->and($first->fresh()->error)->toBe('You are not a member of this workspace.')
        ->and($entries)->toBe([['tenant' => (string) $acme->id, 'user' => $owner->id], ['tenant' => null, 'user' => null]])
        ->and($second->fresh()->status)->toBe(AgentTurn::PENDING)
        ->and(auth()->user())->toBeNull();

    // failed() (the worker gave up): the same bookkeeping, and here the context refuses even the record-keeping
    // entry — the row is still marked failed with the line.
    $refuseAll = true;
    $entries = [];
    Exceptions::fake();
    Queue::pushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $second->id)->first()->failed(new RuntimeException('lost'));

    expect($second->fresh()->status)->toBe(AgentTurn::FAILED)
        ->and($second->fresh()->error)->toBe('You are not a member of this workspace.')
        ->and($entries)->toBe([['tenant' => (string) $acme->id, 'user' => $owner->id], ['tenant' => null, 'user' => null]])
        ->and(app(AgentTurns::class)->active($conversation))->toBeNull()
        ->and(auth()->user())->toBeNull();
    Exceptions::assertReported(WorkspaceAccessDenied::class);
});
