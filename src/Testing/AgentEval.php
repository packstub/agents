<?php

namespace Packstub\Agents\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Files\File;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentRun;
use Packstub\Agents\Support\AgentRuntime;

/**
 * An eval of the assistant in a test: ask as a person, with the provider
 * faked step by step, and assert on what the agent did — which tools it
 * called with which arguments, what it proposed, what it answered. The
 * whole engine runs (the budget, the app's middleware, the tools, the
 * conversation store), so a broken tool schema, a wrong ability or a prompt
 * that stopped calling the overview tool fails here before it fails in
 * front of a person. Each ask() continues the same conversation.
 *
 *   AgentEval::as($user)
 *       ->expecting([new ToolCall('c1', 'list-widgets', ['status' => ['live']]), 'Two widgets are live.'])
 *       ->ask('How many widgets are live?')
 *       ->assertCalled('list-widgets', ['status' => ['live']])
 *       ->assertAnswerContains('Two');
 */
class AgentEval
{
    protected ?Model $tenant = null;

    protected ?string $model = null;

    protected ?string $context = null;

    protected ?string $conversation = null;

    /** @var list<File> */
    protected array $attachments = [];

    protected ?array $expecting = null;

    final public function __construct(protected Model&Authenticatable $participant) {}

    public static function as(Model&Authenticatable $participant): static
    {
        return new static($participant);
    }

    public function in(?Model $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function model(?string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function context(?string $context): static
    {
        $this->context = $context;

        return $this;
    }

    /** @param  list<File>  $attachments */
    public function with(array $attachments): static
    {
        $this->attachments = $attachments;

        return $this;
    }

    /**
     * What the faked provider answers on the next question, step by step: a string is an answer, a
     * Laravel\Ai\Responses\Data\ToolCall makes the agent run that tool (a write tool becomes a proposal), a
     * TextResponse carries usage, a closure may throw. Without it the provider fake set on the agent class is used.
     *
     * @param  list<mixed>  $responses
     */
    public function expecting(array $responses): static
    {
        $this->expecting = $responses;

        return $this;
    }

    public function ask(string $prompt): AgentEvalResult
    {
        if ($this->expecting !== null) {
            Agents::agentClass()::fake($this->expecting);
            $this->expecting = null;
        }

        $answer = AgentRun::as($this->participant)
            ->in($this->tenant)
            ->model($this->model)
            ->context($this->context)
            ->continuing($this->conversation)
            ->with($this->attachments)
            ->ask($prompt);

        $this->conversation = $answer->conversation;
        $this->attachments = [];

        $chat = AgentChat::for($this->participant, $this->conversation, $this->model, $this->context);

        return new AgentEvalResult($answer, $chat, $this);
    }

    /** Approve or reject the proposal a previous ask() left waiting, and read the answer that follows. */
    public function decide(string $callId, bool $approve): AgentEvalResult
    {
        if ($this->expecting !== null) {
            Agents::agentClass()::fake($this->expecting);
            $this->expecting = null;
        }

        $leave = AgentRuntime::enter(array_filter(['user' => $this->participant, 'tenant' => $this->tenant?->getKey()], fn ($v) => $v !== null));

        try {
            $chat = AgentChat::for($this->participant, $this->conversation, $this->model, $this->context)->sync();
            $turn = $chat->decide($callId, $approve);

            return new AgentEvalResult(AgentRun::answerOf($chat, $turn), $chat, $this);
        } finally {
            $leave();
        }
    }

    public function conversation(): ?string
    {
        return $this->conversation;
    }
}
