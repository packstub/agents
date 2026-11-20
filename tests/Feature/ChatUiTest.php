<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Chat\ChatAssets;
use Packstub\Agents\Chat\ChatParts;
use Packstub\Agents\Chat\ChatRoutes;
use Packstub\Agents\Chat\ChatView;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentMessageFeedback;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use Packstub\Agents\Tests\Fixtures\WidgetResource;
use Packstub\Agents\Tests\Fixtures\WidgetServer;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

// The chat on a page of a plain Laravel app: Blade, plain HTTP and Alpine — no Filament, no Livewire.
beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::useServer(WidgetServer::class);
    Agents::useResources([WidgetResource::class]);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
});

/** The part keys and revisions a page would send back, read from rendered HTML. */
function partsOf(string $html): array
{
    preg_match_all('/data-key="([^"]+)" data-rev="([^"]+)"/', $html, $found);

    return array_combine($found[1], $found[2]);
}

it('renders a new chat on the package\'s page with its assets, and no Livewire or Filament in the markup', function () {
    actingAs($this->user());

    $html = get('/agents/chat')->assertOk()->getContent();

    expect($html)
        ->toContain('data-agents-chat')
        ->toContain('x-data="agentChat()"')
        ->toContain('fi-chat-plain')
        ->toContain('New chat')
        ->toContain('Ask Widgets')
        ->toContain('What needs attention today?') // a starter question
        ->toContain('agents/assets/agents-chat.css')
        ->toContain('agents/assets/agents-chat.js')
        ->toContain('data-act="'.e(route('packstub-agents.act', ['action' => '__action__'])).'"')
        ->toContain('<svg') // the package's own icons
        ->not->toContain('wire:')
        ->not->toContain('x-load')
        ->not->toContain('fi-btn');
});

