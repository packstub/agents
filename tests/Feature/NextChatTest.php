<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Packstub\Agents\Channels\Email\AgentAnswerMail;
use Packstub\Agents\Channels\Email\EmailChannel;
use Packstub\Agents\Channels\Email\InboundEmail;
use Packstub\Agents\Events\ProposalDecided;
use Packstub\Agents\Events\ToolCalled;
use Packstub\Agents\Events\TurnEnded;
use Packstub\Agents\Events\TurnStarted;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentAnswerVersion;
use Packstub\Agents\Models\AgentMessageFeedback;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentAttachments;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentPricing;
use Packstub\Agents\Support\AgentRun;
use Packstub\Agents\Testing\AgentEval;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use Packstub\Agents\Tests\Fixtures\WidgetResource;
use Packstub\Agents\Tests\Fixtures\WidgetServer;
use PHPUnit\Framework\AssertionFailedError;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::useServer(WidgetServer::class);
    Agents::useResources([WidgetResource::class]);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
});

it('fires an event when a turn starts, calls a tool, decides a proposal and ends', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    Event::fake([TurnStarted::class, ToolCalled::class, ProposalDecided::class, TurnEnded::class]);

    WidgetAgent::fake([new ToolCall('c1', 'list-widgets', ['filters' => ['status' => ['live']]]), new ToolCall('c2', 'rename-widget', ['id' => $alpha->id, 'name' => 'Alpha II']), 'Shall I?']);
    $chat = AgentChat::for($user);
    $turn = $chat->send('Rename the live one.');

    Event::assertDispatched(TurnStarted::class, fn (TurnStarted $e) => $e->turn->id === $turn->id);
    Event::assertDispatched(ToolCalled::class, fn (ToolCalled $e) => $e->tool === 'list-widgets' && $e->arguments === ['filters' => ['status' => ['live']]] && $e->callId === 'c1');
    Event::assertDispatched(ToolCalled::class, fn (ToolCalled $e) => $e->tool === 'rename-widget');
    Event::assertDispatched(TurnEnded::class, fn (TurnEnded $e) => $e->turn->id === $turn->id && $e->turn->status === AgentTurn::DONE);
    Event::assertNotDispatched(ProposalDecided::class);

    WidgetAgent::fake(['Done.']);
    $decision = $chat->decide('c2', true);
    Event::assertDispatched(ProposalDecided::class, fn (ProposalDecided $e) => $e->turn->id === $decision->id && $e->callId === 'c2' && $e->tool === 'rename-widget' && $e->arguments === ['id' => $alpha->id, 'name' => 'Alpha II'] && $e->approved);
    Event::assertDispatched(TurnEnded::class, 2);
});

it('stores an attachment with the question, hands it to the provider, shows it in the transcript and deletes it with the chat', function () {
    Storage::fake('local');
    config()->set('packstub-agents.chat.attachments.disk', 'local');
    $user = $this->user();
    actingAs($user);

    expect(AgentAttachments::enabled())->toBeTrue()
        ->and(AgentAttachments::accepts('image/png'))->toBeTrue()
        ->and(AgentAttachments::accepts('application/zip'))->toBeFalse()
        ->and(fn () => AgentAttachments::store(UploadedFile::fake()->create('a.zip', 10, 'application/zip')))->toThrow(InvalidArgumentException::class);

    $image = AgentAttachments::store(UploadedFile::fake()->image('invoice.png'));
    expect($image->toArray())->toMatchArray(['type' => 'stored-image', 'name' => 'invoice.png', 'disk' => 'local'])
        ->and(Storage::disk('local')->exists($image->toArray()['path']))->toBeTrue();

    WidgetAgent::fake(['It is an invoice for 12 widgets.']);
    $chat = AgentChat::for($user);
    $turn = $chat->send('What is this?', [$image]);

    expect($turn->status)->toBe(AgentTurn::DONE);
    WidgetAgent::assertPrompted(fn ($prompt) => $prompt->attachments->count() === 1 && $prompt->attachments->first()->toArray()['path'] === $image->toArray()['path']);

    $messages = $chat->messages();
    expect($messages[0]['attachments'])->toHaveCount(1)
        ->and($messages[0]['attachments'][0])->toMatchArray(['name' => 'invoice.png', 'image' => true, 'path' => $image->toArray()['path']])
        ->and($messages[1]['attachments'])->toBe([])
        ->and(json_encode(ConversationMessage::query()->where('conversation_id', $chat->conversation())->where('role', 'user')->value('attachments')))->toContain('stored-image');

    // A question of an attachment alone gets a placeholder text.
    WidgetAgent::fake(['Still an invoice.']);
    expect($chat->send('', [$image])?->status)->toBe(AgentTurn::DONE)
        ->and($chat->send(''))->toBeNull();

    app(AgentConversationStore::class)->deleteConversation($chat->conversation());
    expect(Storage::disk('local')->exists($image->toArray()['path']))->toBeFalse();
});

