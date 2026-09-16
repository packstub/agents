<?php

namespace Packstub\Agents\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tool;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\AgentTool;

/**
 * Agent access tokens: a Sanctum token that lets Claude Code, Claude Desktop
 * or any MCP client work in the app as the person, within their role. Its
 * abilities are "read" / "write", "tool:{name}" for each tool it is limited
 * to, and, with tenancy, "tenant:{slug}" so AuthenticateAgent refuses it on
 * any other workspace. What a token form needs (the tools the role allows,
 * the expiry choices, the endpoint) and the minting itself, without a UI.
 */
class AgentTokens
{
    /**
     * The tools the signed-in person may run right now, as a picker shows them: name => [title, description,
     * read-only]. A token can only be limited to tools the role allows; the role is checked again on every call,
     * so a later role change still wins.
     *
     * @return array<string, array{title: string, description: string, readOnly: bool}>
     */
    public static function availableTools(): array
    {
        $tools = [];

        foreach (Agents::toolClasses() as $class) {
            /** @var Tool $tool */
            $tool = app($class);

            if (! $tool->eligibleForRegistration()) {
                continue;
            }

            $tools[$tool->name()] = [
                'title' => $tool->title(),
                'description' => $tool->description(),
                'readOnly' => $tool instanceof AgentTool ? $tool->isReadOnly() : AgentTool::hasReadOnlyAnnotation($tool),
            ];
        }

        return $tools;
    }

    /**
     * Every tool of the server by name => title, whatever the role allows, so a list can name a tool a token
     * was scoped to even after the role lost it.
     *
     * @return array<string, string>
     */
    public static function toolTitles(): array
    {
        $titles = [];

        foreach (Agents::toolClasses() as $class) {
            /** @var Tool $tool */
            $tool = app($class);
            $titles[$tool->name()] = $tool->title();
        }

        return $titles;
    }

    /**
     * The expiry choices of a token form: "never", or a number of days.
     *
     * @return array<string, string>
     */
    public static function expiryOptions(): array
    {
        return [
            'never' => __('Never'),
            '7' => __(':days days', ['days' => 7]),
            '30' => __(':days days', ['days' => 30]),
            '90' => __(':days days', ['days' => 90]),
            '365' => __(':days days', ['days' => 365]),
        ];
    }

    /** When a token with that expiry choice expires: null for "never". */
    public static function expiresAt(string $expires): ?Carbon
    {
        return $expires !== 'never' && ctype_digit($expires) ? now()->addDays((int) $expires) : null;
    }

    /**
     * The abilities of a token: "read" and/or "write", then "tool:{name}" for every named tool the role allows
     * (a write tool only when the token may write; none named = every tool the role allows), then the workspace.
     *
     * @param  list<string>  $abilities
     * @param  list<string>  $tools
     * @return list<string>
     */
    public static function abilities(array $abilities, array $tools = [], ?string $tenantSlug = null): array
    {
        $abilities = array_values(array_intersect(['read', 'write'], $abilities));
        $canWrite = in_array('write', $abilities, true);
        $known = self::availableTools();

        foreach (array_values($tools) as $name) {
            if (isset($known[$name]) && ($canWrite || $known[$name]['readOnly'])) {
                $abilities[] = 'tool:'.$name;
            }
        }

        if ($tenantSlug !== null && $tenantSlug !== '') {
            $abilities[] = 'tenant:'.$tenantSlug;
        }

        return $abilities;
    }

    /**
     * Mint a token for the person and return the plain text, which is shown once. $abilities is "read" and/or
     * "write", $tools the names to limit it to, $expires a key of expiryOptions(), $tenantSlug the workspace of an
     * mcp/{tenant} path.
     *
     * @param  list<string>  $abilities
     * @param  list<string>  $tools
     */
    public static function mint(object $user, string $label, array $abilities, array $tools = [], string $expires = 'never', ?string $tenantSlug = null): string
    {
        return $user->createToken(
            Str::limit(trim($label), 60, ''),
            self::abilities($abilities, $tools, $tenantSlug),
            self::expiresAt($expires),
        )->plainTextToken;
    }

    /** The MCP endpoint's URL, with the workspace in place of {tenant} when the path carries one. */
    public static function mcpUrl(?string $tenantSlug = null): string
    {
        $path = trim((string) config('packstub-agents.mcp.path', 'mcp'), '/');

        return url('/'.str_replace('{tenant}', $tenantSlug ?? '', $path));
    }

    /** The server's name as a client configuration key. */
    public static function serverSlug(): string
    {
        return Str::slug(Agents::name()) ?: 'assistant';
    }
}
