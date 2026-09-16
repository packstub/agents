# MCP clients

The same tools your agent uses are served over HTTP as an MCP server, so Claude Code, Claude Desktop, Cursor or any client that speaks the Model Context Protocol can work inside your app as the person who minted the token, in their workspace, with their role.

## Tokens

A token is a Sanctum personal access token whose abilities say what the client may do:

```php
// Read-only, for a reporting agent.
$token = $user->createToken('laptop', ['read'])->plainTextToken;

// Read and write, limited to two tools and a month.
$token = $user->createToken('queue', ['read', 'write', 'tool:search-orders', 'tool:confirm-order'], now()->addDays(30))->plainTextToken;

// Bound to a workspace, for an mcp/{tenant} path.
$token = $user->createToken('acme', ['read', 'tenant:'.$team->slug])->plainTextToken;
```

- **`read`** looks things up and runs reports; **`write`** changes data through the tools, still limited by the person's role.
- An **expiry** is Sanctum's `expires_at`, so an expired token is refused by `auth:sanctum` like any other.
- **`tool:{name}`** abilities limit the token to exactly those tools: the others are not listed and are refused by name. Without any, the token has every tool the role allows.
- **`tenant:{slug}`** binds the token to one workspace's URL, see [Tenancy](tenancy.md).

A token can only narrow what the role allows, never widen it: the role is checked again on every call, so a tool the role loses later is refused even when the token names it. `Packstub\Agents\Support\AgentTokens` has what a token form needs: `availableTools()` lists the tools the person's role allows right now (name, title, description, read-only), `toolTitles()` every tool of the server so a list can name one a token was scoped to after the role lost it, `expiryOptions()` the expiry choices, `mcpUrl($tenantSlug)` and `serverSlug()` the connection snippet's parts, and `mint($user, $label, ['read', 'write'], $tools, $expires, $tenantSlug)` mints the token — a write tool is only scoped on a token that may write, a tool the role does not allow never — and returns the plain text, which you show once. Tokens are regular Sanctum tokens, so `$user->tokens()`, `expires_at` and Sanctum's pruning work as usual.

The client connects with the token as a bearer header:

```bash
claude mcp add --transport http acme https://acme.test/mcp --header "Authorization: Bearer 3|…"
```

```json
{ "mcpServers": { "acme": { "type": "http", "url": "https://acme.test/mcp", "headers": { "Authorization": "Bearer 3|…" } } } }
```

**In a Filament panel**, the Agent access page of [Filament Agents](https://packstub.dev/docs/filament-agents/mcp-clients) mints these tokens for the signed-in person: abilities, expiry, a picker of the tools the role allows, the workspace, the connection snippets, and a table to revoke them.

## The endpoint

`POST /mcp` by default (`packstub-agents.mcp.path`), registered with `Mcp::web()` whenever `mcp.enabled` is on — on the app's server class, or the package's own `AgentServer` with the tools given to `Agents::useTools()` until one is named. The middleware stack:

```php
'middleware' => ['throttle:60,1', 'auth:sanctum', AuthenticateAgent::class],
```

Ahead of that stack the package always runs `AcceptJson`, which makes the request one that accepts JSON: a request that fails authentication (no token, a revoked or expired one) gets a JSON `401 {"message": "Unauthenticated."}` whatever `Accept` header the client sent, never the framework's redirect to a login route.

`AuthenticateAgent` puts the request into the same shape as any request of the person: the token's user is the current one on the guard the request authenticated on, the person's locale is applied, and, when the path carries `{tenant}`, the workspace is resolved and entered (see [Tenancy](tenancy.md)). Every tool then behaves exactly as it does for your own agent.

Set `AGENT_MCP_ENABLED=false` to remove the route.

## What a client sees

- `tools/list` returns the tools the person's role **and the token** allow (each tool's `shouldRegister()` checks its ability, then the token), in the order of the server's `$tools`, with the server's name and instructions.
- A **read** token lists and runs read-only tools; write tools are not on its list, and calling one by name gets the protocol's "Tool [confirm-order] not found." error.
- A **write** token runs write tools directly with the token holder's role. There is no approval step over MCP: the client is the agent the person chose to trust, and the server's instructions tell it to read the record first when in doubt.
- A **scoped** token (one or more `tool:{name}` abilities) lists only those tools; any other is "not found" to it, even one the role allows.
- A tool the role does not allow is not listed; a direct call returns the refusal ("Your role (Viewer) is not allowed to do this.").

The token checks live in `AgentTool::tokenRefusal()`, which returns why the current token may not run the tool ("This access token is read-only.", "This access token does not include update-license.") or null; `handle()` calls it too, so a tool invoked outside the server is refused with that message. `AgentTool::accessToken()` gives the current personal access token (null for your own agent and for Sanctum's transient session token), `tokenTools($token)` the names a token is limited to, `tokenIsScoped($token)` whether it is — useful when an app gates a tool of its own that does not extend `AgentTool`, or wants to show what a token may do.

## Testing the endpoint

```php
$token = $user->createToken('desk', ['read', 'write'])->plainTextToken;

postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [
    'Authorization' => 'Bearer '.$token,
    'Accept' => 'application/json, text/event-stream',
    'MCP-Protocol-Version' => '2025-06-18',
])->assertOk()->assertJsonPath('result.tools.0.name', 'search-orders');
```

See [Testing](testing.md) for the full example.