it('sends the records a question mentions as their summaries, and shows them as chips', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    WidgetAgent::fake(['Alpha is live.']);

    $chat = AgentChat::for($user);
    $chat->send('Is @Widget Alpha live?', mentions: ["widgets/{$alpha->id}", 'widgets/999', 'nope']);

    WidgetAgent::assertPrompted(fn ($prompt) => str_starts_with($prompt->prompt, 'Is @Widget Alpha live?')
        && str_contains($prompt->prompt, 'Records the person mentioned')
        && str_contains($prompt->prompt, "- @Widget Alpha (widgets/{$alpha->id}): {\"id\":{$alpha->id},\"name\":\"Alpha\"")
        && ! str_contains($prompt->prompt, 'widgets/999'));

    $messages = $chat->messages();
    expect($messages[0]['text'])->toBe('Is @Widget Alpha live?') // stored as typed
        ->and($messages[0]['mentions'])->toBe([['ref' => "widgets/{$alpha->id}", 'label' => 'Widget Alpha']])
        ->and($messages[1]['mentions'])->toBe([]);
});

it('continues an answer the length limit cut short, hiding the continuation question', function () {
    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake(['Here is the first half of the list']);

    $chat = AgentChat::for($user);
    $chat->send('List every widget in detail.');
    expect($chat->messages()->last()['continuable'])->toBeFalse()
        ->and($chat->continueAnswer())->toBeNull();

    app(AgentConversationStore::class)->markCutShort($chat->conversation(), 'length');
    $chat = AgentChat::for($user, $chat->conversation());
    expect($chat->messages()->last())->toMatchArray(['cutShort' => 'length', 'continuable' => true]);

    WidgetAgent::fake(['and here is the second half.']);
    $turn = $chat->continueAnswer();
    expect($turn->status)->toBe(AgentTurn::DONE);
    WidgetAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Continue exactly where your previous answer stopped'));

    $messages = AgentChat::for($user, $chat->conversation())->messages();
    expect($messages)->toHaveCount(4)
        ->and($messages[2])->toMatchArray(['role' => 'user', 'continuation' => true])
        ->and($messages[3])->toMatchArray(['role' => 'assistant', 'continued' => true, 'continuable' => false])
        ->and($messages[1]['continued'])->toBeFalse()
        ->and(AgentChat::for($user, $chat->conversation())->transcript())->not->toContain('Continue exactly where');
});

it('keeps the earlier answers of a question as versions and puts one back', function () {
    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake(['Two.']);
    $chat = AgentChat::for($user);
    $chat->send('How many?');
    $question = ConversationMessage::query()->where('conversation_id', $chat->conversation())->where('role', 'user')->sole();

    expect($chat->messages()[0]['versions'])->toBe(0)
        ->and($chat->versions($question->id))->toBeEmpty();

    $answered = ConversationMessage::query()->where('conversation_id', $chat->conversation())->where('role', 'assistant')->sole()->created_at;
    $this->travel(10)->minutes();
    WidgetAgent::fake(['Two of them.']);
    $chat->regenerate();
    WidgetAgent::fake(['Two are live.']);
    $chat->resend('How many are live?');

    $chat = AgentChat::for($user, $chat->conversation());
    $versions = $chat->versions($question->id);
    expect($chat->messages()[0]['versions'])->toBe(2)
        ->and($versions->pluck('text')->all())->toBe(['Two.', 'Two of them.'])
        ->and($versions->pluck('question')->all())->toBe(['How many?', 'How many?'])
        ->and($versions[0]['html'])->toContain('Two.')
        // A version is dated when its answer was given, not when Regenerate replaced it.
        ->and($versions[0]['at']->equalTo($answered))->toBeTrue();

    // Put the first answer back: the current one becomes a version, the question's text comes back with it.
    expect($chat->showVersion($question->id, $versions[0]['id']))->toBeTrue()
        ->and($chat->showVersion($question->id, 999))->toBeFalse();
    $chat = AgentChat::for($user, $chat->conversation());
    expect(ConversationMessage::query()->where('conversation_id', $chat->conversation())->orderBy('id')->pluck('content')->all())->toBe(['How many?', 'Two.'])
        ->and($chat->versions($question->id)->pluck('text')->all())->toBe(['Two of them.', 'Two are live.'])
        ->and(AgentAnswerVersion::query()->count())->toBe(2);

    app(AgentConversationStore::class)->deleteConversation($chat->conversation());
    expect(AgentAnswerVersion::query()->count())->toBe(0);
});

