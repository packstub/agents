<?php

namespace Packstub\Agents\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Contracts\AgentResource;
use Packstub\Agents\Filters\Filter;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\Fixtures\Models\WidgetStatus;

/**
 * An agent resource without a panel behind it: the filter vocabulary and the
 * summaries the app's tools share, registered with Agents::useResources().
 * A Filament resource implements the same contract through InteractsWithAgent.
 */
class WidgetResource implements AgentResource
{
    public static function agentKey(): string
    {
        return 'widgets';
    }

    public static function getModel(): string
    {
        return Widget::class;
    }

    public static function getEloquentQuery(): Builder
    {
        return Widget::query();
    }

    public static function canViewAny(): bool
    {
        return Abilities::allows('widgets.view');
    }

    public static function agentSummary(Model $record, bool $full = false): array
    {
        return [
            'id' => $record->id,
            'name' => $record->name,
            'status' => $record->status,
            'price' => (float) $record->price,
            'url' => 'https://widgets.test/widgets/'.$record->id,
        ];
    }

    public static function agentContextLabel(Model $record): string
    {
        return 'Widget '.$record->name;
    }

    public static function agentFilters(): array
    {
        return [
            Filter::text('query')->description('Part of the name.')
                ->apply(fn (Builder $q, string $text) => $q->where('name', 'like', "%{$text}%")),
            Filter::enum('status', WidgetStatus::class)->multiple()
                ->apply(fn (Builder $q, array $status) => $q->whereIn('status', $status)),
            Filter::flag('live_only')->description('Only live widgets.')
                ->apply(fn (Builder $q) => $q->where('status', WidgetStatus::Live->value)),
            Filter::number('min_price')
                ->apply(fn (Builder $q, int|float $min) => $q->where('price', '>=', $min)),
            Filter::date('created_from')
                ->apply(fn (Builder $q, string $date) => $q->where('created_at', '>=', $date)),
        ];
    }
}
