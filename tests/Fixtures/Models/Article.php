<?php

namespace Packstub\Agents\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A knowledge-base document. The suite runs on SQLite, which has no vector
 * type, so the similarity clause pgvector would run is stood in for by a
 * scope of the same name: documents whose text shares a word with the
 * question, the ones without an embedding left out as a vector search would.
 */
class Article extends Model
{
    protected $guarded = [];

    protected $casts = [
        'embedding' => 'array',
        'published' => 'boolean',
    ];

    /** @var list<array{0: string, 1: string, 2: float}> the similarity searches asked for: column, question, minimum similarity */
    public static array $searches = [];

    public function scopeWhereVectorSimilarTo(Builder $query, string $column, string $question, float $minSimilarity = 0.6): void
    {
        self::$searches[] = [$column, $question, $minSimilarity];

        $words = array_filter(preg_split('/\W+/', strtolower($question)) ?: [], fn (string $word) => strlen($word) > 3);

        $query->whereNotNull($column)->where(function (Builder $query) use ($words) {
            foreach ($words as $word) {
                $query->orWhere('title', 'like', "%{$word}%")->orWhere('body', 'like', "%{$word}%");
            }
        });
    }
}