it('sends a question over HTTP, answers with the turn and the chat rendered again, and moves the page to the chat\'s URL', function () {
    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake(['Two widgets are live.']);

    $response = postJson('/agents/chat/act/send', ['prompt' => 'How many widgets are live?'])->assertOk();
    $conversation = Conversation::query()->where('participant_id', $user->id)->sole();

    expect($response->json('turn'))->toMatchArray([
        'conversation' => $conversation->id,
        'active' => false, // the sync queue ran it inside the request
        'poll' => route('packstub-agents.turn', ['conversation' => $conversation->id]),
        'stream' => route('packstub-agents.stream', ['conversation' => $conversation->id]),
    ])
        ->and($response->json('url'))->toBe(route('packstub-agents.chat', ['conversation' => $conversation->id]))
        ->and($response->json('notices'))->toBe([])
        ->and($response->json('html'))->toContain('How many widgets are live?')->toContain('Two widgets are live')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    // The chat's own page shows the same thing, with the export link and the conversation in its state.
    get('/agents/chat/'.$conversation->id)->assertOk()
        ->assertSee('Two widgets are live')
        ->assertSee('data-conversation="'.$conversation->id.'"', escape: false)
        ->assertSee(route('packstub-agents.export', ['conversation' => $conversation->id]));

    // An empty question does nothing.
    expect(postJson('/agents/chat/act/send', ['prompt' => '  ', 'conversation' => $conversation->id])->assertOk()->json('turn'))->toBeNull()
        ->and(ConversationMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(2);
});

it('keeps one person\'s chat from another on every endpoint, and guests out', function () {
    $owner = $this->user();
    $id = conversationWith($owner, [['role' => 'user', 'content' => 'Mine'], ['content' => 'Yours truly.']]);

    actingAs($this->user());

    get('/agents/chat/'.$id)->assertNotFound();
    get('/agents/chat/'.$id.'/export')->assertNotFound();
    get('/agents/chat/'.$id.'/turn')->assertNotFound();
    postJson('/agents/chat/view', ['conversation' => $id])->assertNotFound();

    foreach (['send', 'resend', 'regenerate', 'continue', 'retry', 'decide', 'stop', 'queued-edit', 'queued-remove', 'feedback', 'version', 'rename', 'pin', 'compress', 'continue-in-new'] as $action) {
        postJson('/agents/chat/act/'.$action, ['conversation' => $id, 'prompt' => 'Let me in', 'title' => 'Taken'])->assertNotFound();
    }

    expect(Conversation::query()->find($id)->title)->toBe('Renames')
        ->and(ConversationMessage::query()->where('conversation_id', $id)->count())->toBe(2);

    postJson('/agents/chat/act/nonsense')->assertNotFound();

    auth()->logout();
    postJson('/agents/chat/act/send', ['prompt' => 'Hello'])->assertUnauthorized();
    postJson('/agents/chat/view')->assertUnauthorized();
    $this->getJson('/agents/chat/mentions')->assertUnauthorized();
});

it('leaves the parts the page already shows out of a refresh, and renders a part again when it changed', function () {
    $user = $this->user();
    actingAs($user);
    $id = conversationWith($user, [['role' => 'user', 'content' => 'How many widgets are live?'], ['content' => 'Two widgets are live.']]);
    $answer = ConversationMessage::query()->where('conversation_id', $id)->where('role', 'assistant')->sole();

    $html = postJson('/agents/chat/view', ['conversation' => $id])->assertOk()->json('html');
    $have = partsOf($html);

    expect($have)->toHaveKeys(['header', 'message-'.$answer->id])
        ->and($html)->toContain('Two widgets are live')->not->toContain('data-agents-stub');

    // Nothing changed: the messages and the header come back as stubs, without their bodies.
    $again = postJson('/agents/chat/view', ['conversation' => $id, 'have' => $have])->assertOk()->json('html');

    expect($again)->not->toContain('Two widgets are live')
        ->toContain('data-key="message-'.$answer->id.'" data-rev="'.$have['message-'.$answer->id].'" data-agents-stub')
        ->toContain('data-key="header" data-rev="'.$have['header'].'" data-agents-stub')
        ->toContain('x-ref="state"'); // the state and the composer are always there

    // A rating changes that answer only: it is rendered again, the question stays a stub.
    $rated = postJson('/agents/chat/act/feedback', ['conversation' => $id, 'message' => $answer->id, 'rating' => 'up', 'have' => $have])->assertOk()->json('html');

    expect(AgentMessageFeedback::query()->where('message_id', $answer->id)->value('rating'))->toBe('up')
        ->and($rated)->toContain('Two widgets are live')->toContain('fi-chat-rated')
        ->and(substr_count($rated, 'data-agents-stub'))->toBe(2) // the header and the question
        ->and(partsOf($rated)['message-'.$answer->id])->not->toBe($have['message-'.$answer->id]);

    // A thumbs-down keeps its note.
    postJson('/agents/chat/act/feedback', ['conversation' => $id, 'message' => $answer->id, 'rating' => 'down', 'note' => 'Counted the drafts too'])->assertOk()->assertSee('Counted the drafts too');
});

it('renames, pins and exports a chat', function () {
    $user = $this->user();
    actingAs($user);
    $id = conversationWith($user, [['role' => 'user', 'content' => 'How many widgets are live?'], ['content' => 'Two widgets are live.']]);

    expect(postJson('/agents/chat/act/rename', ['conversation' => $id, 'title' => 'Live widgets'])->assertOk()->json('html'))->toContain('data-title="Live widgets"')
        ->and(Conversation::query()->find($id)->title)->toBe('Live widgets');

    expect(postJson('/agents/chat/act/pin', ['conversation' => $id])->assertOk()->json('html'))->toContain('fi-chat-header-tool-on')
        ->and(AgentChat::for($user, $id)->pinned())->toBeTrue()
        ->and(postJson('/agents/chat/act/pin', ['conversation' => $id])->assertOk()->json('html'))->not->toContain('fi-chat-header-tool-on');

    // An earlier answer that does not exist is said in a notice, not an error.
    expect(postJson('/agents/chat/act/version', ['conversation' => $id, 'question' => 'none', 'version' => 1])->assertOk()->json('notices.0.level'))->toBe('warning');

    $export = get('/agents/chat/'.$id.'/export')->assertOk();

    expect($export->headers->get('Content-Disposition'))->toContain('live-widgets.md')
        ->and($export->streamedContent())->toContain('Two widgets are live');
});

it('approves a proposed change through the decide action', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();

    $id = conversationWith($user, [
        ['role' => 'user', 'content' => 'Rename Alpha'],
        ['tool_calls' => [['id' => 'c1', 'name' => 'rename-widget', 'arguments' => ['id' => $alpha->id, 'name' => 'Alpha II']]], 'approval_state' => ['pending' => ['c1' => 'Rename Alpha?']]],
    ]);

    // The card waits with its two buttons, drawn by the package's own components.
    expect(postJson('/agents/chat/view', ['conversation' => $id])->json('html'))
        ->toContain('fi-chat-proposal-pending')
        ->toContain('decide(&#039;c1&#039;, true)')
        ->toContain('fi-chat-ui-btn');

    WidgetAgent::fake(['Renamed.']);
    $response = postJson('/agents/chat/act/decide', ['conversation' => $id, 'call' => 'c1', 'approve' => true])->assertOk();

    expect($response->json('turn.id'))->not->toBeNull()
        ->and($response->json('url'))->toBeNull() // not a new chat: the address stays
        ->and($alpha->fresh()->name)->toBe('Alpha II')
        ->and($response->json('html'))->toContain('fi-chat-proposal-approved')->toContain('Renamed.');
});

