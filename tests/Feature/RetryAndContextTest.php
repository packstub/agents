<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentAnswerVersion;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentAttachments;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Support\PageContext;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use Packstub\Agents\Tests\Fixtures\WidgetResource;
use Packstub\Agents\Tests\Fixtures\WidgetServer;

use function Pest\Laravel\actingAs;

// A question that never got its answer is retried wherever it sits, and a chat opened from a record stays about it.
beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::useServer(WidgetServer::class);
    Agents::useResources([WidgetResource::class]);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
});

it('marks every unanswered question, says how its turn ended, and retries an earlier one at the end of the chat', function () {
    $user = $this->user();
    actingAs($user);

    // The first question fails at the provider, the second is refused by a middleware, the third is answered.
    WidgetAgent::fake([fn () => throw new RuntimeException('The provider is overloaded.')]);
    $chat = AgentChat::for($user);
    $first = $chat->send('How many widgets are live?');

    config(['packstub-agents.limits.prompt_max_chars' => 30]);
    AgentLimits::flush();
    $second = $chat->refresh()->send('And how many of them were retired during the last quarter?');
    config(['packstub-agents.limits.prompt_max_chars' => 2000]);
    AgentLimits::flush();

    WidgetAgent::fake(['Alpha is the cheapest.']);
    $third = $chat->refresh()->send('Which one is the cheapest?');

    expect([$first->status, $second->status, $third->status])->toBe([AgentTurn::FAILED, AgentTurn::FAILED, AgentTurn::DONE]);

    $messages = $chat->refresh()->messages();
    expect($messages->pluck('role')->all())->toBe(['user', 'user', 'user', 'assistant'])
        ->and($messages[0])->toMatchArray(['unanswered' => true, 'ended' => ['status' => AgentTurn::FAILED, 'reason' => 'failed', 'error' => 'The provider is overloaded.']])
        ->and($messages[1]['unanswered'])->toBeTrue()
        ->and($messages[1]['ended'])->toMatchArray(['status' => AgentTurn::FAILED, 'reason' => 'refused'])
        ->and($messages[1]['ended']['error'])->toContain('too long')
        ->and($messages[2])->toMatchArray(['unanswered' => false, 'ended' => null, 'editable' => true])
        ->and($messages[3]['regenerable'])->toBeTrue();

    // An answered question, another chat's question and an unknown id are not retried; nor anything while a turn waits.
    expect($chat->retry($messages[2]['id']))->toBeNull()
        ->and($chat->retry((string) Str::uuid7()))->toBeNull()
        ->and(AgentChat::for($this->user())->retry($messages[0]['id']))->toBeNull()
        ->and($chat->retry())->toBeNull(); // the last question has its answer

    // Retry on the first one: it moves to the end, is answered there, and its turns follow it.
    WidgetAgent::fake(['Two widgets are live.']);
    $retried = $chat->retry($messages[0]['id']);

    expect($retried->status)->toBe(AgentTurn::DONE);
    WidgetAgent::assertPrompted('How many widgets are live?');

    $after = $chat->refresh()->messages();
    expect($after->pluck('text')->all())->toBe([
        'And how many of them were retired during the last quarter?',
        'Which one is the cheapest?',
        'Alpha is the cheapest.',
        'How many widgets are live?',
        'Two widgets are live.',
    ])
        ->and($after[0]['unanswered'])->toBeTrue() // still waiting for its own Retry
        ->and($after[3])->toMatchArray(['unanswered' => false, 'editable' => true])
        ->and($after[3]['id'])->not->toBe($messages[0]['id'])
        ->and(ConversationMessage::query()->whereKey($messages[0]['id'])->exists())->toBeFalse()
        ->and(AgentTurn::query()->where('message_id', $after[3]['id'])->pluck('status')->sort()->values()->all())->toBe([AgentTurn::DONE, AgentTurn::FAILED]);

    // Retry without an id is the last question, as before: nothing to retry once it is answered.
    expect($chat->retry())->toBeNull();
});

it('moves a question to the end with its earlier answers, and leaves an id that is not a question alone', function () {
    $user = $this->user();
    actingAs($user);
    $id = conversationWith($user, [
        ['role' => 'user', 'content' => 'First'],
        ['role' => 'user', 'content' => 'Second'],
        ['content' => 'Answer to the second.'],
    ]);
    $store = app(AgentConversationStore::class);
    $rows = ConversationMessage::query()->where('conversation_id', $id)->orderBy('id')->get();
    AgentAnswerVersion::query()->create(['conversation_id' => $id, 'question_id' => $rows[0]->id, 'question' => 'First', 'rows' => []]);

    $moved = $store->moveQuestionToEnd($id, $rows[0]->id);

    expect($moved)->not->toBe($rows[0]->id)
        ->and(ConversationMessage::query()->where('conversation_id', $id)->orderBy('id')->pluck('content')->all())->toBe(['Second', 'Answer to the second.', 'First'])
        ->and(AgentAnswerVersion::query()->where('question_id', $moved)->count())->toBe(1)
        ->and($store->moveQuestionToEnd($id, $rows[2]->id))->toBe($rows[2]->id) // an answer stays where it is
        ->and($store->moveQuestionToEnd($id, 'nope'))->toBe('nope');
});

