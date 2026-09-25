<?php

namespace Packstub\Agents\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Files\File;
use Packstub\Agents\Models\AgentTurn;

/**
 * The assistant without a chat: ask a question as a person, in a workspace,
 * and get the answer back in the same call — from a scheduled command, a
 * job, a webhook, a test. The turn goes through everything a chat turn
 * does (the budget, the app's middleware, the tools the person's role
 * allows, the record on agent_turns) and lands in a conversation the person
 * can open later; a write tool the model proposes waits there for their
 * decision, since nobody is watching.
 *
 *   $answer = AgentRun::as($user)->in($team)->ask('Which orders are waiting for a phone call?');
 *   $answer->text; $answer->tools(); $answer->proposals; $answer->conversation;
 */
class AgentRun
{
    protected ?Model $tenant = null;

    protected ?string $model = null;

    protected ?string $context = null;

    protected ?string $conversation = null;

    protected ?string $locale = null;

    /** @var list<File> */
    protected array $attachments = [];

    final public function __construct(protected Model&Authenticatable $participant) {}

    /** Who asks: their role gates the tools, their budget is counted, the conversation is theirs. */
    public static function as(Model&Authenticatable $participant): static
    {
        return new static($participant);
    }

    /** The workspace the question is asked in (a panel's tenant, or the model Agents::tenantModel() named). */
    public function in(?Model $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    /** A picker key ("fast", "deep"); the person's remembered choice otherwise. */
    public function model(?string $model): static
    {
        $this->model = $model;

        return $this;
    }

    /** A page context ("orders/12"): the record the question is about. */
    public function context(?string $context): static
    {
        $this->context = $context;

        return $this;
    }

    /** Continue one of the person's conversations instead of starting a new one. */
    public function continuing(?string $conversation): static
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function locale(?string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    /** @param  list<File>  $attachments  laravel/ai files the provider reads with the question */
    public function with(array $attachments): static
    {
        $this->attachments = $attachments;

        return $this;
    }

    /** Ask, and wait for the answer. Null turn when the agent is off or the question is empty. */
    public function ask(string $prompt): AgentAnswer
    {
        $leave = AgentRuntime::enter(array_filter([
            'user' => $this->participant,
            'tenant' => $this->tenant?->getKey(),
            'locale' => $this->locale,
        ], fn ($v) => $v !== null));

        try {
            $chat = AgentChat::for($this->participant, $this->conversation, $this->model, $this->context)->sync();
            $turn = $chat->send($prompt, $this->attachments);

            return self::answerOf($chat, $turn);
        } finally {
            $leave();
        }
    }

    /** The answer a chat's turn produced, read back from the transcript once the turn ended. */
    public static function answerOf(AgentChat $chat, ?AgentTurn $turn): AgentAnswer
    {
        $chat->refresh();
        $turn?->refresh();
        $messages = $chat->messages();
        $last = $messages->last();
        $answer = $last && $last['role'] === 'assistant' ? $last : null;

        $proposals = collect($answer['tools'] ?? [])
            ->filter(fn (array $tool) => $tool['pending'])
            ->map(fn (array $tool) => ['id' => $tool['id'], 'question' => $tool['question'], 'tool' => $tool['tool'], 'arguments' => $tool['arguments']])
            ->values();

        return new AgentAnswer($turn, $chat->conversation(), (string) ($answer['text'] ?? $turn?->text ?? ''), $proposals);
    }
}