it('renames, pins, searches and exports a chat, and rates an answer with a note against its turn', function () {
    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake(['Two widgets are live: Alpha and Beta.']);
    $chat = AgentChat::for($user);
    $turn = $chat->send('How many widgets are live?');

    expect($chat->rename('  '))->toBeFalse()
        ->and($chat->rename('Live widgets'))->toBeTrue()
        ->and($chat->title())->toBe('Live widgets')
        ->and($chat->pinned())->toBeFalse()
        ->and($chat->pin())->toBeTrue()
        ->and($chat->pin())->toBeTrue()
        ->and($chat->pinned())->toBeTrue()
        ->and(AgentChat::pinnedIds($user))->toBe([$chat->conversation()])
        ->and(AgentChat::pinnedIds($this->user()))->toBe([])
        ->and($chat->unpin())->toBeTrue()
        ->and($chat->unpin())->toBeFalse()
        ->and(AgentChat::for($user)->rename('x'))->toBeFalse();

    $hits = AgentChat::search($user, 'alpha');
    expect($hits)->toHaveCount(1)
        ->and($hits[0])->toMatchArray(['conversation' => $chat->conversation(), 'title' => 'Live widgets'])
        ->and($hits[0]['snippet'])->toContain('Alpha')
        ->and(AgentChat::search($user, 'live widgets')[0]['snippet'])->toBeNull() // matched by title alone
        ->and(AgentChat::search($user, 'nothing here'))->toBeEmpty()
        ->and(AgentChat::search($this->user(), 'alpha'))->toBeEmpty()
        ->and(AgentChat::search($user, ''))->toBeEmpty();

    $transcript = $chat->transcript();
    expect($transcript)->toStartWith('# Live widgets')
        ->toContain('**You**')
        ->toContain('How many widgets are live?')
        ->toContain('**Ask Widgets**')
        ->toContain('Two widgets are live: Alpha and Beta.');

    $answer = $chat->messages()[1];
    $chat->rate($answer['id'], 'down', 'Beta is retired');
    $feedback = AgentMessageFeedback::query()->where('message_id', $answer['id'])->sole();
    expect($feedback->rating)->toBe('down')
        ->and($feedback->note)->toBe('Beta is retired')
        ->and($feedback->turn_id)->toBe($turn->id)
        ->and(AgentChat::for($user, $chat->conversation())->messages()[1])->toMatchArray(['rating' => 'down', 'ratingNote' => 'Beta is retired']);

    $chat->rate($answer['id'], 'up');
    expect($feedback->fresh()->note)->toBeNull();
});

it('prices a turn from the model\'s price list and sums the cost of a chat', function () {
    config()->set('packstub-agents.pricing.models', ['test-claude' => ['in' => 3, 'out' => 15, 'cache_read' => 0.3, 'cache_write' => 3.75], 'test-claude-auto' => ['in' => 5, 'out' => 25]]);

    expect(AgentPricing::for('test-claude-fast'))->toBe(['in' => 3, 'out' => 15, 'cache_read' => 0.3, 'cache_write' => 3.75]) // the prefix
        ->and(AgentPricing::for('test-claude-auto'))->toBe(['in' => 5, 'out' => 25]) // the exact name wins
        ->and(AgentPricing::for('gpt-9'))->toBeNull()
        ->and(AgentPricing::cost('test-claude-fast', ['prompt_tokens' => 1_000_000, 'completion_tokens' => 100_000, 'cache_read_input_tokens' => 1_000_000, 'reasoning_tokens' => 0]))->toBe(4.8)
        ->and(AgentPricing::cost('gpt-9', ['prompt_tokens' => 10]))->toBeNull()
        ->and(AgentPricing::format(0.0123))->toBe('$0.0123')
        ->and(AgentPricing::format(1.2))->toBe('$1.20')
        ->and(AgentPricing::format(null))->toBeNull();

    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake([new TextResponse('Two.', new Usage(promptTokens: 200_000, completionTokens: 40_000), new Meta('anthropic', 'test-claude-auto'))]);
    $chat = AgentChat::for($user);
    $turn = $chat->send('How many?');

    expect((float) $turn->cost)->toBe(2.0)
        ->and($chat->history()['turns']['cost'])->toBe(2.0);
});

