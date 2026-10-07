<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Models\Team;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
});

it('posts a message the app wrote as the assistant: read as history, shown as an answer, never billed', function () {
    $user = $this->user();
    actingAs($user);
    $store = app(AgentConversationStore::class);

    // A conversation the app opens itself, titled as given — no question first.
    $conversation = $store->startConversation($user, 'Digest for Monday, 6 October', 'Monday digest');
    expect(Conversation::query()->findOrFail($conversation)->title)->toBe('Monday digest');

    $messageId = $store->storePostedMessage($conversation, $user, "**Two invoices** are due this week.\n\nShall I draft the reminders?");

    $row = ConversationMessage::query()->findOrFail($messageId);
    expect($row->role)->toBe('assistant')
        ->and($row->meta)->toMatchArray(['posted' => true])
        ->and($row->usage)->toBe([])
        ->and($row->agent)->toBe(WidgetAgent::class)
        ->and(AgentConversationStore::wasPosted($row->meta))->toBeTrue()
        ->and(AgentConversationStore::wasStopped($row->meta))->toBeFalse();

    // The turn log lists it as a done turn ended "posted", without provider, usage or cost.
    $turn = AgentTurn::query()->forConversation($conversation)->sole();
    expect($turn)->toMatchArray(['status' => AgentTurn::DONE, 'message_id' => $messageId, 'finish_reason' => 'posted', 'provider' => null, 'usage' => null, 'cost' => null, 'duration_ms' => 0, 'participant_id' => (string) $user->id])
        ->and($turn->prompt())->toBeNull()
        ->and($turn->decisions())->toBeNull();

    // A surface renders it like any answer, flagged, with nothing to regenerate; the budget does not count it.
    $chat = AgentChat::for($user, $conversation);
    $message = $chat->messages()->sole();
    expect($message)->toMatchArray(['role' => 'assistant', 'posted' => true, 'stopped' => false, 'regenerable' => false, 'continuable' => false, 'unanswered' => false])
        ->and($message['html'])->toContain('<strong>Two invoices</strong>')
        ->and($chat->idle())->toBeTrue()
        ->and(AgentBudget::turnsToday())->toBe(0);

    // The next turn reads it as history, and the model's answer is the first one counted.
    WidgetAgent::fake(['Drafting them now.']);
    $answer = $chat->send('Yes, please.');

    expect($answer->status)->toBe(AgentTurn::DONE)
        ->and($answer->input['title'] ?? false)->toBeFalse() // the app titled it; the provider does not retitle it
        ->and(Conversation::query()->findOrFail($conversation)->title)->toBe('Monday digest')
        ->and(AgentBudget::turnsToday())->toBe(1);

    // The window opens on the posted message (not cut away as a half turn, nor summarized), after one line that
    // says so, since a provider may require the person to speak first.
    $history = $store->getLatestConversationMessages($conversation, 50);
    expect($history->map(fn ($m) => $m->role->value)->all())->toBe(['user', 'assistant', 'user', 'assistant'])
        ->and((string) $history[0]->content)->toBe(__('(This conversation starts with a message you posted.)'))
        ->and((string) $history[1]->content)->toContain('Two invoices')
        ->and((string) $history[3]->content)->toBe('Drafting them now.');

    // Posted after an answer: the question above is not editable (editing would drop the posted message) and nothing is regenerable.
    $store->storePostedMessage($conversation, $user, 'Reminder: the close is on Friday.');
    $messages = AgentChat::for($user, $conversation)->messages();
    expect($messages->pluck('posted')->all())->toBe([true, false, false, true])
        ->and($messages->last()['regenerable'])->toBeFalse()
        ->and($messages[1]['editable'])->toBeFalse()
        ->and(AgentBudget::turnsToday())->toBe(1);
});

