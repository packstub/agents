<?php

use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Mcp\Request;
use Packstub\Agents\Ai\Middleware\SupportedProviderTools;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Mcp\Tools\DrawChart;
use Packstub\Agents\Mcp\Tools\SearchKnowledgeBase;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\KnowledgeBase;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Models\Article;
use Packstub\Agents\Tests\Fixtures\Tools\ListWidgets;
use Packstub\Agents\Tests\Fixtures\Tools\RenameWidget;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use Packstub\Agents\Tests\Fixtures\WidgetResource;
use Packstub\Agents\Tests\Fixtures\WidgetServer;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\postJson;

// Beyond the records: the app's own documents (a knowledge base the assistant searches and cites) and the web
// (a provider-run search, held to an allow-list and kept apart from the workspace's data).
beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::useServer(WidgetServer::class);
    Agents::useResources([WidgetResource::class]);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
    Article::$searches = [];
});

function articles(): array
{
    return [
        Article::query()->create(['title' => 'Refund policy', 'body' => 'A refund is paid within 14 days of the return arriving.', 'link' => 'https://help.widgets.test/refunds', 'embedding' => [0.1, 0.2]]),
        Article::query()->create(['title' => 'Retiring a widget', 'body' => 'Open the widget, choose Retire, confirm. Retired widgets stay in reports.', 'embedding' => [0.3, 0.4]]),
        Article::query()->create(['title' => 'Refund fraud checklist', 'body' => 'Internal draft about refund abuse.', 'published' => false, 'embedding' => [0.5, 0.6]]),
        Article::query()->create(['title' => 'Refunds for resellers', 'body' => 'Not embedded yet: refund terms for resellers.']),
    ];
}

it('serves no knowledge tool and says nothing about one until a knowledge base is registered', function () {
    actingAs($this->user());

    expect(Agents::knowledge())->toBeNull()
        ->and(Agents::optInTools())->toBe([])
        ->and(Agents::toolClasses())->not->toContain(SearchKnowledgeBase::class)
        ->and(app(SearchKnowledgeBase::class)->eligibleForRegistration())->toBeFalse()
        ->and(app(SearchKnowledgeBase::class)->handle(new Request(['query' => 'refunds']))->isError())->toBeTrue()
        ->and(Agents::agent()->instructions())->not->toContain('knowledge base');
});

it('searches the registered documents by similarity, returns them to cite, and joins the tool list of the chat and of MCP', function () {
    $user = $this->user();
    actingAs($user);
    articles();

    Agents::knowledgeBase(Article::class, 'embedding', content: 'body', url: 'link', minSimilarity: 0.4, limit: 3, query: fn ($query) => $query->where('published', true));

    // The tool joins the server's own list, for the chat (as a read tool, no approval) and for MCP clients.
    $tools = collect(Agents::agent()->tools())->keyBy(fn ($tool) => $tool->name());
    expect(Agents::toolClasses())->toBe([ListWidgets::class, RenameWidget::class, DrawChart::class, SearchKnowledgeBase::class])
        ->and($tools['search-knowledge-base'])->toBeInstanceOf(McpServerTool::class)
        ->and(Agents::agent()->instructions())->toContain('search the knowledge base first', 'citing each article you used by its title');

    $token = $user->createToken('laptop', ['read'])->plainTextToken;
    $listed = postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'])
        ->assertOk()
        ->json('result.tools.*.name');
    expect($listed)->toContain('list-widgets', 'search-knowledge-base');
    auth()->forgetGuards();
    actingAs($user);

    // The published documents that have an embedding and are close to the question, with the url where there is one.
    $payload = json_decode((string) app(SearchKnowledgeBase::class)->handle(new Request(['query' => 'How long does a refund take?']))->content(), true);

    expect($payload['total'])->toBe(1)
        ->and($payload['articles'])->toBe([['title' => 'Refund policy', 'url' => 'https://help.widgets.test/refunds', 'content' => 'A refund is paid within 14 days of the return arriving.']])
        ->and($payload['note'])->toContain('cite each one you used by its title')
        ->and(Article::$searches)->toBe([['embedding', 'How long does a refund take?', 0.4]]);

    // An article without a page has no url; nothing found says so, for the model to say so too.
    $retire = json_decode((string) app(SearchKnowledgeBase::class)->handle(new Request(['query' => 'retire widget', 'limit' => 1]))->content(), true);
    $nothing = json_decode((string) app(SearchKnowledgeBase::class)->handle(new Request(['query' => 'shipping zones']))->content(), true);

    expect($retire['articles'])->toHaveCount(1)
        ->and($retire['articles'][0])->toBe(['title' => 'Retiring a widget', 'content' => 'Open the widget, choose Retire, confirm. Retired widgets stay in reports.'])
        ->and($nothing)->toMatchArray(['articles' => [], 'total' => 0])
        ->and($nothing['note'])->toContain('Nothing in the knowledge base matches')
        ->and(app(SearchKnowledgeBase::class)->handle(new Request(['query' => ' ']))->isError())->toBeTrue();

    // In a turn: the model searches, then answers from what came back.
    WidgetAgent::fake([new ToolCall('c1', 'search-knowledge-base', ['query' => 'refund']), 'Refunds are paid within 14 days ([Refund policy](https://help.widgets.test/refunds)).']);
    $chat = AgentChat::for($user);
    expect($chat->send('How long does a refund take?')->status)->toBe(AgentTurn::DONE)
        ->and($chat->messages()[1]['tools'][0])->toMatchArray(['tool' => 'search-knowledge-base', 'readOnly' => true])
        ->and($chat->messages()[1]['tools'][0]['result'])->toContain('Refund policy');
});

