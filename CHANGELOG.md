# Changelog

All notable changes to `packstub/agents` are documented here.

## 1.11.0 — 2026-10-22

Every tool call is now on record, and a fix for MySQL and MariaDB that you should pick up soon.

- A tool call log (#48): who ran each call, the arguments, the result and the approval decision
- Covers the chat, `AgentRun`, the commands, the email channel and MCP clients; redacted when `redact` is on
- Kept 90 days by default; `AGENT_TOOL_LOG=false` switches it off
- Tool steps keep the provider's stateful continuation (OpenAI, xAI) instead of replaying the history (#36)
- Titles and summaries work again on laravel/ai 1.3 when the provider answers in prose
- MySQL/MariaDB: a chat polled just as its turn finished could deadlock and lose the answer

**Upgrading:** run `php artisan migrate` (new `agent_tool_calls` table); a database-per-tenant app moves `create_agent_tool_calls_table` next to its tenant migrations.

## 1.10.0 — 2026-10-13

- The classifier for typed replies is now pluggable, with a driver for Jev, TypeSafe's decision model (#39)
- With Jev the reply and the proposal questions are sent to TypeSafe; set `TYPESAFE_API_KEY`
- A confidence floor (`AGENT_DECISION_CLASSIFIER_MIN_CONFIDENCE`, 0.8): a less sure decision becomes a question
- The turn records which classifier decided and how sure it was (#42)
- A reply typed after deciding one of two proposals is answered instead of leaving the chat stuck (#44)
- Postgres: chat search ignores case, and a workspace slug no longer crashes a key lookup

## 1.9.0 — 2026-10-07

A reply typed while writes wait for a decision is now read in up to three steps, the first that decides wins (#37).

- Your own rule first, with `Agents::decideTypedUsing()`
- Then the word lists, now in translation files (en, de, es, ro, ru) you can publish to add or change phrases
- Then an optional classifier (`AGENT_DECISION_CLASSIFIER=true`) that decides each proposal on its own
- The turn records what decided it
- A typed yes approves the pending writes only when the reply is nothing but a yes: "Yes, but only Alpha." or "Ok?" used to run them all (#35)

## 1.8.0 — 2026-10-07

- Your app can post a message as the assistant (a digest, a reminder) with `storePostedMessage()` (#21)
- `startConversation()` takes a title, so a chat can be opened without a question
- A first turn can wait and run when the person opens the chat: `AgentTurns::defer()` and `startDeferred()` (#21)

## 1.7.2 — 2026-10-07

Security and workspace fixes found by an audit after 1.7.1.

- Security: entering a workspace with nobody acting is refused too (#30)
- Security: workspace membership is checked again on every tool call, so a removed member's turn stops (#31)
- Security: a record summary by id (mentions, page context, `record://`) now runs the resource's view check
- Budgets are counted per workspace on a shared database; one workspace could use up another's (#24)
- A workspace key that matches nothing refuses the turn instead of running it outside any workspace (#25)
- A refused turn no longer stays pending

Reported by Yusuf Kef (@kefyusuf), thank you.

**Upgrading:** run the migrations (a new index on `agent_turns`) and keep `chat.keep_turns_days` at 31 or more. A job that acts for the app itself inside a workspace passes `system => true` to `AgentRuntime::enter()`.

## 1.7.1 — 2026-10-07

- Security: workspace membership is checked on every path that enters a workspace (`AgentRun`, email, queued turns), not only MCP. Reported by Yusuf Kef, thank you. Also fixed in Filament Agents 1.14.1.
- Security: an MCP path without `{tenant}` is refused once the app has workspaces
- Docs: a shorter Features list in the README and on the docs index

## 1.7.0 — 2026-10-02

Three hooks around a tool call. Nothing changes until you use them.

- Change a tool result before the model reads it with `Agents::mapToolResultsUsing()` (#16)
- A write tool's `preview()` shows what a proposed call would change (#17)
- A `ToolAuthorized` event for every call, allowed or refused (#18)

## 1.6.0 — 2026-10-01

The assistant reaches beyond your records, with guard rails you switch on. Filament Agents 1.13 shows it in a panel.

- A knowledge base it searches and cites, and the provider's web search held to an allow-list
- A prompt guard, redaction of secrets in answers and tool results, and a classification of every chat
- Titles, summaries, the guard and the classification run as structured-output side agents
- Retry any unanswered question, not only the last; a chat remembers the record it is about
- Retry, Regenerate and Edit resend the question's files and mentions; Compress keeps exchanges whole

**Upgrading:** run the migrations (new `agent_conversation_classifications` table). Everything new is off by default. Tests that fake only the assistant fake `TitleAgent` too to test a title; an `Agent` that overrides `middleware()` adds `GuardPrompt` and `SupportedProviderTools` itself; `Agent::tools()` may return provider tools without a `name()`.

## 1.5.0 — 2026-09-28

Moves to laravel/ai 1.0 and laravel/mcp 1.0. Filament Agents 1.12 follows.

- Requires `laravel/ai ^1.0` and `laravel/mcp ^1.0`
- Answers are stored with their steps and reasoning; a turn the provider gave up on keeps what arrived
- A "Reasoning…" status while the model thinks
- Token counts are inclusive now; `AgentUsage` reads old and new rows the same way

**Upgrading:** decide or abandon any waiting proposal first, then run the migrations (they rewrite every row of `agent_conversation_messages`). If you have them:

- Your own middleware wraps each step: `handle(PendingStep $step, Closure $next)`
- Usage keys are `input_tokens` and `output_tokens`; a price's `in` rate applies to uncached input only
- Raw Gemini options use `thinking_level`; MCP errors come back with HTTP 400

## 1.4.0 — 2026-09-25

The chat grows up, and the assistant works outside the chat too. Filament Agents 1.11 shows it in a panel.

- Live updates over server-sent events; files with a question; continue a cut-short answer
- Earlier answers kept on Regenerate and Edit; rename, pin, search and export chats; a note with a thumbs-down
- A cost in money per turn, from prices you fill in
- Ask from code or `packstub-agents:run`, or by email; MCP resources and prompts; turn and tool events
- `AgentEval` to test which tools the agent calls and what it answers
- Fixed: a question sent in the same second as the answer before it no longer sorts above it

**Upgrading:** run the migrations (two new tables and a few new columns).

## 1.3.0 — 2026-09-16

The chat logic moves out of Filament Agents' pages, so you can build your own chat surface without a panel. Filament Agents 1.10 uses it; nothing changes there.

- `AgentChat`: send, decide, retry, stop, rate and read the transcript of one person's chat
- `AgentTokens`: what an agent access form needs, and `mint()` for a scoped token
- Fixed: editing a question while one of two decisions was held no longer breaks the held decision
- Fixed: deleting a chat deletes its ratings

## 1.2.1 — 2026-09-15

- Asking a new question while a proposal waits declines the proposal instead of leaving the chat stuck
- Docs: the OpenRouter key on the installation page, worked examples for the `models` config

## 1.2.0 — 2026-09-10

- Starter questions on an empty chat with `Agent::suggestions()`; Filament Agents 1.9 shows them
- Page context works with a headless resource in a plain Laravel app

## 1.1.0 — 2026-09-10

- A write tool can phrase its proposal as a question with `describe()` ("Confirm order RO-00016 for Acme?")
- A turn no queue worker picks up says so, with the command to run, instead of "Thinking…"

## 1.0.0 — 2026-09-09

First release: the engine of Filament Agents 1.6 in its own package, so a plain Laravel app can use it without Filament. A panel app installs `packstub/filament-agents` ^1.7 and has nothing to run.

- One tool list for the in-app assistant and an MCP server over HTTP
- Writes are proposals the person approves; MCP tokens with read and write, tool scopes, a workspace and an expiry
- Queued turns with a record: provider, model, tokens, tools, duration and how they ended
- Anthropic, OpenAI, Gemini and xAI out of the box, with failover to the next provider
- Long conversations with a rolling summary; budgets and limits per user and per workspace
- Workspaces, database-per-tenant included

Docs: https://packstub.dev/docs/agents