it('defers the first turn until the person opens the conversation, checking the budget then', function () {
    $user = $this->user();
    $store = app(AgentConversationStore::class);
    $turns = app(AgentTurns::class);

    // The app, on a schedule and signed in as nobody, opens the chat and stores what it asks.
    $conversation = $store->startConversation($user, 'Let us close September.', 'September close');
    $deferred = $turns->defer($conversation, $user, ['prompt' => 'Let us close September.'], 'auto', 'widgets/1');

    expect($deferred->status)->toBe(AgentTurn::DEFERRED)
        ->and($deferred->isOpen())->toBeTrue()
        ->and($deferred->isActive())->toBeFalse()
        ->and($deferred->input['title'])->toBeFalse() // the app titled it: the provider does not retitle it after the first answer
        ->and($deferred->context)->toBe('widgets/1')
        ->and(AgentTurn::statusLabel(AgentTurn::DEFERRED))->toBe('Deferred');

    // The question is in the transcript already, with the context; the turn is outside the line.
    $question = ConversationMessage::query()->findOrFail($deferred->message_id);
    expect($question->role)->toBe('user')
        ->and($question->content)->toBe('Let us close September.')
        ->and($store->contextOf($conversation))->toBe('widgets/1')
        ->and($turns->active($conversation))->toBeNull()
        ->and($turns->queued($conversation))->toBeEmpty()
        ->and($turns->startNext($conversation))->toBeNull()
        ->and($turns->state($conversation)['active'])->toBeNull()
        ->and($turns->deferred($conversation)?->id)->toBe($deferred->id)
        ->and($deferred->fresh()->status)->toBe(AgentTurn::DEFERRED);

    // Read before it is opened: being answered when opened, so no Retry and nothing editable.
    actingAs($user);
    $chat = AgentChat::for($user, $conversation);
    expect($chat->live()['deferred'])->toBe(['id' => $deferred->id, 'error' => null])
        ->and($chat->idle())->toBeFalse()
        ->and($chat->messages()->sole())->toMatchArray(['role' => 'user', 'unanswered' => false, 'editable' => false]);

    // Someone else opening it starts nothing.
    expect($turns->startDeferred($conversation, $this->user()))->toBeNull()
        ->and($deferred->fresh()->status)->toBe(AgentTurn::DEFERRED);

    // The budget is checked when the owner opens it: a refusal leaves the turn deferred, with the reason for the page.
    AgentLimit::query()->create(['scope' => 'global', 'enabled' => false]);
    AgentLimits::flush();
    expect($turns->startDeferred($conversation, $user))->toBeNull();
    $refusal = __(':name is switched off for this workspace.', ['name' => 'Ask Widgets']);
    expect($deferred->fresh())->toMatchArray(['status' => AgentTurn::DEFERRED, 'error' => $refusal])
        ->and(AgentChat::for($user, $conversation)->live()['deferred'])->toBe(['id' => $deferred->id, 'error' => $refusal]);
    AgentLimit::query()->delete();
    AgentLimits::flush();

    // Another tab holds the lock: this open starts nothing, and the turn is still there for the one that does.
    $lock = Cache::lock('agent-turns:deferred:'.$conversation, 10);
    expect($lock->get())->toBeTrue()
        ->and($turns->startDeferred($conversation, $user))->toBeNull()
        ->and($deferred->fresh()->status)->toBe(AgentTurn::DEFERRED);
    $lock->release();

    // Opened with the budget in order: the turn runs, once, as the opener (who is acting and where captured now).
    WidgetAgent::fake(['September is ready to close.']);
    $started = $turns->startDeferred($conversation, $user);

    expect($started?->id)->toBe($deferred->id)
        ->and($started->status)->toBe(AgentTurn::DONE)
        ->and($started->error)->toBeNull()
        ->and($started->message_id)->toBe($deferred->message_id)
        ->and($started->guard)->toBe('web')
        ->and(Conversation::query()->findOrFail($conversation)->title)->toBe('September close')
        ->and(ConversationMessage::query()->where('conversation_id', $conversation)->orderBy('id')->pluck('role')->all())->toBe(['user', 'assistant'])
        ->and(ConversationMessage::query()->where('conversation_id', $conversation)->count())->toBe(2)
        ->and($turns->startDeferred($conversation, $user))->toBeNull()
        ->and(AgentTurn::query()->forConversation($conversation)->count())->toBe(1);

    $messages = AgentChat::for($user, $conversation)->messages();
    expect($messages->pluck('role')->all())->toBe(['user', 'assistant'])
        ->and($messages->last()['text'])->toBe('September is ready to close.')
        ->and($messages->last()['regenerable'])->toBeTrue()
        ->and(AgentChat::for($user, $conversation)->live()['deferred'])->toBeNull();

    // A deferred turn needs a question; it is never pruned while it waits.
    expect(fn () => $turns->defer($conversation, $user, ['decisions' => ['c1' => true]], 'auto', null))->toThrow(InvalidArgumentException::class);
    config(['packstub-agents.chat.keep_turns_days' => 1]);
    $waiting = $turns->defer($conversation, $user, ['prompt' => 'And October?'], 'auto', null);
    AgentTurn::query()->whereKey($waiting->id)->update(['created_at' => now()->subDays(3)]);
    expect((new AgentTurn)->prunable()->pluck('id')->all())->not->toContain($waiting->id);
});

it('lets the provider title a deferred conversation only while it carries the placeholder title', function () {
    $user = $this->user();
    $store = app(AgentConversationStore::class);
    $turns = app(AgentTurns::class);

    // Opened without a title: the question stands in until the first answer, then the provider titles it.
    $untitled = $store->startConversation($user, 'Let us close September.');
    expect($turns->defer($untitled, $user, ['prompt' => 'Let us close September.'], 'auto', null)->input['title'])->toBeTrue();

    // Titled by the app: kept.
    $titled = $store->startConversation($user, 'Let us close September.', 'September close');
    expect($turns->defer($titled, $user, ['prompt' => 'Let us close September.'], 'auto', null)->input['title'])->toBeFalse();

    // Titled, but the app says the provider may retitle it.
    $retitle = $store->startConversation($user, 'Let us close September.', 'September close');
    expect($turns->defer($retitle, $user, ['prompt' => 'Let us close September.', 'title' => true], 'auto', null)->input['title'])->toBeTrue();
});