it('gates the knowledge base by its ability, reads closures for the fields, and takes a search of the app\'s own', function () {
    actingAs($this->user());
    [$refunds] = articles();

    Agents::knowledgeBase(
        Article::class,
        title: fn (Article $article) => Str::upper($article->title),
        content: fn (Article $article) => $article->body,
        url: fn (Article $article) => 'https://kb.test/'.$article->id,
        ability: 'kb.view',
    );
    $knowledge = Agents::knowledge();

    expect($knowledge->search('refund')->first())->toBe(['title' => 'REFUND POLICY', 'url' => 'https://kb.test/'.$refunds->id, 'content' => 'A refund is paid within 14 days of the return arriving.'])
        ->and($knowledge->text($refunds))->toBe("REFUND POLICY\n\nA refund is paid within 14 days of the return arriving.")
        ->and(Article::$searches[0])->toBe(['embedding', 'refund', 0.5]);

    Abilities::$allowed = ['widgets.view'];
    expect(app(SearchKnowledgeBase::class)->eligibleForRegistration())->toBeFalse()
        ->and(app(SearchKnowledgeBase::class)->handle(new Request(['query' => 'refund']))->isError())->toBeTrue()
        ->and(collect(Agents::agent()->tools())->map(fn ($tool) => $tool->name())->all())->not->toContain('search-knowledge-base')
        ->and(Agents::agent()->instructions())->not->toContain('knowledge base');
    Abilities::reset();

    // A search of the app's own (Scout, a search API): models or arrays, cut to the limit and to an excerpt.
    $asked = [];
    Agents::knowledgeBase(limit: 2, using: function (string $question, int $limit) use (&$asked) {
        $asked[] = [$question, $limit];

        return [
            ['title' => 'Returns', 'content' => str_repeat('Long text. ', 300), 'url' => 'https://kb.test/returns'],
            ['title' => 'Shipping', 'content' => 'Two days.'],
            ['title' => 'Third', 'content' => 'Over the limit.'],
            'not a document',
        ];
    });

    $found = Agents::knowledge()->search('returns');
    expect($asked)->toBe([['returns', 2]])
        ->and($found)->toHaveCount(2)
        ->and(strlen($found[0]['content']))->toBeLessThan(1600)
        ->and($found[1])->toBe(['title' => 'Shipping', 'content' => 'Two days.'])
        ->and(Agents::optInTools())->toBe([SearchKnowledgeBase::class]);
});

