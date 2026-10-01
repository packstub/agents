<?php

namespace Packstub\Agents\Ai\Side;

use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent as AgentContract;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * A housekeeping agent next to the assistant: one cheap call with a fixed
 * instruction and a structured answer (laravel/ai's HasStructuredOutput), so
 * the engine reads fields instead of parsing prose — the chat's title, its
 * rolling summary, its classification, the prompt guard's verdict. It has no
 * tools, no history and no middleware, and runs on the provider's cheapest
 * model unless told otherwise.
 *
 * In a test each one is faked on its own (TitleAgent::fake([['title' => …]])).
 * Unfaked next to a faked assistant, the title, the classification and the
 * guard are skipped (runsBeside()), so the assistant's fake answers stay the
 * assistant's; the summary answers through the provider it is handed.
 */
abstract class SideAgent implements AgentContract, HasStructuredOutput
{
    use Promptable;

    /** The field a plain-text answer fills, for a provider (or a fake) that answered in prose; null when prose is no answer. */
    protected static ?string $textField = null;

    public function timeout(): int
    {
        return 30;
    }

    /**
     * Ask once and return the fields. A provider that answered in prose gives its text under the agent's text
     * field, or nothing when the agent has none.
     *
     * @return array<string, mixed>
     */
    public static function run(string $input, TextProvider $provider, ?string $model = null): array
    {
        $agent = static::make();

        if (static::isFaked()) {
            $provider = Ai::textProviderFor($agent, $provider->name());
        }

        $response = $provider->prompt(new AgentPrompt($agent, $input, [], $provider, $model ?? $provider->cheapestTextModel(), $agent->timeout()));

        if ($response instanceof StructuredAgentResponse) {
            return $response->toArray();
        }

        return static::$textField !== null && trim($response->text) !== '' ? [static::$textField => $response->text] : [];
    }

    /**
     * Whether the call may be made next to the assistant: always outside a test; with the assistant faked only when
     * this agent is faked too, since it would otherwise take the assistant's next fake answer.
     *
     * @param  class-string<AgentContract>|AgentContract  $assistant
     */
    public static function runsBeside(string|AgentContract $assistant): bool
    {
        $class = is_string($assistant) ? $assistant : $assistant::class;

        return static::isFaked() || ! Ai::hasFakeGatewayFor($class);
    }
}
