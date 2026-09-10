# Tables and charts

A model that searches orders and a model that shows orders should mean the same thing by "waiting for a call". The `AgentResource` contract gives each kind of record one name, one summary and one filter vocabulary, and `AgentResources` shares it with every tool that names the same key.

## AgentResource

A plain class implements `Packstub\Agents\Contracts\AgentResource` and is registered with `Agents::useResources()`:

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Contracts\AgentResource;
use Packstub\Agents\Filters\Filter;

class Orders implements AgentResource
{
    public static function agentKey(): string
    {
        return 'orders';
    }

    public static function agentSummary(Model $record, bool $full = false): array
    {
        return [
            'number' => $record->number,
            'status' => $record->status->value,
            'total' => (float) $record->total,
            'url' => route('orders.show', $record),
        ];
    }

    public static function agentContextLabel(Model $record): string
    {
        return 'Order '.$record->number;
    }

    public static function agentFilters(): array
    {
        return [ /* see below */ ];
    }
}
```

```php
Agents::useResources([Orders::class, Customers::class]);
```

| Method | Purpose |
| --- | --- |
| `agentKey()` | the name the model uses for this kind of record (`orders`) |
| `agentSummary(Model $record, bool $full = false)` | how a record looks to the model: compact for lists, `$full` for one record, always with a `url` so answers can link to it |
| `agentContextLabel(Model $record)` | "The person opened this chat from …" ("Order RO-00012"), see [Page context](#page-context) |
| `agentFilters()` | the vocabulary the model may pass to your search tools |

`AgentResources::all()` lists the registered classes by key, `has($key)` says whether one is registered, `find($key)` returns one, `forModel(Order::class)` finds the class for a model when the class exposes a static `getModel()`, and `filters($key)` returns a resource's filter vocabulary.

**In a Filament panel**, a resource implements the same contract with the `Packstub\Agents\Concerns\InteractsWithAgent` trait (shipped here, used there), which derives the key, the summary, the label and the record url from the resource itself, and the plugin discovers every resource of the panel that implements it; its `show-table` tool then renders the resource's own table under an answer. See [Filament Agents](https://packstub.dev/docs/filament-agents/tables-and-charts).

## Filters

`agentFilters()` returns the vocabulary the model may pass, as `Filter` objects that know their JSON schema, how to clean a value and how to narrow a query:

```php
use Packstub\Agents\Filters\Filter;

public static function agentFilters(): array
{
    return [
        Filter::text('query')->description('Order number or customer.')
            ->apply(fn (Builder $q, string $text) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$text}%")
                ->orWhereRelation('customer', 'name', 'like', "%{$text}%"))),
        Filter::enum('status', OrderStatus::class)->multiple()
            ->apply(fn (Builder $q, array $statuses) => $q->whereIn('status', $statuses)),
        Filter::flag('open_only')->description('Only orders that still need work.')
            ->apply(fn (Builder $q) => $q->whereIn('status', OrderStatus::open())),
        Filter::date('placed_from')
            ->apply(fn (Builder $q, string $date) => $q->where('placed_at', '>=', $date)),
        Filter::number('min_total')
            ->apply(fn (Builder $q, int|float $total) => $q->where('total', '>=', $total)),
    ];
}
```

| Constructor | Schema | Normalized value |
| --- | --- | --- |
| `Filter::text($key)` | string | trimmed string |
| `Filter::enum($key, EnumClass::class or [...])` | string with the allowed values (an array of them with `->multiple()`) | one value, or a list |
| `Filter::boolean($key)` | boolean | `true` or `false`; the closure runs for both |
| `Filter::flag($key)` | boolean | the closure only runs when true |
| `Filter::date($key)` | string, "YYYY-MM-DD" in the hint | the string |
| `Filter::number($key)` | number | int or float |

Empty values and unknown keys are dropped. `->description()` becomes part of the hint the model reads.

The same vocabulary serves your search tools through `AgentResources`:

```php
$filters = AgentResources::normalizeFilters('orders', (array) $request->get('filters'));
$query = AgentResources::apply('orders', Order::query(), $filters);
$schema = AgentResources::filterSchema($schema, 'orders');   // for the tool's schema(); without a key, the union of every table's vocabulary
```

## Charts

`Packstub\Agents\Mcp\Tools\DrawChart` renders a chart from numbers the model already retrieved with other tools:

| Argument | |
| --- | --- |
| `title` | required |
| `type` | `bar` (default), `line`, `pie`, `doughnut` |
| `labels` | 1 to 60 strings |
| `datasets` | 1 to 8 series of `{label, data}`; every series has one value per label |

The rules tell the model to pass only values that came from a tool result, never estimates. For anything over time, prefer a reporting tool of your own that returns a `chart` key next to its data (see [Tools](tools.md)); a chat surface renders both the same way, and an MCP client or your own front end gets the same `chart` structure in the tool result.

## Page context

A conversation can start from a record: pass a `context` of `orders/12` when you start it (the `$context` argument of `AgentTurns::enqueue()`), and the dynamic prompt block carries the record's compact summary: "The person opened this chat from Order RO-00012. 'This one' / 'this record' means that record: {…}". The model calls a tool for anything beyond the summary.

`PageContext::resolve('orders/12')` turns a reference into the label and summary; `PageContext::fromRequest()` resolves one from the current route when a bound model or a record parameter names a registered resource. A resource class used for page context exposes the statics `resolveRecordRouteBinding($id)` (a Filament resource has it; a plain class returns `Order::find($id)`) and, for `fromRequest()` on routes without a bound model, `getSlug()`.