it('reads the knowledge base from config, and embeds its documents from the console', function () {
    actingAs($this->user());
    articles();

    artisan('packstub-agents:embed')->expectsOutputToContain('No knowledge base model is registered')->assertFailed();

    config(['packstub-agents.knowledge_base' => ['model' => Article::class, 'content' => 'body', 'url' => 'link', 'limit' => 2] + config('packstub-agents.knowledge_base')]);
    $knowledge = Agents::knowledge();

    expect($knowledge)->toBeInstanceOf(KnowledgeBase::class)
        ->and($knowledge->searchable())->toBeTrue()
        ->and([$knowledge->column, $knowledge->limit, $knowledge->minSimilarity])->toBe(['embedding', 2, 0.5])
        ->and(Agents::toolClasses())->toContain(SearchKnowledgeBase::class);

    // Only the documents without an embedding go to the provider, title and content together, in one request.
    $sent = [];
    Embeddings::fake(function ($prompt) use (&$sent) {
        $sent[] = $prompt->inputs;

        return array_map(fn () => [0.9, 0.8, 0.7], $prompt->inputs);
    });

    artisan('packstub-agents:embed')->expectsOutputToContain('Embedded 1 document.')->assertSuccessful();

    expect($sent)->toBe([["Refunds for resellers\n\nNot embedded yet: refund terms for resellers."]])
        ->and(Article::query()->where('title', 'Refunds for resellers')->first()->embedding)->toBe([0.9, 0.8, 0.7])
        ->and(Article::query()->where('title', 'Refund policy')->first()->embedding)->toBe([0.1, 0.2]);

    artisan('packstub-agents:embed')->expectsOutputToContain('Nothing to embed')->assertSuccessful();

    // --fresh embeds every document again, in chunks.
    $sent = [];
    artisan('packstub-agents:embed', ['--fresh' => true, '--chunk' => 3])->expectsOutputToContain('Embedded 4 documents.')->assertSuccessful();
    expect(array_map('count', $sent))->toBe([3, 1])
        ->and(Article::query()->where('title', 'Refund policy')->first()->embedding)->toBe([0.9, 0.8, 0.7]);
});

it('hands the chat a file search over the provider\'s vector stores, without a tool of its own', function () {
    actingAs($this->user());

    Agents::knowledgeBase(stores: ['vs_handbook', 'vs_policies']);
    $provided = collect(Agents::agent()->tools())->first(fn ($tool) => $tool instanceof FileSearch);

    expect(Agents::knowledge()->searchable())->toBeFalse()
        ->and(Agents::optInTools())->toBe([])
        ->and($provided)->toBeInstanceOf(FileSearch::class)
        ->and($provided->ids())->toBe(['vs_handbook', 'vs_policies'])
        ->and(Agents::agent()->instructions())->toContain('search the knowledge base first');

    config(['packstub-agents.knowledge_base.stores' => ['vs_from_config']]);
    expect(KnowledgeBase::fromConfig()->stores)->toBe(['vs_from_config']);
});

it('adds the provider\'s web search when switched on, held to the allow-list, with the rule that keeps web and workspace apart', function () {
    $user = $this->user();
    actingAs($user);

    expect(collect(Agents::agent()->tools())->contains(fn ($tool) => $tool instanceof WebSearch))->toBeFalse()
        ->and(Agents::agent()->instructions())->not->toContain('Web search');

    config(['packstub-agents.web_search' => ['enabled' => true, 'allow' => ['docs.widgets.test', 'laravel.com'], 'max' => 2, 'location' => ['city' => null, 'region' => null, 'country' => 'RO']]]);
    $search = collect(Agents::agent()->tools())->first(fn ($tool) => $tool instanceof WebSearch);

    expect($search)->toBeInstanceOf(WebSearch::class)
        ->and($search->maxSearches)->toBe(2)
        ->and($search->allowedDomains)->toBe(['docs.widgets.test', 'laravel.com'])
        ->and([$search->city, $search->country])->toBe([null, 'RO'])
        ->and(Agents::agent()->instructions())->toContain('Web search is for public information', 'Keep the workspace\'s data and what you found on the web apart')
        ->and(collect(Agents::agent()->tools())->filter(fn ($tool) => method_exists($tool, 'name'))->map(fn ($tool) => $tool->name())->values()->all())->toBe(['list-widgets', 'rename-widget', 'draw-chart']); // the app's tools are as they were

    // A turn runs with the provider tool on its list.
    WidgetAgent::fake(['According to docs.widgets.test, widgets ship in two days.']);
    expect(AgentChat::for($user)->send('How fast do widgets ship?')->status)->toBe(AgentTurn::DONE);

    // Each step keeps the provider tools its own provider runs: a failover to a provider without web search answers without it.
    Agents::knowledgeBase(stores: ['vs_1']);
    $tools = [...Agents::agent()->tools()];
    $step = fn (string $provider) => new PendingStep(0, false, $provider, 'a-model', null, [], $tools, null, null);
    $kept = fn (string $provider) => collect((new SupportedProviderTools)->handle($step($provider), fn (PendingStep $s) => $s)->tools)->map(fn ($tool) => class_basename($tool))->all();

    expect($kept('openai'))->toBe(['McpServerTool', 'ApprovableTool', 'McpServerTool', 'WebSearch', 'FileSearch'])
        ->and($kept('anthropic'))->toBe(['McpServerTool', 'ApprovableTool', 'McpServerTool', 'WebSearch']) // no file search on Anthropic
        ->and($kept('ollama'))->toBe(['McpServerTool', 'ApprovableTool', 'McpServerTool']);
});