it('streams the turn state as server-sent events and ends once nothing runs', function () {
    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake(['Two.']);
    $chat = AgentChat::for($user);
    $chat->send('How many?');
    config()->set('packstub-agents.chat.stream_seconds', 5);

    $response = get('/agents/chat/'.$chat->conversation().'/stream')->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
    $body = $response->streamedContent();
    expect($body)->toContain('event: turn')
        ->toContain('"active":null')
        ->toContain('"version":"')
        ->toContain('event: end');

    $poll = get('/agents/chat/'.$chat->conversation().'/turn')->assertOk()->json();
    expect($body)->toContain($poll['version']);

    // Nothing new since the version the client saw: no turn event, just the end.
    $body = get('/agents/chat/'.$chat->conversation().'/stream?version='.$poll['version'])->assertOk()->streamedContent();
    expect($body)->not->toContain('event: turn');

    actingAs($this->user());
    get('/agents/chat/'.$chat->conversation().'/stream')->assertNotFound();
});

it('runs the assistant headlessly as a person, in the same call whatever the driver, and from the console', function () {
    config()->set('packstub-agents.chat.driver', 'queue');
    config()->set('queue.default', 'database'); // never processed: the run must not depend on a worker
    $user = $this->user(['email' => 'ada@example.com']);
    [$alpha] = $this->widgets();
    WidgetAgent::fake([new ToolCall('c1', 'list-widgets', ['filters' => []]), new ToolCall('c2', 'rename-widget', ['id' => $alpha->id, 'name' => 'Alpha II']), 'Two widgets; shall I rename Alpha?']);

    $answer = AgentRun::as($user)->model('fast')->ask('How many, and rename Alpha to Alpha II');

    // The write tool paused the turn: the answer so far is the proposal, the text comes once it is decided.
    expect($answer->ok())->toBeTrue()
        ->and($answer->text)->toBe('')
        ->and($answer->tools())->toBe(['list-widgets', 'rename-widget'])
        ->and($answer->turn->model)->toBe('fast')
        ->and($answer->proposals)->toHaveCount(1)
        ->and($answer->proposals[0])->toMatchArray(['id' => 'c2', 'tool' => 'rename-widget', 'question' => "Rename widget #{$alpha->id} to Alpha II?"])
        ->and((string) $answer)->toBe($answer->text)
        ->and(auth()->check())->toBeFalse() // the run signed the person in for the turn and out again
        ->and(AgentChat::for($user, $answer->conversation)->messages())->toHaveCount(2)
        ->and($alpha->fresh()->name)->toBe('Alpha'); // a proposal waits in the chat

    WidgetAgent::fake(['Three.']);
    $again = AgentRun::as($user)->continuing($answer->conversation)->ask('And now?');
    expect($again->conversation)->toBe($answer->conversation)
        ->and($again->text)->toBe('Three.')
        ->and($again->html())->toBe("<p>Three.</p>\n")
        ->and($again->proposals)->toBeEmpty() // the question declined the waiting proposal
        ->and(AgentChat::for($user, $answer->conversation)->messages())->toHaveCount(4);

    WidgetAgent::fake(['Four.']);
    $this->artisan('packstub-agents:run', ['prompt' => 'How many?', '--user' => 'ada@example.com'])->expectsOutput('Four.')->assertSuccessful();
    WidgetAgent::fake(['Five.']);
    $this->artisan('packstub-agents:run', ['prompt' => 'How many?', '--user' => $user->id, '--json' => true])->expectsOutputToContain('"text": "Five."')->assertSuccessful();
    $this->artisan('packstub-agents:run', ['prompt' => 'How many?', '--user' => 'nobody@example.com'])->assertFailed();
});