it('sends the files and the mentions of a question again when it is retried or regenerated', function () {
    Storage::fake('local');
    config()->set('packstub-agents.chat.attachments.disk', 'local');
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    $image = AgentAttachments::store(UploadedFile::fake()->image('label.png'));

    WidgetAgent::fake([fn () => throw new RuntimeException('Overloaded.')]);
    $chat = AgentChat::for($user);
    $chat->send('Is this the label of @Widget Alpha?', [$image], ["widgets/{$alpha->id}"]);

    // What the provider is handed each time: the text (with the mentioned records' summaries) and the files.
    $seen = [];
    $answer = function (string $prompt, $attachments) use (&$seen) {
        $seen[] = ['files' => $attachments->map(fn ($file) => $file->toArray()['path'])->all(), 'mentions' => str_contains($prompt, '- @Widget Alpha (widgets/')];

        return 'Yes, it is.';
    };
    $carried = ['files' => [$image->toArray()['path']], 'mentions' => true];

    WidgetAgent::fake($answer);
    expect($chat->refresh()->retry()?->status)->toBe(AgentTurn::DONE)
        ->and($seen)->toBe([$carried]);

    expect($chat->refresh()->regenerate()?->status)->toBe(AgentTurn::DONE)
        ->and($seen)->toBe([$carried, $carried]);

    // An edited question keeps its files; a record it no longer names is no longer mentioned.
    expect($chat->refresh()->resend('What is on this picture?')?->status)->toBe(AgentTurn::DONE)
        ->and($seen[2])->toBe(['files' => [$image->toArray()['path']], 'mentions' => false]);
});

it('keeps the record a chat was opened from with the conversation, and links it', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha, $beta] = $this->widgets();
    WidgetAgent::fake(['Alpha is live.']);

    $chat = AgentChat::for($user, context: "widgets/{$alpha->id}");
    $chat->send('Is this one live?');

    expect($chat->contextLabel())->toBe('Widget Alpha')
        ->and($chat->contextUrl())->toBe('https://widgets.test/widgets/'.$alpha->id)
        ->and(PageContext::url("widgets/{$alpha->id}"))->toBe('https://widgets.test/widgets/'.$alpha->id)
        ->and(PageContext::url('widgets/999'))->toBeNull()
        ->and(PageContext::url('nope/1'))->toBeNull()
        ->and(PageContext::url(null))->toBeNull()
        ->and(ConversationMessage::query()->where('conversation_id', $chat->conversation())->where('role', 'user')->first()->meta)->toMatchArray(['context' => "widgets/{$alpha->id}"]);

    // Reopened by its id alone — after the redirect to the conversation's URL, from the list of chats, in another
    // tab — the chat is still about the record, and a follow-up tells the model so.
    $reopened = AgentChat::for($user, $chat->conversation());
    expect($reopened->context())->toBe("widgets/{$alpha->id}")
        ->and($reopened->contextLabel())->toBe('Widget Alpha');

    $read = null;
    WidgetAgent::fake(function (string $prompt) use (&$read) {
        $read = $prompt;

        return 'It costs 10.';
    });
    $reopened->send('And what does it cost?');
    expect($read)->toContain('The person opened this chat from Widget Alpha')->toEndWith('And what does it cost?');

    // A context given on reopening wins, and is the conversation's from then on.
    WidgetAgent::fake(['Beta is a draft.']);
    AgentChat::for($user, $chat->conversation(), context: "widgets/{$beta->id}")->send('And this one?');
    expect(AgentChat::for($user, $chat->conversation())->contextLabel())->toBe('Widget Beta');

    // A chat from before questions recorded their context reads it from its newest turn; one about nothing has none.
    $store = app(AgentConversationStore::class);
    ConversationMessage::query()->where('conversation_id', $chat->conversation())->update(['meta' => '[]']);
    expect($store->contextOf($chat->conversation()))->toBe("widgets/{$beta->id}");

    WidgetAgent::fake(['Hello.']);
    $plain = AgentChat::for($user);
    $plain->send('Hello');
    expect(AgentChat::for($user, $plain->conversation())->context())->toBeNull()
        ->and($plain->contextUrl())->toBeNull();
});
