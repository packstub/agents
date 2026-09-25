<?php

namespace Packstub\Agents\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Support\AgentRun;

/**
 * Ask the assistant from the console, as a person: for a scheduled report
 * ("what needs attention today", mailed by the scheduler), a smoke test
 * after a deploy, or a script. Prints the answer, or JSON with the record.
 */
class RunCommand extends Command
{
    protected $signature = 'packstub-agents:run
        {prompt : The question}
        {--user= : Who asks — an id or an email on the auth provider}
        {--tenant= : The workspace — its key or slug}
        {--model= : A picker key (auto, fast, deep…)}
        {--context= : A page context, e.g. orders/12}
        {--conversation= : Continue one of the person\'s conversations}
        {--json : Print the turn\'s record as JSON}';

    protected $description = 'Ask the assistant a question as a person and print the answer';

    public function handle(): int
    {
        $user = $this->resolveUser((string) $this->option('user'));

        if (! $user) {
            $this->components->error('No person matches --user; pass an id or an email of the auth provider.');

            return self::FAILURE;
        }

        $tenant = null;

        if (filled($this->option('tenant'))) {
            $context = Agents::context();
            $tenant = $context->findTenant((string) $this->option('tenant')) ?? $context->findTenantBySlug((string) $this->option('tenant'));

            if (! $tenant) {
                $this->components->error('No workspace matches --tenant.');

                return self::FAILURE;
            }
        }

        $answer = AgentRun::as($user)
            ->in($tenant)
            ->model($this->option('model') ?: null)
            ->context($this->option('context') ?: null)
            ->continuing($this->option('conversation') ?: null)
            ->ask((string) $this->argument('prompt'));

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $answer->ok(),
                'status' => $answer->turn?->status,
                'error' => $answer->error(),
                'conversation' => $answer->conversation,
                'turn' => $answer->turn?->id,
                'text' => $answer->text,
                'tools' => $answer->tools(),
                'proposals' => $answer->proposals->all(),
                'usage' => $answer->turn?->usage,
                'cost' => $answer->turn?->cost,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $answer->ok() ? self::SUCCESS : self::FAILURE;
        }

        if (! $answer->ok()) {
            $this->components->error($answer->error() ?? 'The assistant did not answer.');

            return self::FAILURE;
        }

        $this->line($answer->text);

        foreach ($answer->proposals as $proposal) {
            $this->components->warn('Waiting for a decision in the chat: '.$proposal['question']);
        }

        return self::SUCCESS;
    }

    protected function resolveUser(string $given): (Model&Authenticatable)|null
    {
        if ($given === '') {
            return null;
        }

        $provider = Auth::guard(Agents::context()->guard())->getProvider();

        if (! $provider) {
            return null;
        }

        $user = str_contains($given, '@') ? $provider->retrieveByCredentials(['email' => $given]) : $provider->retrieveById($given);

        return $user instanceof Model && $user instanceof Authenticatable ? $user : null;
    }
}