it('answers a mail from a person and continues the chat on a reply, ignoring strangers and the wrong secret', function () {
    Mail::fake();
    config()->set('app.url', 'https://widgets.test');
    $user = $this->user(['email' => 'ada@example.com']);

    // Off until a secret is set: the route answers 404.
    expect(EmailChannel::enabled())->toBeFalse();
    postJson('/agents/email', ['from' => 'ada@example.com', 'subject' => 'Widgets', 'text' => 'How many?'])->assertStatus(404);

    config()->set('packstub-agents.email.enabled', true);
    config()->set('packstub-agents.email.secret', 'hook-secret');
    expect(EmailChannel::enabled())->toBeTrue();

    postJson('/agents/email', ['from' => 'ada@example.com', 'subject' => 'Widgets', 'text' => 'How many?'])->assertStatus(401);
    postJson('/agents/email', ['from' => 'ada@example.com', 'subject' => 'Widgets', 'text' => 'How many?'], ['X-Agent-Secret' => 'wrong'])->assertStatus(401);

    // A stranger gets nothing, and the provider is told all is well so it does not retry.
    postJson('/agents/email', ['from' => 'stranger@example.com', 'subject' => 'Widgets', 'text' => 'How many?'], ['X-Agent-Secret' => 'hook-secret'])->assertOk()->assertJson(['answered' => false]);
    Mail::assertNothingSent();

    WidgetAgent::fake(['Two.']);
    $first = postJson('/agents/email', ['From' => 'Ada Lovelace <ada@example.com>', 'Subject' => 'Widgets', 'TextBody' => "How many?\n\nOn Monday, the assistant wrote:\n> earlier", 'MessageID' => '<m1@mail.example>'], ['X-Agent-Secret' => 'hook-secret'])
        ->assertOk()->assertJson(['answered' => true])->json();
    WidgetAgent::assertPrompted(fn ($prompt) => $prompt->prompt === 'How many?');

    Mail::assertSent(AgentAnswerMail::class, function (AgentAnswerMail $mail) use ($first) {
        $rendered = $mail->render();

        return $mail->hasTo('ada@example.com')
            && $mail->envelope()->subject === 'Re: Widgets '.EmailChannel::tag($first['conversation'])
            && $mail->headers()->messageId === EmailChannel::messageId($first['conversation'], $first['turn'])
            && $mail->headers()->references === ['<m1@mail.example>']
            && str_contains($rendered, '<p>Two.</p>')
            && str_contains($rendered, 'Reply to this email');
    });

    // A reply with the subject tag continues the chat; one threaded by Message-ID too.
    WidgetAgent::fake(['Three.']);
    $second = postJson('/agents/email', ['from' => 'ada@example.com', 'subject' => 'Re: Widgets '.EmailChannel::tag($first['conversation']), 'text' => 'And now?'], ['X-Agent-Secret' => 'hook-secret'])->assertOk()->json();
    expect($second['conversation'])->toBe($first['conversation']);

    WidgetAgent::fake(['Four.']);
    $third = postJson('/agents/email', ['from' => 'ada@example.com', 'subject' => 'Something else', 'text' => 'Again?', 'in_reply_to' => '<'.EmailChannel::messageId($first['conversation'], $first['turn']).'>'], ['X-Agent-Secret' => 'hook-secret'])->assertOk()->json();
    expect($third['conversation'])->toBe($first['conversation'])
        ->and(AgentChat::for($user, $first['conversation'])->messages())->toHaveCount(6);

    // Another person's tag opens a new chat of one's own rather than reading theirs.
    $other = $this->user(['email' => 'bob@example.com']);
    WidgetAgent::fake(['Five.']);
    $fourth = postJson('/agents/email', ['from' => 'bob@example.com', 'subject' => 'Re: Widgets '.EmailChannel::tag($first['conversation']), 'text' => 'Mine?'], ['X-Agent-Secret' => 'hook-secret'])->assertOk()->json();
    expect($fourth['conversation'])->not->toBe($first['conversation'])
        ->and(AgentChat::for($other, $fourth['conversation'])->messages())->toHaveCount(2);

    expect(InboundEmail::address('Ada <ADA@Example.com>'))->toBe('ada@example.com')
        ->and(EmailChannel::body(new InboundEmail('a@b.c', 's', "Yes\n-- \nAda")))->toBe('Yes');
});

