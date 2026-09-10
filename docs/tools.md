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

## Errors

Exceptions thrown from `run()` are handed back to the model as tool errors, never to the person as a crash:

| Thrown | The model sees |
| --- | --- |
| `Illuminate\Validation\ValidationException` (from `$request->validate([...])`) | "Invalid arguments: …" |
| `RuntimeException`, `InvalidArgumentException` | the exception message |
| anything else | "The action failed: …", and the exception is reported |

So a domain service that throws `RuntimeException('Order RO-00012 is already shipped.')` produces a sentence the assistant can relay and act on.

When the person's role does not allow the tool, the model gets "Your role (Viewer) is not allowed to do this." (or "You are not allowed to do this." without a role label), and the generic rules tell it to say who can do it instead of retrying.

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
