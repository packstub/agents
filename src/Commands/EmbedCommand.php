<?php

namespace Packstub\Agents\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Embeddings;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Support\AgentRuntime;

/**
 * Fill the knowledge base's embedding column: every document that has none
 * (or all of them with --fresh) is embedded from its title and content by
 * laravel/ai's embeddings provider, a batch per request. Run it after a
 * seed, on a schedule, or from the model's saved event for one document
 * (KnowledgeBase::text() is what gets embedded).
 */
class EmbedCommand extends Command
{
    protected $signature = 'packstub-agents:embed
        {--fresh : Embed every document again, not only those without an embedding}
        {--chunk=50 : How many documents go to the provider per request}
        {--tenant= : The workspace whose documents to embed — its key or slug}';

    protected $description = 'Generate the embeddings of the knowledge base the assistant searches';

    public function handle(): int
    {
        $leave = null;

        if (filled($this->option('tenant'))) {
            $context = Agents::context();
            $tenant = $context->findTenant((string) $this->option('tenant')) ?? $context->findTenantBySlug((string) $this->option('tenant'));

            if (! $tenant) {
                $this->components->error('No workspace matches --tenant.');

                return self::FAILURE;
            }

            // The console acts for the app, not for a person: enter the workspace as the system.
            $leave = AgentRuntime::enter(['tenant' => $tenant->getKey(), 'system' => true]);
        }

        try {
            $knowledge = Agents::knowledge();

            if ($knowledge?->model === null) {
                $this->components->error('No knowledge base model is registered: call Agents::knowledgeBase(Article::class, \'embedding\') or set knowledge_base.model in config/packstub-agents.php.');

                return self::FAILURE;
            }

            $column = $knowledge->column;
            $embedded = 0;

            $knowledge->documents()
                ->when(! $this->option('fresh'), fn ($query) => $query->whereNull($column))
                ->chunkById(max(1, (int) $this->option('chunk')), function (Collection $documents) use ($knowledge, $column, &$embedded): void {
                    $documents = $documents->filter(fn (Model $document) => $knowledge->text($document) !== '')->values();

                    if ($documents->isEmpty()) {
                        return;
                    }

                    $vectors = Embeddings::for($documents->map(fn (Model $document) => $knowledge->text($document))->all())->generate()->embeddings;

                    foreach ($documents as $i => $document) {
                        if (isset($vectors[$i])) {
                            $document->forceFill([$column => $vectors[$i]])->saveQuietly();
                            $embedded++;
                        }
                    }
                });

            $this->components->info($embedded === 0 ? 'Nothing to embed: every document has its embedding.' : "Embedded {$embedded} document".($embedded === 1 ? '' : 's').'.');

            return self::SUCCESS;
        } finally {
            if ($leave) {
                $leave();
            }
        }
    }
}
