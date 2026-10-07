<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Ai\Side\DecisionAgent;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Support\TypedDecisions;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Tools\RetireWidget;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
    Queue::fake();
});

/** A conversation whose last answer proposes retiring Alpha (c1) and Beta (c2), and the reply typed over it. */
function replyOverTwoProposals(object $user, string $reply): AgentTurn
{
    $store = app(AgentConversationStore::class);
    $conversation = $store->startConversation($user, 'Retire Alpha and Beta');

    ConversationMessage::query()->create([
        'id' => (string) Str::uuid7(), 'conversation_id' => $conversation, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => '', 'attachments' => [], 'usage' => [], 'meta' => [],
        'steps' => [['content' => '', 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => [], 'tool_calls' => [
            ['id' => 'c1', 'name' => 'retire-widget', 'arguments' => ['id' => 1], 'result_id' => 'call_1', 'approval_reason' => 'Retire widget Alpha?'],
            ['id' => 'c2', 'name' => 'retire-widget', 'arguments' => ['id' => 2], 'result_id' => 'call_2', 'approval_reason' => 'Retire widget Beta?'],
        ]]],
        'status' => MessageStatus::Paused,
    ]);

    return app(AgentTurns::class)->enqueue($conversation, $user, ['prompt' => $reply], null, 'auto', null);
}

it('reads the word lists of every locale from the lang files, a language the app publishes included', function () {
    expect(TypedDecisions::locales())->toContain('en', 'de', 'es', 'ro', 'ru')
        ->and(TypedDecisions::lists()['yes'])->toContain('yes', 'ja', 'sí', 'da', 'да')
        ->and(AgentTurns::decisionInText('Oui'))->toBeNull();

    $dir = lang_path('vendor/packstub-agents/fr');
    File::ensureDirectoryExists($dir);
    File::put($dir.'/decisions.php', "<?php\n\nreturn ['yes' => ['oui', 'vas-y'], 'no' => ['non merci'], 'no_openers' => ['non']];\n");

    try {
        expect(TypedDecisions::locales())->toContain('fr')
            ->and(AgentTurns::decisionInText('Oui, vas-y !'))->toBeTrue()
            ->and(AgentTurns::decisionInText('Non merci.'))->toBeFalse()
            ->and(AgentTurns::decisionInText('Non, pas Beta.'))->toBeFalse()
            ->and(AgentTurns::decisionInText('Oui, mais seulement Alpha.'))->toBeNull()
            ->and(AgentTurns::decisionInText('Yes, go ahead.'))->toBeTrue(); // the shipped lists still apply

        // A shipped language's file replaces its lists key by key, so a phrase can be taken out; a key left out stays.
        File::ensureDirectoryExists(lang_path('vendor/packstub-agents/en'));
        File::put(lang_path('vendor/packstub-agents/en/decisions.php'), "<?php\n\nreturn ['yes' => ['yes', 'yes please']];\n");

        expect(AgentTurns::decisionInText('Sure'))->toBeNull()
            ->and(AgentTurns::decisionInText('Go ahead'))->toBeNull()
            ->and(AgentTurns::decisionInText('Yes please'))->toBeTrue()
            ->and(AgentTurns::decisionInText('Cancel'))->toBeFalse()
            ->and(AgentTurns::decisionInText('Ja'))->toBeTrue();
    } finally {
        File::deleteDirectory(lang_path('vendor/packstub-agents'));
    }
});

it('reads a plain yes with the lists without building the proposals, so a write tool that cannot be resolved does not stop it', function () {
    $user = $this->user();
    actingAs($user);

    Agents::useTools([RetireWidget::class]);
    app()->bind(RetireWidget::class, fn () => throw new RuntimeException('Not resolvable here.'));

    expect(replyOverTwoProposals($user, 'Yes, go ahead.')->decisions())->toBe(['c1' => true, 'c2' => true]);
});

it('records that the word lists decided a typed reply', function () {
    $user = $this->user();
    actingAs($user);

    $turn = replyOverTwoProposals($user, 'Yes, go ahead.');

    expect($turn->decisions())->toBe(['c1' => true, 'c2' => true])
        ->and($turn->decidedBy())->toBe('words')
        ->and($turn->decisionReason())->toBeNull();
});

