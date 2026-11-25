<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Chat\ApprovalForms;
use Packstub\Agents\Chat\ChatHost;
use Packstub\Agents\Chat\ChatView;
use Packstub\Agents\Chat\Hosts\RouteHost;
use Packstub\Agents\Events\ProposalDecided;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentToolCall;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use Packstub\Agents\Tests\Fixtures\WidgetResource;
use Packstub\Agents\Tests\Fixtures\WidgetServer;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

// Approving a proposal with corrected arguments (an approval form, filament-agents #108): AgentChat::decide() with
// arguments runs the tool with them, the transcript and the model read what ran, the log keeps both.
beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::useServer(WidgetServer::class);
    Agents::useResources([WidgetResource::class]);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
    Abilities::$allowed = ['widgets.view', 'widgets.manage'];
});

/** A chat whose answer proposes renaming $widget (call c1, and c2 for $other when given), paused for the decision. */
function renameProposal(object $user, object $widget, string $name = 'Alpha II', ?object $other = null): string
{
    $calls = [['id' => 'c1', 'name' => 'rename-widget', 'arguments' => ['id' => $widget->id, 'name' => $name]]];
    $pending = ['c1' => 'Rename?'];

    if ($other) {
        $calls[] = ['id' => 'c2', 'name' => 'rename-widget', 'arguments' => ['id' => $other->id, 'name' => 'Beta II']];
        $pending['c2'] = 'Rename?';
    }

    return conversationWith($user, [
        ['role' => 'user', 'content' => 'Rename it'],
        ['tool_calls' => $calls, 'approval_state' => ['pending' => $pending]],
    ]);
}

it('runs an approved proposal with the corrected arguments and logs both the proposed and the approved ones', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    $conversation = renameProposal($user, $alpha, 'Alpah II');
    Event::fake([ProposalDecided::class]);

    WidgetAgent::fake(['Renamed to Alpha II.']);
    $turn = AgentChat::for($user, $conversation)->decide('c1', true, ['name' => 'Alpha II']);

    expect($turn->status)->toBe(AgentTurn::DONE)
        ->and($turn->edits())->toBe(['c1' => ['id' => $alpha->id, 'name' => 'Alpha II']])
        ->and($alpha->fresh()->name)->toBe('Alpha II');

    // The transcript keeps what ran (laravel/ai writes the edited arguments to the stored call), so the card and the
    // model read the corrected call; the log keeps what the model proposed beside it.
    $call = collect(ConversationMessage::query()->where('conversation_id', $conversation)->where('role', 'assistant')->get()->flatMap->tool_calls)->firstWhere('id', 'c1');
    $row = AgentToolCall::query()->sole();

    expect($call['arguments'])->toBe(['id' => $alpha->id, 'name' => 'Alpha II'])
        ->and($row->arguments)->toBe(['id' => $alpha->id, 'name' => 'Alpah II'])
        ->and($row->approved_arguments)->toBe(['id' => $alpha->id, 'name' => 'Alpha II'])
        ->and($row->decision)->toBe('approved')
        ->and($row->status)->toBe(AgentToolCall::SUCCEEDED);

    Event::assertDispatched(ProposalDecided::class, fn (ProposalDecided $e) => $e->callId === 'c1'
        && $e->arguments === ['id' => $alpha->id, 'name' => 'Alpah II']
        && $e->approvedArguments === ['id' => $alpha->id, 'name' => 'Alpha II']);
});

it('approves as proposed when the corrected arguments change nothing', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    $conversation = renameProposal($user, $alpha);

    WidgetAgent::fake(['Done.']);
    $turn = AgentChat::for($user, $conversation)->decide('c1', true, ['name' => 'Alpha II']);

    expect($turn->edits())->toBe([])
        ->and($alpha->fresh()->name)->toBe('Alpha II')
        ->and(AgentToolCall::query()->sole()->approved_arguments)->toBeNull();
});

it('starts nothing for corrected arguments on a call that is not waiting in the chat', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    $conversation = renameProposal($user, $alpha);

    expect(AgentChat::for($user, $conversation)->decide('c9', true, ['name' => 'Other']))->toBeNull()
        ->and(AgentTurn::query()->where('conversation_id', $conversation)->count())->toBe(0)
        ->and($alpha->fresh()->name)->toBe('Alpha');
});