it('lists the pages an answer cites and the searches the provider ran, and says on the status line that it is searching', function () {
    $user = $this->user();
    actingAs($user);

    $id = conversationWith($user, [
        ['role' => 'user', 'content' => 'What is the VAT rate in Romania?'],
        [
            'content' => 'According to the ministry, it is 21%.',
            'meta' => ['provider' => 'anthropic', 'citations' => [
                ['url' => 'https://mfinante.gov.ro/tva', 'title' => 'TVA'],
                ['url' => 'https://mfinante.gov.ro/tva', 'title' => 'TVA again'],
                ['url' => 'https://example.com/vat', 'title' => ''],
                ['url' => 'javascript:alert(1)', 'title' => 'Bad'],
            ]],
            'steps' => [['content' => 'According to the ministry, it is 21%.', 'reasoning' => '', 'replay_blocks' => [], 'tool_calls' => [], 'provider_tool_calls' => [
                ['id' => 'srv_1', 'type' => 'server_tool_use', 'data' => ['type' => 'server_tool_use', 'id' => 'srv_1', 'name' => 'web_search', 'input' => ['query' => 'Romania VAT rate 2026']]],
                ['id' => 'srv_1', 'type' => 'web_search_tool_result', 'data' => ['type' => 'web_search_tool_result', 'tool_use_id' => 'srv_1', 'content' => []]],
                ['id' => 'ws_2', 'type' => 'web_search_call', 'data' => ['action' => ['query' => 'TVA România']]],
            ]]],
            'status' => MessageStatus::Completed,
        ],
    ]);

    $answer = AgentChat::for($user, $id)->messages()[1];

    expect($answer['sources'])->toBe([
        ['title' => 'TVA', 'url' => 'https://mfinante.gov.ro/tva'],
        ['title' => 'example.com', 'url' => 'https://example.com/vat'],
    ])
        ->and(collect($answer['tools'])->map(fn (array $tool) => [$tool['name'], $tool['arguments'], $tool['readOnly']])->all())->toBe([
            ['Web Search', ['query' => 'Romania VAT rate 2026'], true],
            ['Web Search', ['query' => 'TVA România'], true],
        ])
        ->and(AgentChat::for($user, $id)->messages()[0]['sources'])->toBe([])
        ->and(ConversationMessage::query()->where('conversation_id', $id)->count())->toBe(2);

    $event = fn (string $type, array $data) => new ProviderToolEvent('e1', 'i1', $type, $data, 'started', time(), 'anthropic');
    expect(RunAgentTurn::providerToolStatus($event('server_tool_use', ['name' => 'web_search'])))->toBe(__('Searching the web…'))
        ->and(RunAgentTurn::providerToolStatus($event('web_search_call', [])))->toBe(__('Searching the web…'))
        ->and(RunAgentTurn::providerToolStatus($event('file_search_call', [])))->toBe(__('Searching the knowledge base…'))
        ->and(RunAgentTurn::providerToolStatus($event('code_interpreter_call', [])))->toBe(__('Working…'));
});
