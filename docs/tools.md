# Tools

Every capability the assistant has is one `laravel/mcp` tool class. The MCP server lists it to external agents; your agent calls the very same class through laravel/ai's `McpServerTool` bridge. There is one list, and it lives on your server class.

## Scaffold

```bash
php artisan packstub-agents:tool SearchOrders --ability=orders.view
php artisan packstub-agents:tool ConfirmOrder --write --ability=orders.manage
```

The command writes `app/Mcp/Tools/<Name>.php`. Without `--write` the class carries `#[IsReadOnly]`; `--ability` fills the `$ability` property; `--force` overwrites a file that exists.

## Anatomy

```php
namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Mcp\AgentTool;

#[IsReadOnly]
#[Description('Find orders by number, customer, status and date. Returns compact rows with a url.')]
class SearchOrders extends AgentTool
{
    /** The ability required to see and run this tool; null = any member of the workspace. */
    protected ?string $ability = 'orders.view';

    protected function run(Request $request): array
    {
        $query = Order::query()
            ->when($request->get('query'), fn ($q, $text) => $q->where('number', 'like', "%{$text}%"));

        return [
            'total' => $query->count(),
            'rows' => $query->limit($this->limit($request))->get()->map(fn (Order $order) => [
                'number' => $order->number,
                'status' => $order->status->value,
                'url' => route('orders.show', $order),
            ])->all(),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Order number or customer name.'),
            'limit' => $schema->integer()->description('Max rows, default 20.'),
        ];
    }
}
```

- `$ability` is the same string that gates the action the tool mirrors in your app. The tool is only listed, and only runs, when the current person may that ability (through the `authorizeUsing()` callback, or the `Gate`).
- `run()` returns the data the model gets to see; it is encoded as JSON. Keep rows compact and always include a `url` so the answer can link to the record.
- `schema()` describes the arguments with laravel's `JsonSchema` builder. Use `#[Description]` for what the tool does, when to use it and what it returns; the model reads it.
- `limit()` clamps a requested page size (default 20, max 50).

## Read-only versus write

`#[IsReadOnly]` decides how a tool is treated in both places:

| | Read-only tool | Write tool |
| --- | --- | --- |
| For the agent | runs directly | wrapped as an `ApprovableTool`: laravel/ai pauses the turn on the tool and its arguments until the person approves or rejects it |
| Over MCP with a `read` token | runs | refused ("This access token is read-only.") |
| Over MCP with a `write` token | runs | runs directly with the token holder's role |

There is no separate "destructive" tier: a write is a write. If a change needs extra care, say so in the description, read the record first inside `run()` and refuse when the state is wrong.

### The proposal as a question