it('ignores a rejection\'s arguments', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    $conversation = renameProposal($user, $alpha);

    WidgetAgent::fake(['Left as it is.']);
    $turn = AgentChat::for($user, $conversation)->decide('c1', false, ['name' => 'Other']);

    expect($turn->edits())->toBe([])
        ->and($alpha->fresh()->name)->toBe('Alpha')
        ->and(AgentToolCall::query()->sole()->approved_arguments)->toBeNull();
});

it('keeps a corrected decision while it waits for the other proposal of the same answer', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha, $beta] = $this->widgets();
    $conversation = renameProposal($user, $alpha, 'Alpah II', $beta);

    WidgetAgent::fake(['Both renamed.']);
    $held = AgentChat::for($user, $conversation)->decide('c1', true, ['name' => 'Alpha II']);

    expect($held->status)->toBe(AgentTurn::QUEUED)
        ->and($alpha->fresh()->name)->toBe('Alpha');

    $turn = AgentChat::for($user, $conversation)->decide('c2', true);

    expect($turn->id)->toBe($held->id)
        ->and($turn->status)->toBe(AgentTurn::DONE)
        ->and($alpha->fresh()->name)->toBe('Alpha II')
        ->and($beta->fresh()->name)->toBe('Beta II');
});

it('drops a held correction when the same call is decided again without one', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha, $beta] = $this->widgets();
    $conversation = renameProposal($user, $alpha, 'Alpah II', $beta);

    WidgetAgent::fake(['Both renamed.']);
    AgentChat::for($user, $conversation)->decide('c1', true, ['name' => 'Alpha II']);
    $held = AgentChat::for($user, $conversation)->decide('c1', true);

    expect($held->status)->toBe(AgentTurn::QUEUED)
        ->and($held->edits())->toBe([]);

    AgentChat::for($user, $conversation)->decide('c2', true, ['name' => 'Beta III']);

    expect($held->refresh()->edits())->toBe(['c2' => ['id' => $beta->id, 'name' => 'Beta III']])
        ->and($alpha->fresh()->name)->toBe('Alpah II')
        ->and($beta->fresh()->name)->toBe('Beta III');
});

it('gives the turn a host\'s own decision reports to the page', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    $conversation = renameProposal($user, $alpha);

    WidgetAgent::fake(['Renamed.']);
    $view = ChatView::make($conversation);
    $turn = $view->chat->decide('c1', true, ['name' => 'Alpha III']);

    expect($view->turn($turn))->toMatchArray(['id' => $turn->id, 'conversation' => $conversation, 'active' => false])
        ->and($view->turn($turn)['poll'])->toContain($conversation);
});

it('never takes arguments from the chat\'s decide endpoint', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    $conversation = renameProposal($user, $alpha);

    WidgetAgent::fake(['Renamed.']);
    postJson('/agents/chat/act/decide', ['conversation' => $conversation, 'call' => 'c1', 'approve' => true, 'arguments' => ['name' => 'Injected']])->assertOk();

    expect($alpha->fresh()->name)->toBe('Alpha II')
        ->and(AgentToolCall::query()->sole()->approved_arguments)->toBeNull();
});

it('lets a host draw a waiting proposal\'s decision in place of the buttons', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    $conversation = renameProposal($user, $alpha);

    view()->addNamespace('approval-test', __DIR__.'/../Fixtures/views');
    app()->singleton(ChatHost::class, fn () => new class extends RouteHost implements ApprovalForms
    {
        public function approvalView(array $tool): ?string
        {
            return $tool['tool'] === 'rename-widget' ? 'approval-test::approval-form' : null;
        }
    });

    $html = postJson('/agents/chat/view', ['conversation' => $conversation])->json('html');

    expect($html)
        ->toContain('fi-chat-proposal-form')
        ->toContain('data-agents-approval="c1"')
        ->toContain('A form for c1 (Alpha II)')
        ->not->toContain('decide(&#039;c1&#039;, true)');
});