it('edits and removes a waiting question, and stops the running turn', function () {
    $user = $this->user();
    actingAs($user);
    Queue::fake();

    $first = postJson('/agents/chat/act/send', ['prompt' => 'First'])->assertOk();
    $id = $first->json('turn.conversation');

    expect($first->json('turn.active'))->toBeTrue();

    $second = postJson('/agents/chat/act/send', ['prompt' => 'Second', 'conversation' => $id])->assertOk();
    $third = postJson('/agents/chat/act/send', ['prompt' => 'Third', 'conversation' => $id])->assertOk();

    expect($third->json('html'))->toContain('data-agents-queued')->toContain('Second')->toContain('Third');

    // Edit hands the text back to the composer and takes the question out of the line.
    expect(postJson('/agents/chat/act/queued-edit', ['conversation' => $id, 'turn' => $second->json('turn.id')])->assertOk()->json('text'))->toBe('Second')
        ->and(postJson('/agents/chat/act/queued-remove', ['conversation' => $id, 'turn' => $third->json('turn.id')])->assertOk()->json('html'))->not->toContain('Third')
        ->and(AgentTurn::query()->forConversation($id)->where('status', AgentTurn::QUEUED)->count())->toBe(0);

    postJson('/agents/chat/act/stop', ['conversation' => $id])->assertOk();

    expect(app(AgentTurns::class)->stopRequested(AgentTurn::query()->find($first->json('turn.id'))))->toBeTrue();
});

it('says in a notice why a question could not be answered', function () {
    $user = $this->user();
    actingAs($user);

    // No provider key: nothing is sent, and the page is told.
    config()->set('packstub-agents.enabled', false);

    $off = postJson('/agents/chat/act/send', ['prompt' => 'Hello'])->assertOk();

    expect($off->json('turn'))->toBeNull()
        ->and($off->json('notices.0'))->toMatchArray(['level' => 'warning', 'title' => 'Ask Widgets is not connected to an AI provider yet.'])
        ->and(Conversation::query()->count())->toBe(0);

    // The provider fails: the question is kept and the notice stays until it is read.
    config()->set('packstub-agents.enabled', true);
    WidgetAgent::fake(fn () => throw new RuntimeException('The provider is down'));

    $failed = postJson('/agents/chat/act/send', ['prompt' => 'Hello'])->assertOk();

    expect($failed->json('notices.0'))->toMatchArray(['level' => 'danger', 'title' => 'The assistant could not answer', 'persistent' => true])
        ->and($failed->json('notices.0.body'))->toContain('Your question is kept')
        ->and($failed->json('notices'))->toHaveCount(1) // the first request's notice did not stay with the controller
        ->and($failed->json('html'))->toContain('fi-chat-unanswered')->toContain('retry(');
});

