<?php

namespace Packstub\Agents\Mcp;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\TransientToken;
use Packstub\Agents\Events\ToolAuthorized;
use Packstub\Agents\Exceptions\WorkspaceAccessDenied;
use Packstub\Agents\Facades\Agents;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * One capability of the product. Every tool is a laravel/mcp tool: the MCP
 * server lists it to external agents, and the in-panel chat calls the very
 * same class through laravel/ai's McpServerTool bridge.
 *
 * Authorization is the panel's: a tool is only registered (and only runs)
 * when the current person may $ability — the same strings that gate the
 * resources and actions, so the agent can never do more than its user.
 * An agent access token narrows that further: a read token never sees a
 * write tool, and a token scoped to some tools ("tool:{name}" abilities)
 * sees only those. Both checks run on the tool list and again on the call,
 * and with a workspace entered the call asks the person's membership again,
 * so a turn under way stops running tools once the person was removed.
 * Domain errors (RuntimeException from the services) are handed back to the
 * model as tool errors, never thrown at the user.
 */
abstract class AgentTool extends Tool
{
    /** The ability required to see and run this tool; null = any member of the workspace. */
    protected ?string $ability = null;

    public function shouldRegister(): bool
    {
        return Agents::allows($this->ability) && $this->tokenRefusal() === null;
    }

    public function handle(Request $request): Response
    {
        if ($refusal = self::membershipRefusal()) {
            return $this->refused($request, $refusal, 'workspace');
        }

        if (! Agents::allows($this->ability)) {
            return $this->refused($request, self::refusal(), 'role');
        }

        if ($refusal = $this->tokenRefusal()) {
            return $this->refused($request, $refusal, 'token');
        }

        ToolAuthorized::dispatch($this, $this->ability, $request->all(), true);

        try {
            $result = $this->run($request);

            // The app's own say on what the model reads (Agents::mapToolResultsUsing()), in the order given. A callback
            // that throws fails the call like the tool would, so the result it was given never leaves.
            foreach (Agents::toolResultMaps() as $map) {
                $result = $map($result, $this, $request);
            }

            return Response::json($result);
        } catch (ValidationException $e) {
            return Response::error(__('Invalid arguments: :errors', ['errors' => collect($e->errors())->flatten()->join(' ')]));
        } catch (RuntimeException|InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error(__('The action failed: :message', ['message' => $e->getMessage()]));
        }
    }

    protected function refused(Request $request, string $refusal, string $by): Response
    {
        ToolAuthorized::dispatch($this, $this->ability, $request->all(), false, $refusal, $by);

        return Response::error($refusal);
    }

    /**
     * Execute the tool and return the data the model gets to see (encoded as JSON).
     *
     * @return array<string, mixed>
     */
    abstract protected function run(Request $request): array;

    /**
     * A proposed call as a question the person can answer ("Confirm order RO-00016?"),
     * shown where a write tool waits for approval. Null (the default) reads as the
     * tool's title followed by the first argument — see ApprovableTool::question().
     *
     * @param  array<string, mixed>  $arguments
     */
    public function describe(array $arguments): ?string
    {
        return null;
    }

    /**
     * What a proposed call would change, shown with the question while it waits for approval: rows of
     * ['label' => 'Price', 'before' => 12, 'after' => 15], either side left out when there is none (a create has
     * no before, a delete no after). Read while the proposal waits, so it is the record as it is now. Empty (the
     * default) shows nothing; a preview that throws is left out — see ApprovableTool::preview().
     *
     * @param  array<string, mixed>  $arguments
     * @return list<array{label: string, before?: mixed, after?: mixed}>
     */
    public function preview(array $arguments): array
    {
        return [];
    }

    /**
     * Why the current agent access token may not run this tool, or null when
     * it may. The in-panel chat (session auth) has no token and relies on
     * approvals instead; Sanctum's transient token for a session stands for
     * "no token" as well.
     */
    public function tokenRefusal(): ?string
    {
        $token = self::accessToken();

        if (! $token) {
            return null;
        }

        if (! $this->isReadOnly() && ! $token->can('write')) {
            return __('This access token is read-only.');
        }

        if (self::tokenIsScoped($token) && ! $token->can('tool:'.$this->name())) {
            return __('This access token does not include :tool.', ['tool' => $this->name()]);
        }

        return null;
    }

    /**
     * Why the person may no longer act in the workspace the call runs in, or null when they may (or there is no
     * workspace, or nobody is acting). Membership was checked when the workspace was entered; a turn keeps the person
     * and the workspace from that moment, so every call asks canAccessTenant() again and the remaining calls of a
     * turn are refused once the person was removed. Nothing is queried in an app without workspaces.
     */
    public static function membershipRefusal(): ?string
    {
        $context = Agents::context();
        $tenant = $context->tenant();

        if (! $tenant) {
            return null;
        }

        $user = $context->user();

        if (! $user || $context->canAccessTenant($user, $tenant)) {
            return null;
        }

        return WorkspaceAccessDenied::make()->getMessage();
    }

    /** The personal access token the request was authenticated with, if any. */
    public static function accessToken(): ?HasAbilities
    {
        $user = auth()->user();
        $token = $user && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        return $token instanceof HasAbilities && ! $token instanceof TransientToken ? $token : null;
    }

    /**
     * The tool names a token was limited to ("tool:{name}" abilities); empty = every tool the role allows.
     *
     * @return list<string>
     */
    public static function tokenTools(HasAbilities $token): array
    {
        $abilities = (array) ($token->abilities ?? []);

        return array_values(array_map(fn (string $a) => substr($a, 5), array_filter($abilities, fn ($a) => str_starts_with((string) $a, 'tool:'))));
    }

    public static function tokenIsScoped(HasAbilities $token): bool
    {
        return self::tokenTools($token) !== [];
    }

    public function isReadOnly(): bool
    {
        return self::hasReadOnlyAnnotation($this);
    }

    public static function hasReadOnlyAnnotation(object $tool): bool
    {
        return (new ReflectionClass($tool))->getAttributes(IsReadOnly::class) !== [];
    }

    /** The message a person gets when their role may not run a tool. */
    public static function refusal(): string
    {
        $role = Agents::roleLabel();

        return $role
            ? __('Your role (:role) is not allowed to do this.', ['role' => $role])
            : __('You are not allowed to do this.');
    }

    /** Clamp a requested page size. */
    protected function limit(Request $request, int $default = 20, int $max = 50): int
    {
        return max(1, min($max, (int) ($request->get('limit') ?: $default)));
    }
}