it('serves the app\'s resources, one record and the starter questions over MCP', function () {
    $user = $this->user();
    [$alpha] = $this->widgets();
    $token = $user->createToken('laptop', ['read'])->plainTextToken;
    $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json, text/event-stream'];
    $rpc = function (string $method, array $params = []) use ($headers) {
        auth()->forgetGuards();

        return postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], $headers)->assertOk();
    };

    expect($rpc('resources/list')->json('result.resources.*.uri'))->toBe(['agents://resources'])
        ->and($rpc('resources/templates/list')->json('result.resourceTemplates.*.uriTemplate'))->toBe(['record://{resource}/{id}'])
        ->and($rpc('prompts/list')->json('result.prompts.*.name'))->toBe(['what-needs-attention', 'ask-about-record']);

    $index = json_decode($rpc('resources/read', ['uri' => 'agents://resources'])->json('result.contents.0.text'), true);
    expect($index['resources'][0]['key'])->toBe('widgets')
        ->and($index['resources'][0]['record_uri'])->toBe('record://widgets/{id}')
        ->and(array_keys($index['resources'][0]['filters']))->toContain('status', 'live_only');

    $record = json_decode($rpc('resources/read', ['uri' => "record://widgets/{$alpha->id}"])->json('result.contents.0.text'), true);
    expect($record)->toMatchArray(['resource' => 'widgets', 'id' => (string) $alpha->id, 'label' => 'Widget Alpha'])
        ->and($record['record']['name'])->toBe('Alpha');

    expect($rpc('resources/read', ['uri' => 'record://widgets/999'])->json('error.message'))->toContain('No widgets record matches 999')
        ->and($rpc('resources/read', ['uri' => 'record://nope/1'])->json('error.message'))->toContain('No resource is called nope');

    $prompt = $rpc('prompts/get', ['name' => 'ask-about-record', 'arguments' => ['resource' => 'widgets', 'id' => (string) $alpha->id]])->json('result.messages.0.content.text');
    expect($prompt)->toContain('About Widget Alpha')->toContain('What is the next step for Widget Alpha?');
    expect($rpc('prompts/get', ['name' => 'what-needs-attention'])->json('result.messages.0.content.text'))->toContain('What needs attention today?');

    // Without agent resources there is nothing to read, and no record prompt.
    Agents::useResources([]);
    expect($rpc('resources/list')->json('result.resources'))->toBe([])
        ->and($rpc('resources/templates/list')->json('result.resourceTemplates'))->toBe([])
        ->and($rpc('prompts/list')->json('result.prompts.*.name'))->toBe(['what-needs-attention']);
});

it('evaluates the agent in a test: which tools it called with which arguments, what it proposed, what it answered', function () {
    $user = $this->user();
    [$alpha] = $this->widgets();

    $result = AgentEval::as($user)
        ->model('fast')
        ->expecting([new ToolCall('c1', 'list-widgets', ['filters' => ['status' => ['live']], 'limit' => 5]), new ToolCall('c2', 'rename-widget', ['id' => $alpha->id, 'name' => 'Alpha II']), 'One live widget: Alpha. Rename it?'])
        ->ask('Rename the live one');

    // The write tool paused the turn: the proposal waits, the text comes once it is decided.
    $result->assertOk()
        ->assertCalled('list-widgets')
        ->assertCalled('list-widgets', ['filters' => ['status' => ['live']]])
        ->assertCalledInOrder(['list-widgets', 'rename-widget'])
        ->assertNotCalled('draw-chart')
        ->assertProposed('rename-widget', ['name' => 'Alpha II'])
        ->assertTurnTools(['list-widgets', 'rename-widget']);

    expect($result->toolCalls()->pluck('name')->all())->toBe(['list-widgets', 'rename-widget'])
        ->and($result->toolCalls()[0]['readOnly'])->toBeTrue()
        ->and($result->toolCalls()[1]['pending'])->toBeTrue()
        ->and($result->proposals())->toHaveCount(1)
        ->and(fn () => $result->assertCalled('list-widgets', ['filters' => ['status' => ['draft']]]))->toThrow(AssertionFailedError::class)
        ->and(fn () => $result->assertNothingProposed())->toThrow(AssertionFailedError::class);

    // Under a faked provider laravel/ai does not resume the approved call (the tool's own logic is tested directly);
    // the decision still reaches the prompt and the answer follows.
    $decided = $result->then()->expecting(['Renamed Alpha.'])->decide('c2', true);
    $decided->assertOk()->assertAnswerContains('renamed alpha')->assertAnswerNotContains('beta');
    WidgetAgent::assertPrompted(fn ($prompt) => $prompt->approvalDecisions?->get('c2')?->isApproved() === true);

    $next = $result->then()->expecting(['Two widgets.'])->ask('How many now?');
    $next->assertOk()->assertNoToolCalls();
    expect($next->chat->conversation())->toBe($result->chat->conversation())
        ->and(AgentChat::for($user, $result->chat->conversation())->messages())->toHaveCount(5); // a decision has no question row

    $refused = AgentEval::as($user)->expecting(['never'])->ask(str_repeat('x', 5000));
    $refused->assertRefused('characters')->assertFailed();
});