A paused call is shown as a question the person can answer. Give a write tool a `describe()` and it phrases its own calls; without one, the question is the tool's title followed by the first scalar argument ("Confirm Order RO-00016?", "Retire Widget 12?"). The sentence travels as the approval's reason (laravel/ai's `PendingApproval::$reason`), so any client that reads the pending approvals gets it too; `ApprovableTool::question($tool, $arguments)` is the same sentence in code, for a chat surface of your own that renders a stored pending call.

```php
public function describe(array $arguments): ?string
{
    $order = Order::query()->where('number', $arguments['number'] ?? null)->first();

    return $order ? "Confirm order {$order->number} for {$order->customer_name}?" : null; // null falls back to the title
}
```

Keep it to one sentence about the effect, in the person's words rather than the tool's: the arguments stay visible under the question for whoever wants the exact call.

### A preview of the change

A question says what the call will do; a preview shows what it changes. Give a write tool a `preview()` and the proposal carries rows of a label with the value now and the value after, read while the proposal waits, so "before" is the record as it is at that moment:

```php
public function preview(array $arguments): array
{
    $order = Order::query()->where('number', $arguments['number'] ?? null)->first();

    return $order ? [
        ['label' => 'Status', 'before' => $order->status->label(), 'after' => 'Confirmed'],
        ['label' => 'Ship by', 'before' => $order->ship_by?->toDateString(), 'after' => $arguments['ship_by'] ?? null],
    ] : [];
}
```

Leave out `before` for something new and `after` for something removed. Each proposal of `AgentChat::messages()` and of `AgentAnswer::$proposals` has the rows as `preview`, and Filament Agents shows them under the question. A preview that throws is reported and left out, so it never stands between the person and the decision; `ApprovableTool::preview($tool, $arguments)` gives the same rows for a surface of your own.

#### Decisions in words, and two proposals at once

A turn that arrives as a question while a proposal waits for a decision is read first: a short reply made of nothing but yes ("Yes, go ahead.", "ok", "Sure, confirm it.", and the same in German, Spanish, Romanian and Russian; `AgentTurns::decisionInText()`) approves every pending proposal and one that opens with a no ("No, leave them.") rejects them, the reply recorded like any question and the turn run as that decision. Anything else — a yes with a condition ("Yes, but only Alpha."), a question after it ("Ok wait, what does this change?"), a yes asked back ("Ok?"), an "if" ("Si lo apruebo, ¿qué cambia?") — is a question of its own: the pending proposals are declined with `AgentTurns::supersededResult()` as their result, so the history never carries a call without a result (laravel/ai cannot continue over one, and a later decision could no longer be matched), and the new question is answered.

What reads the reply, in order — the first that decides wins, and none deciding makes it a question as above:

1. **Your rule.** `Agents::decideTypedUsing(fn (string $text, array $proposals): bool|array|null)` in a service provider. `$proposals` is call id => `name`, `arguments` and `question` (the proposal as a surface asks it). Return `true` or `false` to decide every proposal, an array of call id => bool to decide them one by one (a call you leave out is rejected), or `null` to leave the reply to the lists. A rule that throws is reported and the lists read the reply.
2. **The word lists**, in `resources/lang/<locale>/decisions.php` of the package: `yes` (a reply made of nothing but these phrases approves), `no` (a reply that is one of these rejects) and `no_openers` (a reply that opens with one of these words rejects). Every locale's lists apply, whatever the app's locale, since people type in any language. Add a language by publishing `lang/vendor/packstub-agents/<locale>/decisions.php` with the same three keys; to add phrases to a shipped language, copy its file there and append to it.
3. **The classifier**, when you switch it on with `AGENT_DECISION_CLASSIFIER=true`: a small structured-output side agent (`Packstub\Agents\Ai\Side\DecisionAgent`) asked only when the lists cannot read a reply of up to 40 words. It decides each proposal on its own, so "Yes, but only Alpha." approves Alpha and rejects Beta, and it is applied only when it approved or rejected every proposal; one it leaves undecided, or a failure, makes the reply a question. It runs in the request that sends the reply, on the provider of the model the reply was sent with (its cheapest model) unless `AGENT_DECISION_CLASSIFIER_PROVIDER` and `AGENT_DECISION_CLASSIFIER_MODEL` say otherwise. The lists come first, so it never approves what they reject.

The turn records what decided it: `AgentTurn::decidedBy()` is `app`, `words` or `classifier` (null for a question, or for decisions made with the buttons), and `decisionReason()` is the classifier's one-sentence reason.

An answer that proposed two changes pauses on both. laravel/ai applies the decisions of one pause together, so a decision on one of them is held: the turn stays queued with what was decided so far, later decisions join it (`AgentTurns::enqueue()` merges them), and it starts once every proposal of that answer has one.

## Errors

Exceptions thrown from `run()` are handed back to the model as tool errors, never to the person as a crash:

| Thrown | The model sees |
| --- | --- |
| `Illuminate\Validation\ValidationException` (from `$request->validate([...])`) | "Invalid arguments: …" |
| `RuntimeException`, `InvalidArgumentException` | the exception message |
| anything else | "The action failed: …", and the exception is reported |

So a domain service that throws `RuntimeException('Order RO-00012 is already shipped.')` produces a sentence the assistant can relay and act on.

When the person's role does not allow the tool, the model gets "Your role (Viewer) is not allowed to do this." (or "You are not allowed to do this." without a role label), and the generic rules tell it to say who can do it instead of retrying.

## Changing a result before the model reads it

`Agents::mapToolResultsUsing()` gives your app the last word on what any tool returns, before the model reads it, in the chat and over MCP alike. Register it in a service provider, as often as you need; the callbacks run in the order given, each on what the one before returned:

```php
use Laravel\Mcp\Request;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\AgentTool;

Agents::mapToolResultsUsing(function (array $result, AgentTool $tool, Request $request): array {
    if (! auth()->user()->can('customers.contact')) {
        array_walk_recursive($result, function (&$value, $key) {
            if (in_array($key, ['email', 'phone'], true)) {
                $value = '[hidden]';
            }
        });
    }

    return $result;
});
```

A callback that throws fails the call the way a tool would ([Errors](#errors)), so the result it was handed never reaches the model. `Agents::mapToolResultsUsing(null)` forgets every callback given so far. Use it for what holds across tools (a field some roles never see, a record of what was returned); what belongs to one tool stays in its `run()`.

## The server class

```php
namespace App\Mcp\Servers;

use App\Mcp\Tools;
use Packstub\Agents\Mcp\AgentServer;
use Packstub\Agents\Mcp\Tools\DrawChart;

class AcmeServer extends AgentServer
{
    protected string $name = 'Acme';

    protected string $version = '1.0.0'; // what MCP clients see in the handshake

    protected string $instructions = <<<'MARKDOWN'
        The back office of an online shop. Start with search-orders; confirm-order and ship-order change data.
        MARKDOWN;

    public int $defaultPaginationLength = 50; // rows per page for MCP list requests (laravel/mcp)

    protected array $tools = [
        Tools\WorkspaceOverview::class,
        Tools\SearchOrders::class,
        DrawChart::class,
        Tools\ConfirmOrder::class,
        Tools\ShipOrder::class,
    ];
}
```

Register it with `Agents::useServer(AcmeServer::class)` in a service provider (or `mcp.server` in config). The agent reads the same `$tools`, in the same order, so put the reads first and the overview tool at the top: the generic rules tell the assistant to start broad questions with the overview tool when there is one.

`DrawChart` is the package's own tool, see [Tables and charts](tables-and-charts.md). Include it when you want charts in answers.

An app may skip the server class and pass the list to the facade: `Agents::useTools([...])`. The package's default `AgentServer` then serves that list under the assistant's name.

**In a Filament panel**, `AgentsPlugin::make()->server()` or `->tools()` do the same, and the plugin's `ShowTable` tool renders a resource's own table under an answer; see [Filament Agents](https://packstub.dev/docs/filament-agents/tools).

## Sharing filters between tools

When a class implements `AgentResource` (see [Tables and charts](tables-and-charts.md)), its filter vocabulary is available to every search tool that names the same key, so "orders waiting for a phone call" means the same thing in each of them:

```php
protected function run(Request $request): array
{
    $filters = AgentResources::normalizeFilters('orders', (array) ($request->get('filters') ?? []));
    $query = AgentResources::apply('orders', Order::query(), $filters);
    // …
}

public function schema(JsonSchema $schema): array
{
    return [
        'filters' => $schema->object(AgentResources::filterSchema($schema, 'orders')),
        'limit' => $schema->integer(),
    ];
}
```

## Tools that return a chart

Any tool may return a `chart` key next to its data, and a chat surface renders it under the answer:

```php
return [
    'chart' => [
        'type' => 'line',
        'title' => 'Orders per day',
        'labels' => $days,
        'datasets' => [['label' => 'Orders', 'data' => $counts]],
    ],
    'total' => array_sum($counts),
];
```

`type` is one of `bar`, `line`, `pie` or `doughnut`. Prefer this over `draw-chart` for anything over time: the numbers come straight from the query. The `chart` key is part of the tool result either way, so an MCP client or your own front end can draw it too.

## Knowledge base

Your tools answer "how many" and "which one"; a knowledge base answers "how do I" and "what is our policy on": the guides, policies and how-to articles you already have, searched by meaning and cited in the answer. Register it once and the package's `search-knowledge-base` tool joins the tool list, for the chat and for MCP clients, whether the list is your server's or the facade's.

```php
use App\Models\Article;
use Packstub\Agents\Facades\Agents;

Agents::knowledgeBase(
    Article::class,
    'embedding',                                      // the vector column (pgvector)
    title: 'title',                                   // an attribute, or fn (Article $a): string
    content: 'body',
    url: fn (Article $a) => route('help.show', $a),   // optional: answers link what they cite
    query: fn ($query) => $query->where('published', true),
    minSimilarity: 0.5,
    limit: 5,
    ability: 'help.view',                             // optional: who may search it
);
```

The tool takes a `query` and an optional `limit`, and runs laravel's `whereVectorSimilarTo()` over the column: laravel/ai embeds the question with your embeddings provider (`ai.default_for_embeddings`) and the database orders the documents by distance. The model gets the closest ones back, each with its title, its url when it has one and its content cut to an excerpt, plus a note to answer from them and cite them. The prompt gains a rule as well: search the knowledge base first for how-to and policy questions, cite each article used by its title, and say so when it has nothing rather than answering from general knowledge as if it were your guidance.

| Argument | Default | |
| --- | --- | --- |
| `model` | | the Eloquent model of a document |
| `column` | `embedding` | its vector column |
| `title`, `content` | `title`, `content` | the attributes that are embedded and returned, or closures that read them |
| `url` | `null` | an attribute or a closure; without it articles are cited by title alone |
| `minSimilarity` | `0.5` | how close a document must be, from 0 to 1 |
| `limit` | `5` | how many the model gets at most (it may ask for fewer or more, up to 20) |
| `query` | `null` | narrows the documents searched and embedded: published ones, the workspace's |
| `using` | `null` | a search of your own in place of the similarity query, see below |
| `stores` | `[]` | provider-hosted vector stores, see below |
| `ability` | `null` | the ability required to search; `null` = any member |

The same without closures goes in config, under `knowledge_base` (`model`, `column`, `title`, `content`, `url` as an attribute, `min_similarity`, `limit`, `stores`, `ability`).

### The embeddings

The column is a pgvector `vector` on PostgreSQL, cast to `array` on the model:

```php
Schema::ensureVectorExtensionExists();

Schema::create('articles', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('body');
    $table->vector('embedding', dimensions: 1536)->nullable()->index();
    $table->timestamps();
});
```

`php artisan packstub-agents:embed` fills it: every document without an embedding is embedded from its title and content, a batch per request (`--chunk=50`). `--fresh` embeds all of them again, after you changed the embeddings model; `--tenant=` runs it inside one workspace. Schedule it, or embed a document when it is saved:

```php
static::saved(function (Article $article) {
    if ($article->wasChanged(['title', 'body'])) {
        $article->updateQuietly(['embedding' => Str::of(Agents::knowledge()->text($article))->toEmbeddings()]);
    }
});
```

### A search of your own

Documents in Scout, Typesense, Meilisearch or behind a search API: pass `using`, a closure that takes the question and the limit and returns models or `['title' => …, 'content' => …, 'url' => …]` arrays. The same tool serves it.

```php
Agents::knowledgeBase(using: fn (string $question, int $limit) => Article::search($question)->take($limit)->get(), content: 'body');
```

### Provider-hosted stores

When the documents live in a vector store at the provider (laravel/ai's `Stores::create()` and `$store->add()`), name the stores and the chat gets laravel/ai's `FileSearch` provider tool over them:

```php
Agents::knowledgeBase(stores: ['vs_6a1f…']);
```

The provider runs the search itself, so there is no tool call of yours in the transcript and nothing for an MCP client; it needs a provider with file search (OpenAI, Gemini, xAI, Azure OpenAI) and is left out of a step that runs on another. Both shapes can be registered together.

## Web search

The chat can search the web, with the search run by the provider (laravel/ai's `WebSearch` provider tool): for the things your tools cannot know, a carrier's tracking page, a tax rate, a vendor's documentation. It is off until you switch it on, and it takes an allow-list:

```dotenv
AGENT_WEB_SEARCH=true
AGENT_WEB_SEARCH_ALLOW=docs.acme.com,laravel.com,anaf.ro
AGENT_WEB_SEARCH_MAX=3
```

```php
'web_search' => [
    'enabled' => env('AGENT_WEB_SEARCH', false),
    'allow' => ['docs.acme.com', 'laravel.com', 'anaf.ro'], // empty = the whole web
    'max' => 3,                                              // searches per turn
    'location' => ['city' => null, 'region' => null, 'country' => 'RO'],
],
```

- **The allow-list is the point.** With it the assistant reads only those domains, which keeps its answers on sources you trust and keeps web text, a prompt-injection vector like any other untrusted input, off pages you do not. An empty list searches the whole web.
- **Two kinds of facts, kept apart.** The prompt gains two rules: web search is for public information only, never for the workspace's own records, and an answer says which is which ("In your workspace…", "According to laravel.com…") with a link to the page each web fact came from. `AgentChat::messages()` lists the cited pages on the answer as `sources` (title and url), and the searches the provider ran appear in its `tools` as read-only `Web Search` entries with their query. While a search runs the status line says "Searching the web…".
- **Provider support.** Anthropic, OpenAI, Gemini, xAI, Azure OpenAI and OpenRouter run it. A turn on a provider that does not (a local Ollama model, a failover to Mistral) answers without it: the `SupportedProviderTools` middleware drops the tool from that step rather than failing the turn.
- **Cost.** Providers bill a search on top of the tokens it adds; `max` caps how many one turn may run.

Web search belongs to the chat. An MCP client has its own.