it('takes the files of a question with it, within the limits', function () {
    Storage::fake('local');
    config()->set('packstub-agents.chat.attachments.disk', 'local');
    config()->set('packstub-agents.chat.attachments.max_files', 2);

    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake(['A fine chart.', 'Three is one too many.']);

    // A multipart body: the page's parts and the mentions travel as JSON strings.
    $response = post('/agents/chat/act/send', [
        'prompt' => 'What is in this file?',
        'have' => json_encode(['header' => 'abc']),
        'mentions' => json_encode([]),
        'files' => [UploadedFile::fake()->image('chart.png', 20, 20)],
    ], ['Accept' => 'application/json'])->assertOk();

    expect($response->json('html'))->toContain('fi-chat-attachment')->toContain('chart.png')
        ->and(Storage::disk('local')->allFiles('agent-attachments'))->toHaveCount(1);

    // The composer shows the attach button and says what it accepts.
    get('/agents/chat')->assertSee('data-max-files="2"', escape: false)->assertSee('image/png');

    // One file more than a question takes: the extra one is left out.
    post('/agents/chat/act/send', ['prompt' => 'And these?', 'conversation' => $response->json('turn.conversation'), 'files' => [
        UploadedFile::fake()->image('a.png', 20, 20), UploadedFile::fake()->image('b.png', 20, 20), UploadedFile::fake()->image('c.png', 20, 20),
    ]], ['Accept' => 'application/json'])->assertOk();

    expect(Storage::disk('local')->allFiles('agent-attachments'))->toHaveCount(3);

    // A type the chat does not take: nothing is sent, the notice says why.
    $refused = post('/agents/chat/act/send', ['prompt' => 'Run this', 'files' => [UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload')]], ['Accept' => 'application/json'])->assertOk();

    expect($refused->json('turn'))->toBeNull()
        ->and($refused->json('notices.0.level'))->toBe('warning');

    // With attachments off the button is gone and files are ignored.
    config()->set('packstub-agents.chat.attachments.enabled', false);
    get('/agents/chat')->assertDontSee('data-max-files', escape: false);
});

it('opens a chat with a question waiting in the composer, or sent on load when the app left it in the session', function () {
    actingAs($this->user());

    // From the URL: it only waits (anyone can craft a link).
    get('/agents/chat?prompt='.urlencode('How many widgets are live?'))->assertOk()
        ->assertSee('data-prompt="How many widgets are live?"', escape: false)
        ->assertSee('data-auto-send=""', escape: false);

    // From the session: the app asked for it, so it is sent.
    session()->put('packstub-agents.prompt', 'Summarize today');

    get('/agents/chat')->assertOk()
        ->assertSee('data-prompt="Summarize today"', escape: false)
        ->assertSee('data-auto-send="1"', escape: false);

    expect(session()->has('packstub-agents.prompt'))->toBeFalse();
});

it('continues a long chat in a new one and sends the page there', function () {
    $user = $this->user();
    actingAs($user);
    $id = conversationWith($user, [
        ['role' => 'user', 'content' => 'Rename widget 1 to Alpha'],
        ['content' => 'Widget 1 is now Alpha.'],
        ['role' => 'user', 'content' => 'How many widgets are live?'],
        ['content' => 'Two widgets are live.'],
    ]);

    WidgetAgent::fake(['Alpha, two live widgets.']);
    $response = postJson('/agents/chat/act/continue-in-new', ['conversation' => $id])->assertOk();
    $new = Conversation::query()->where('id', '!=', $id)->sole();

    expect($response->json('redirect'))->toBe(route('packstub-agents.chat', ['conversation' => $new->id]))
        ->and($response->json('html'))->toBeNull();

    // The new chat says where it came from, linked to the first one.
    get('/agents/chat/'.$new->id)->assertOk()->assertSee('Continued from')->assertSee(route('packstub-agents.chat', ['conversation' => $id]));

    // Compress now answers with a notice either way.
    WidgetAgent::fake(['Widget 1 was renamed.']);

    expect(postJson('/agents/chat/act/compress', ['conversation' => $id])->assertOk()->json('notices.0.level'))->toBe('success');
});

it('lists the person\'s chats, pinned first, and searches titles and messages', function () {
    $user = $this->user();
    actingAs($user);
    $old = conversationWith($user, [['role' => 'user', 'content' => 'About invoices'], ['content' => 'Three are overdue.']], 'Invoices');
    $new = conversationWith($user, [['role' => 'user', 'content' => 'About widgets'], ['content' => 'Two widgets are live.']], 'Widgets');
    conversationWith($this->user(), [['role' => 'user', 'content' => 'Private'], ['content' => 'Nobody else reads this.']], 'Someone else');
    Conversation::query()->whereKey($old)->update(['updated_at' => now()->subDay()]);

    get('/agents/chats')->assertOk()->assertSeeInOrder(['Widgets', 'Invoices'])->assertDontSee('Someone else');

    AgentChat::for($user, $old)->pin();

    get('/agents/chats')->assertOk()->assertSeeInOrder(['Invoices', 'Widgets']);
    get('/agents/chats?q=overdue')->assertOk()->assertSee('Invoices')->assertDontSee('Widgets');
    get('/agents/chats?q=nothing-like-this')->assertOk()->assertSee('No chat matches.');
});

it('serves the built stylesheet and scripts, and nothing else', function () {
    foreach (array_keys(ChatAssets::FILES) as $file) {
        expect(is_file(ChatAssets::path($file)))->toBeTrue("resources/dist/{$file} is missing: run npm run build");
    }

    $css = get(ChatAssets::url('agents-chat.css'))->assertOk();

    expect($css->headers->get('Content-Type'))->toContain('text/css')
        ->and($css->headers->get('Cache-Control'))->toContain('immutable')
        ->and(get(ChatAssets::url('agents-chat.js'))->assertOk()->headers->get('Content-Type'))->toContain('javascript');

    get('/agents/assets/composer.json')->assertNotFound();
    get('/agents/assets/..%2F..%2Fcomposer.json')->assertNotFound();
});

it('gives a part a revision from what it is drawn from, and a stub for one the page has', function () {
    $parts = new ChatParts;

    expect($parts->begin('header'))->toBeTrue();
    echo '<div class="fi-chat-header">Title</div>';
    $html = $parts->end();

    expect($html)->toMatch('/^<div data-key="header" data-rev="[0-9a-f]{12}" class="fi-chat-header">Title<\/div>$/');

    // The page has this revision: a part with an explicit one is not even rendered.
    $parts->known(['message-1' => ChatParts::rev('same')]);

    expect($parts->begin('message-1', ChatParts::rev('same')))->toBeFalse()
        ->and($parts->end())->toBe('<div data-key="message-1" data-rev="'.ChatParts::rev('same').'" data-agents-stub></div>')
        ->and($parts->begin('message-1', ChatParts::rev('changed')))->toBeTrue();

    echo '<div>New</div>';

    expect($parts->end())->toContain('>New</div>');

    // A part that draws nothing leaves nothing.
    $parts->begin('suggestions');

    expect($parts->end())->toBe('');
});

it('renders the chat in a view of the app\'s own with the component', function () {
    $user = $this->user();
    actingAs($user);
    $id = conversationWith($user, [['role' => 'user', 'content' => 'How many widgets are live?'], ['content' => 'Two widgets are live.']]);

    $html = Blade::render('<x-agents-chat :conversation="$id" context="widgets/1" embedded />', ['id' => $id]);

    expect($html)->toContain('Two widgets are live')
        ->toContain('fi-chat-embedded')
        ->toContain('Open full page')
        ->toContain('agents-chat.css');

    // Without its assets, for a layout that loads them itself.
    expect(Blade::render('<x-agents-chat :assets="false" />'))->not->toContain('agents-chat.css')->toContain('data-agents-chat');

    // The view object is the same thing a controller of the app's own would use.
    expect(ChatView::make($id)->title())->toBe('Renames')
        ->and(fn () => ChatView::make((string) Str::uuid7()))->toThrow(ModelNotFoundException::class);
});

it('keeps the chat script free of Livewire and its deferred work off a click handler\'s context', function () {
    // Alpine evaluates a click handler with `this` bound to the clicked element: once a morph has removed it (Retry,
    // Regenerate, Approve, a queued question's Edit) its $root is gone. So timers, the pump and continuations after
    // an await go through the root context (`self`).
    $script = file_get_contents(ChatAssets::path('../js/agent-chat.js'));

    expect($script)
        ->toContain('self = this')
        ->not->toContain('$wire')
        ->not->toContain('Livewire.')
        ->not->toMatch('/setTimeout\(\(\) => this\./')
        ->toContain('self.revealed().then(() => self.refresh())')
        ->toContain('self.timer = setTimeout(() => self.poll(), delay)')
        // The stream while a turn runs, the poll when it cannot be held open.
        ->toContain('new EventSource(this.streamUrl')
        ->toContain("source.addEventListener('turn'")
        ->toContain("source.addEventListener('end'")
        ->toContain('self.streamFailures++')
        // A link's question waits in the composer instead of the saved draft; drafts are kept per conversation.
        ->toContain("} else if (state.prompt.trim() !== '') {")
        ->toContain('packstub-agents:draft:')
        ->toContain('/(?:^|\\s)@([^\\s@]*)$/')
        // Code blocks get their copy button as they render or stream in.
        ->toContain("querySelectorAll('.fi-chat-md pre:not([data-decorated])')")
        ->toContain('navigator.clipboard.writeText');
});

it('reads its route parameters by name, so the endpoints work under a prefix with a parameter of its own', function () {
    // A panel with tenancy registers them under {tenant}: the first route parameter is then the workspace.
    Route::middleware(['web', 'auth'])->prefix('w/{tenant}')->name('w.')->group(fn () => ChatRoutes::endpoints('assistant'));

    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake(['Two widgets are live.']);

    $id = postJson('/w/acme/assistant/chat/act/send', ['prompt' => 'How many widgets are live?'])->assertOk()->json('turn.conversation');

    expect($id)->not->toBeNull();

    postJson('/w/acme/assistant/chat/act/pin', ['conversation' => $id])->assertOk();
    postJson('/w/acme/assistant/chat/view', ['conversation' => $id])->assertOk()->assertSee('Two widgets are live');
    get('/w/acme/assistant/chat/'.$id.'/export')->assertOk();
    get('/w/acme/assistant/chat/'.$id.'/turn')->assertOk();
    postJson('/w/acme/assistant/chat/act/nonsense')->assertNotFound();
});