it('lets the app decide a typed reply first, one proposal at a time, and falls back to the lists when it does not', function () {
    $user = $this->user();
    actingAs($user);

    $seen = null;
    Agents::decideTypedUsing(function (string $text, array $proposals) use (&$seen) {
        $seen = $proposals;

        return match ($text) {
            'Only Alpha' => ['c1' => true],
            'Not today' => false,
            'Boom' => throw new RuntimeException('The rule broke.'),
            default => null,
        };
    });

    $turn = replyOverTwoProposals($user, 'Only Alpha');
    expect($turn->decisions())->toBe(['c1' => true, 'c2' => false]) // a call the rule leaves out is rejected
        ->and($turn->decidedBy())->toBe('app')
        ->and(array_keys($seen))->toBe(['c1', 'c2'])
        ->and($seen['c1'])->toMatchArray(['name' => 'retire-widget', 'arguments' => ['id' => 1]])
        ->and($seen['c1']['question'])->toBeString()->not->toBe('');

    expect(replyOverTwoProposals($user, 'Not today')->decisions())->toBe(['c1' => false, 'c2' => false]);

    // Null, or a rule that throws, leaves the reply to the lists.
    expect(replyOverTwoProposals($user, 'Sure, confirm it.')->decidedBy())->toBe('words');
    $turn = replyOverTwoProposals($user, 'Boom');
    expect($turn->decisions())->toBeNull()
        ->and($turn->prompt())->toBe('Boom');

    Agents::decideTypedUsing(null);
    expect(Agents::typedDecider())->toBeNull();
});

it('asks the classifier, when switched on, about a reply the lists cannot read, and applies it only when it decided every proposal', function () {
    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake([]);
    $store = app(AgentConversationStore::class);

    // Off by default: the reply is a question, the proposals declined.
    $asked = [];
    DecisionAgent::fake(function (string $prompt) use (&$asked) {
        $asked[] = $prompt;

        return ['decisions' => [['id' => 'c1', 'decision' => 'approve'], ['id' => 'c2', 'decision' => 'reject']], 'reason' => 'Only Alpha is approved.'];
    });
    $turn = replyOverTwoProposals($user, 'Yes, but only Alpha.');
    expect($turn->decisions())->toBeNull()
        ->and($store->pendingCalls($turn->conversation_id, $user))->toBe([])
        ->and($asked)->toBe([]);

    config(['packstub-agents.decision_classifier.enabled' => true]);

    $turn = replyOverTwoProposals($user, 'Yes, but only Alpha.');
    expect($turn->decisions())->toBe(['c1' => true, 'c2' => false])
        ->and($turn->decidedBy())->toBe('classifier')
        ->and($turn->decisionReason())->toBe('Only Alpha is approved.')
        ->and($turn->input['said'])->toBe('Yes, but only Alpha.')
        ->and($asked)->toHaveCount(1)
        ->and($asked[0])->toContain('- c1: ', '- c2: ', 'Yes, but only Alpha.');

    // What the lists read is not asked: they come first, so the classifier never approves what they reject.
    expect(replyOverTwoProposals($user, 'No, leave them.')->decisions())->toBe(['c1' => false, 'c2' => false])
        ->and(replyOverTwoProposals($user, 'Yes, go ahead.')->decidedBy())->toBe('words')
        ->and($asked)->toHaveCount(1);

    // A proposal it leaves undecided makes the reply a question.
    DecisionAgent::fake([['decisions' => [['id' => 'c1', 'decision' => 'approve'], ['id' => 'c2', 'decision' => 'question']], 'reason' => 'Beta is unclear.']]);
    $turn = replyOverTwoProposals($user, 'Ok wait, what does Beta change?');
    expect($turn->decisions())->toBeNull()
        ->and($store->pendingCalls($turn->conversation_id, $user))->toBe([]);

    // So does a classifier that fails.
    DecisionAgent::fake(fn () => throw new RuntimeException('The provider is down.'));
    expect(replyOverTwoProposals($user, 'Yes, but only Alpha.')->decisions())->toBeNull();
});