it('keeps a deferred turn in its workspace and off while the agent is switched off', function () {
    $owner = $this->user();
    $acme = $this->team($owner, 'acme');
    $globex = $this->team($owner, 'globex');
    $store = app(AgentConversationStore::class);
    $turns = app(AgentTurns::class);
    Agents::tenantModel(Team::class, 'slug');

    // Deferred in Acme.
    Agents::tenantUsing(fn () => $acme);
    $conversation = $store->startConversation($owner, 'Let us close September.', 'September close');
    $deferred = $turns->defer($conversation, $owner, ['prompt' => 'Let us close September.'], 'auto', null);
    expect($deferred->tenant)->toBe((string) $acme->id);

    actingAs($owner);

    // Opened from Globex: nothing starts, the turn waits for an open in Acme with the reason on it.
    Agents::tenantUsing(fn () => $globex);
    $elsewhere = __('This question was asked in another workspace. Open the conversation there to get its answer.');
    expect($turns->startDeferred($conversation, $owner))->toBeNull()
        ->and($deferred->fresh())->toMatchArray(['status' => AgentTurn::DEFERRED, 'tenant' => (string) $acme->id, 'error' => $elsewhere])
        ->and(AgentChat::for($owner, $conversation)->live()['deferred'])->toBe(['id' => $deferred->id, 'error' => $elsewhere]);

    // Opened in Acme with the agent switched off: the same, with that reason.
    Agents::tenantUsing(fn () => $acme);
    config(['packstub-agents.enabled' => false]);
    expect($turns->startDeferred($conversation, $owner))->toBeNull()
        ->and($deferred->fresh())->toMatchArray(['status' => AgentTurn::DEFERRED, 'error' => __(':name is switched off.', ['name' => 'Ask Widgets'])]);
    config(['packstub-agents.enabled' => null]);

    // Opened in Acme: it runs, in Acme.
    WidgetAgent::fake(['September is ready to close.']);
    $started = $turns->startDeferred($conversation, $owner);
    expect($started?->status)->toBe(AgentTurn::DONE)
        ->and($started->error)->toBeNull()
        ->and($started->tenant)->toBe((string) $acme->id);
});

it('deletes the open turns with the conversation, so a deferred one does not outlive it', function () {
    $user = $this->user();
    $store = app(AgentConversationStore::class);
    $turns = app(AgentTurns::class);
    config(['packstub-agents.chat.keep_turns_days' => 1]);

    $conversation = $store->startConversation($user, 'Let us close September.', 'September close');
    $store->storePostedMessage($conversation, $user, 'The close opens on Monday.');
    $deferred = $turns->defer($conversation, $user, ['prompt' => 'Let us close September.'], 'auto', null);
    $posted = AgentTurn::query()->forConversation($conversation)->where('status', AgentTurn::DONE)->sole();

    $store->deleteConversation($conversation);

    expect(Conversation::query()->whereKey($conversation)->exists())->toBeFalse()
        ->and(AgentTurn::query()->whereKey($deferred->id)->exists())->toBeFalse()
        ->and(AgentTurn::query()->whereKey($posted->id)->exists())->toBeTrue() // the turn log keeps what ended; pruning takes it after keep_turns_days
        ->and($turns->deferred($conversation))->toBeNull();
});

it('does not count a posted message against the day\'s answers, which are counted from agent_turns', function () {
    $user = $this->user();
    actingAs($user);
    config()->set('packstub-agents.limits.turns_per_day', 2);
    $store = app(AgentConversationStore::class);
    $conversation = $store->startConversation($user, 'Digest', 'Digest');

    // Two posts and one answer today: one answer counted, room for one more.
    $store->storePostedMessage($conversation, $user, 'Morning digest.');
    $store->storePostedMessage($conversation, $user, 'Afternoon digest.');
    AgentTurn::query()->create([
        'id' => (string) Str::uuid7(), 'conversation_id' => $conversation, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'status' => AgentTurn::DONE, 'input' => ['prompt' => 'Earlier'], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10], 'finished_at' => now(),
    ]);

    expect(AgentTurn::query()->forConversation($conversation)->where('finish_reason', AgentTurn::POSTED)->count())->toBe(2)
        ->and(AgentBudget::turnsToday())->toBe(1)
        ->and(AgentBudget::refusal('hi'))->toBeNull();

    // A third post still does not spend the day's second answer.
    $store->storePostedMessage($conversation, $user, 'Evening digest.');
    expect(AgentBudget::turnsToday())->toBe(1)->and(AgentBudget::refusal('hi'))->toBeNull();
});
