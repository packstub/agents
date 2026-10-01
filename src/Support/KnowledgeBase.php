<?php

namespace Packstub\Agents\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The app's own documents as the assistant searches them: guides, policies,
 * how-to articles — what "how do I…" is answered from, where the records
 * answer "how many…". Two shapes, which can be combined:
 *
 * - An Eloquent model with an embedding column (pgvector): Agents::knowledgeBase(Article::class, 'embedding')
 *   serves the search-knowledge-base tool to the chat and to MCP clients, a similarity search over that column
 *   (laravel/ai embeds the question), and `php artisan packstub-agents:embed` fills the column.
 * - Vector stores hosted by the provider: Agents::knowledgeBase(stores: ['vs_…']) hands the chat laravel/ai's
 *   FileSearch provider tool over them, on a provider that runs one.
 *
 * An app on another index (Scout, Typesense, a search API) passes `using`: fn (string $query, int $limit) returning
 * models or arrays, and the same tool serves it.
 */
class KnowledgeBase
{
    /**
     * @param  class-string<Model>|null  $model
     * @param  string|Closure(Model): string  $title  the attribute, or how to read it
     * @param  string|Closure(Model): string  $content
     * @param  string|Closure(Model): ?string|null  $url  the attribute or a closure; null for documents without a page
     * @param  (Closure(Builder): mixed)|null  $query  narrows the documents searched and embedded (published ones, the workspace's)
     * @param  (Closure(string, int): iterable<int, mixed>)|null  $using  a search of the app's own, in place of the similarity query
     * @param  list<string>  $stores  provider vector store ids, for the FileSearch provider tool
     */
    public function __construct(
        public readonly ?string $model = null,
        public readonly string $column = 'embedding',
        public readonly string|Closure $title = 'title',
        public readonly string|Closure $content = 'content',
        public readonly string|Closure|null $url = null,
        public readonly float $minSimilarity = 0.5,
        public readonly int $limit = 5,
        public readonly ?Closure $query = null,
        public readonly ?Closure $using = null,
        public readonly array $stores = [],
        public readonly ?string $ability = null,
        public readonly int $excerpt = 1500,
    ) {}

    /** The knowledge base config `knowledge_base` describes, or null when it names neither a model nor a store. */
    public static function fromConfig(): ?self
    {
        $config = (array) config('packstub-agents.knowledge_base', []);
        $stores = array_values(array_filter((array) ($config['stores'] ?? []), fn ($id) => is_string($id) && $id !== ''));

        if (blank($config['model'] ?? null) && $stores === []) {
            return null;
        }

        return new self(
            model: filled($config['model'] ?? null) ? (string) $config['model'] : null,
            column: (string) ($config['column'] ?? 'embedding'),
            title: (string) ($config['title'] ?? 'title'),
            content: (string) ($config['content'] ?? 'content'),
            url: filled($config['url'] ?? null) ? (string) $config['url'] : null,
            minSimilarity: (float) ($config['min_similarity'] ?? 0.5),
            limit: (int) ($config['limit'] ?? 5),
            stores: $stores,
            ability: filled($config['ability'] ?? null) ? (string) $config['ability'] : null,
        );
    }

    /** Whether the search-knowledge-base tool has something to search: a model, or a search of the app's own. */
    public function searchable(): bool
    {
        return $this->model !== null || $this->using !== null;
    }

    /**
     * The documents closest to the question, best first, as the model reads them: title, url (when there is one)
     * and the content cut to an excerpt.
     *
     * @return Collection<int, array{title: string, url?: string, content: string}>
     */
    public function search(string $question, ?int $limit = null): Collection
    {
        $limit = max(1, min(20, $limit ?? $this->limit));

        if ($this->using !== null) {
            $found = collect(($this->using)($question, $limit))->take($limit);
        } elseif ($this->model !== null) {
            // laravel/ai embeds the question and the database compares it with the column (pgvector).
            $found = $this->documents()->whereVectorSimilarTo($this->column, $question, $this->minSimilarity)->limit($limit)->get();
        } else {
            $found = collect();
        }

        return $found->map(fn ($document) => $this->present($document))->filter()->values();
    }

    /** The documents searched and embedded: the model's, narrowed by `query`. */
    public function documents(): Builder
    {
        $query = $this->model::query();

        if ($this->query !== null) {
            $query = ($this->query)($query) ?? $query;
        }

        return $query;
    }

    /** What is embedded for a document: its title, then its content. */
    public function text(Model $document): string
    {
        return trim($this->read($document, $this->title)."\n\n".$this->read($document, $this->content));
    }

    /**
     * A search hit as the model reads it; an array (from a search of the app's own) is taken as it is.
     *
     * @return array{title: string, url?: string, content: string}|null
     */
    protected function present(mixed $document): ?array
    {
        if (is_array($document)) {
            $title = (string) ($document['title'] ?? '');
            $content = (string) ($document['content'] ?? '');
            $url = $document['url'] ?? null;
        } elseif ($document instanceof Model) {
            $title = $this->read($document, $this->title);
            $content = $this->read($document, $this->content);
            $url = $this->url === null ? null : ($this->url instanceof Closure ? ($this->url)($document) : $document->getAttribute($this->url));
        } else {
            return null;
        }

        if ($title === '' && $content === '') {
            return null;
        }

        return array_filter([
            'title' => $title,
            'url' => is_string($url) && $url !== '' ? $url : null,
            'content' => Str::limit(trim($content), $this->excerpt),
        ], fn ($value) => $value !== null);
    }

    protected function read(Model $document, string|Closure $field): string
    {
        return trim((string) ($field instanceof Closure ? $field($document) : $document->getAttribute($field)));
    }
}
