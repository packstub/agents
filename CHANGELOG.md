# Changelog

All notable changes to `packstub/agents` are documented here.

## 1.7.1 — 2026-10-07

### Security

- **Workspace membership is checked on every path that enters a workspace**, not only on the MCP request. `LaravelContext::enter()` now calls `canAccessTenant()` for the person it enters as (the one given, else the one already signed in on the guard) whenever it is given a tenant, and throws `Packstub\Agents\Exceptions\WorkspaceAccessDenied` ("You are not a member of this workspace.") instead of entering, so `AgentRun::as($user)->in($tenant)` with a workspace the person is not in, and a mail to the email channel whose `tenant` names another workspace, no longer run tools or scope queries inside it. `AgentRun` lets the exception through, the email channel drops the mail without a reply, and a queued turn whose membership was revoked between the question and the worker ends `failed` with that line. The chat path, where the workspace comes from the request, was not affected. Reported privately by Yusuf Kef — thank you. Also fixed in Filament Agents 1.14.1, whose panel context enters the same way.
- **An MCP path without `{tenant}` is refused once the app has workspaces.** `AuthenticateAgent` now answers 404 ("This app has workspaces: put {tenant} in packstub-agents.mcp.path…") when `Agents::tenantModel()` names a workspace model and the route carries no `{tenant}`, instead of running the tools with no workspace to scope them. Apps with workspaces already use `mcp/{tenant}` as the docs say; an app without workspaces keeps the plain path.

### Changed

- **Docs**: a shorter Features list in the README and on the docs index, one line per area; the README's agent section names the providers and the failover list.

## 1.7.0 — 2026-10-02

Three hooks for what an app wants to see and control around a tool call: a say on every result before the model reads it, a preview of what a write would change, and an event for every authorization decision. Nothing changes until you use them.

### Added

- **Change a tool result before the model reads it** (#16). `Agents::mapToolResultsUsing(fn (array $result, AgentTool $tool, Request $request): array)` runs after every tool's `run()`, in the chat and over MCP, in the order given; a callback that throws fails the call as a tool error, so the result it was handed never leaves. `Agents::toolResultMaps()` lists them. See [Changing a result before the model reads it](https://packstub.dev/docs/agents/tools#changing-a-result-before-the-model-reads-it).
- **A preview of what a proposed call would change** (#17). A write tool's `preview(array $arguments)` returns rows of a label with a `before` and/or an `after`, read while the proposal waits; each proposal of `AgentChat::messages()` and of `AgentAnswer::$proposals` carries them as `preview`. `ApprovableTool::preview()` reads them safely: a preview that throws is reported and left out. See [A preview of the change](https://packstub.dev/docs/agents/tools#a-preview-of-the-change).
- **`ToolAuthorized`, fired for every call** (#18), allowed or refused, from the chat and from MCP clients, before the tool runs: the tool, its ability, the arguments, `allowed`, and for a refusal its message and `refusedBy` (`role` or `token`). The tool list does not fire it. See [What the package enforces](https://packstub.dev/docs/agents/security#what-the-package-enforces).

## 1.6.0 — 2026-10-01

The assistant reaches beyond the records and gets guard rails to switch on: a knowledge base it searches and cites, the provider's web search held to an allow-list, a prompt guard in front of every question, redaction of secrets in answers and stored tool results, and a classification of every chat. The housekeeping calls become structured-output side agents, and a question that never got its answer can be retried wherever it sits. Filament Agents 1.13 shows it in a panel.

Upgrading: run the migrations (one new table, `agent_conversation_classifications`). Everything new is off until you switch it on, and every existing method keeps its signature. Three things to know:

- **Tests that fake the assistant.** A new chat's title is written by `TitleAgent`, so with only the assistant faked the title stays the first question (it was the fake's next answer before) and the assistant's fake list is no longer used up by it. Fake `TitleAgent` to test a title: `TitleAgent::fake([['title' => '…']])`.
- **`Agent::tools()`** may return laravel/ai provider tools (`WebSearch`, `FileSearch`) next to the MCP tools once web search or a provider vector store is switched on; they have no `name()`.
- **An `Agent` subclass that overrides `middleware()`** adds `GuardPrompt` and `SupportedProviderTools` itself to use the prompt guard and the provider tools (see the base method).

### Added

- **Retry on every unanswered question** (packstub/filament-agents#19). `AgentChat::messages()` marks a question followed by another question as `unanswered`, not only the last one, and says on each how its last turn ended (`ended`: status, reason, error). `AgentChat::retry($messageId)` sends an earlier one again: it moves to the end of the chat (`AgentConversationStore::moveQuestionToEnd()`), its earlier answers and turns following the new id, so the answer lands under it. `retry()` without an id is the last question, as before.
- **The record a chat is about stays with it** (packstub/filament-agents#40). The page context a question was asked with is recorded on it, and `AgentChat::for($user, $conversation)` without a context takes the conversation's (`AgentConversationStore::contextOf()`; a chat from before reads its newest turn). `AgentChat::contextUrl()` and `PageContext::url($ref)` give the record's page, for a surface to link the label.
- **Structured-output side agents** (packstub/filament-agents#31). The title, the rolling summary, the classification and the prompt guard's verdict are asked of `Packstub\Agents\Ai\Side` agents (`TitleAgent`, `SummaryAgent`, `ClassifierAgent`, `GuardAgent`, on a shared `SideAgent`): laravel/ai agents with a schema, run on the provider's cheapest model and faked on their own in a test. A provider that answers in prose still titles and summarizes. See [Side agents](https://packstub.dev/docs/agents/assistant#side-agents).
- **Classification** (packstub/filament-agents#31). With `AGENT_CLASSIFY=true` every answered question is followed by one cheap call that says what the chat is about (`topic`, from `classify.topics` when you give a list), how the person sounds (`sentiment`) and whether it is `resolved`, kept in `agent_conversation_classifications` (`ConversationClassification`) and read with `AgentChat::classification()`. See [Classification](https://packstub.dev/docs/agents/assistant#classification).
- **A prompt guard** (packstub/filament-agents#28). With `AGENT_PROMPT_GUARD=true` the `GuardPrompt` middleware has `GuardAgent` classify each question before the assistant reads it — `safe`, `injection`, `jailbreak`, `data_exfiltration`, `off_topic` — on the turn's provider or on one of its own (`prompt_guard.provider`, `prompt_guard.model`; a local model works). A category on `prompt_guard.refuse` ends the turn as refused with a friendly line under the question; anything but safe is logged with its reason and fires `PromptFlagged`. It fails open by default. See [The prompt guard](https://packstub.dev/docs/agents/security#the-prompt-guard).
- **Redaction** (packstub/filament-agents#29). With `AGENT_REDACT=true` `AgentRedactor` replaces payment card numbers (Luhn-checked), US social security numbers, API keys and tokens, your own patterns (`redact.patterns`) and what your callback finds (`Agents::redactUsing()`): in every snapshot of an answer while it streams, with the value under way held back so it is never shown and then withdrawn; in the stored answer, its steps and a stopped answer; and in the tool results stored with it, JSON kept as JSON. A turn that had something replaced writes one `critical` log line and fires `OutputRedacted` with the kinds. See [Redaction](https://packstub.dev/docs/agents/security#redaction).
- **A knowledge base** (packstub/filament-agents#32). `Agents::knowledgeBase(Article::class, 'embedding', …)` (or config `knowledge_base`) registers the app's own documents: the new `search-knowledge-base` tool runs a similarity search over the embedding column (pgvector, laravel/ai embedding the question) and returns the closest articles with their title, url and an excerpt, for the chat and for MCP clients, joining whatever tool list is served; the prompt tells the assistant to answer how-to and policy questions from it and cite what it used. `php artisan packstub-agents:embed` fills the embeddings (`--fresh`, `--chunk`, `--tenant`). `using` takes a search of your own in place of the similarity query; `stores` hands the chat laravel/ai's `FileSearch` over provider-hosted vector stores. See [Knowledge base](https://packstub.dev/docs/agents/tools#knowledge-base).
- **Web search, held to an allow-list** (packstub/filament-agents#33). With `AGENT_WEB_SEARCH=true` the chat gets laravel/ai's `WebSearch` provider tool, limited to `web_search.allow` (`AGENT_WEB_SEARCH_ALLOW`), to `web_search.max` searches per turn and to an approximate `location`; the prompt keeps workspace data and web information apart and asks for a link per web fact. `AgentChat::messages()` returns the cited pages as `sources` and the provider's searches as read-only entries of `tools`; the status line says "Searching the web…". A step on a provider without it runs without it (`SupportedProviderTools`), so a failover or a local model still answers. See [Web search](https://packstub.dev/docs/agents/tools#web-search).
- `Agents::optInTools()`, `knowledge()`, `redactor()`; the `PromptFlagged` and `OutputRedacted` events; `AgentChat::providerCalls()` and `sources()`; `RunAgentTurn::providerToolStatus()`.
- The new strings ship in the German, Spanish, Romanian and Russian files.

### Fixed

- **Retry, Regenerate and Edit send the whole question.** They sent its text alone, so a question asked with a file or an `@` mention was answered again without them. The files attached to the question and the records it mentions now go with it each time.
- **Compress kept an answer without its question.** `AgentConversationStore::compactNow()` counted the exchanges to keep one row short, keeping the answer before them while folding its question into the summary; the next turn then summarized that answer on its own. An exchange is now kept or folded whole.

## 1.5.0 — 2026-09-28

The engine moves to laravel/ai 1.0 and laravel/mcp 1.0, and takes what they bring: an answer stored with its steps and reasoning, a failed turn on record, middleware around every model round-trip, inclusive token counts. Filament Agents 1.12 follows.

Upgrading: `composer update packstub/agents` pulls laravel/ai 1.0 and laravel/mcp 1.0; run the migrations (one, on `agent_conversation_messages`: it adds `steps` and `status` and rewrites every existing message, see below). Decide or abandon any proposal still waiting first: it is carried over as pending, but a turn that paused on the 0.x provider state cannot resume from it. Then, if you have any:

- **Your own middleware** now wraps each step of a turn, not the turn: `handle(PendingStep $step, Closure $next)` in place of `handle(AgentPrompt $prompt, Closure $next)`, `$next($step)->then(fn (StepResponse $r) => …)` in place of `->then(fn (AgentResponse $r) => …)`. `$step->isFirstStep()` tells the question from the tool steps; `EnforceBudget::question($step)` is what the person typed (null on a tool step and on a resume, where `$prompt->hasApprovalDecisions()` was true). `withInstructions()`, `withMessages()`, `withTools()`… hand a copy on; `append()` / `prepend()` are gone with the prompt. See [Middleware](https://packstub.dev/docs/agents/assistant#middleware).
- **Code reading a stored message** finds the answer's model round-trips in `steps` (each tool call with its `result`, `denied`, `failed` and, while a proposal waits, its `approval_reason`) under a `status` (`completed`, `paused`, `failed`); `ConversationMessage::$tool_calls` and `$tool_results` still read them, flattened. The `tool_calls`, `tool_results` and `approval_state` columns stay, empty and nullable, until 2.0 (`AgentConversationStore::callsOf($row)` reads either shape).
- **Code reading a turn's usage** (`AgentTurn::$usage`, the log line, `AgentPricing::cost()`) gets laravel/ai 1.0's inclusive keys, `input_tokens` (the cached tokens included) and `output_tokens` (the reasoning included), in place of `prompt_tokens` and `completion_tokens`; `tokensIn()`, `tokensOut()`, the budget counters and the pricing read a row stored before the upgrade the same way (`AgentUsage`). A price's `in` rate now applies to the uncached input only.
- **A resumed turn** (a proposal decided with the buttons) is folded into the answer it paused on — one message per turn, as laravel/ai 1.0 stores it — so a transcript counts one assistant message where it counted two. A decision typed in words ("Yes, go ahead.") keeps its reply row, and the answer to it is stored after the reply.
- **MCP clients** get a JSON-RPC error (an unknown tool, a resource they may not read) with HTTP status 400 rather than 200, as laravel/mcp 1.0 answers; nothing changes for a client that reads the body.

### Added

- **Reasoning and failed turns in the transcript.** `AgentChat::messages()` returns what the model thought before it answered (`reasoning`, joined across the steps, when the provider reports it) and marks an answer the provider gave up on midway (`failed`, `error`): laravel/ai 1.0 stores such a turn with the steps it completed, so the person sees what arrived and can Regenerate instead of Retry.
- **A status line while the model reasons.** The turn reports `Reasoning…` when the provider streams reasoning, before `Writing…`; a sub-agent's preliminary results are left out of the tool timeline.
- **`AgentUsage`.** `in()`, `out()`, `total()` and `priced()` read a usage array written by laravel/ai 1.0 (inclusive counts) or before (`prompt_tokens`…) the same way; every reader in the package goes through it.
- **`AgentConversationStore::callsOf($row)` and `stepsFromLegacyRow($row)`.** The calls of a stored message across its steps, and the steps a 0.x row translates to — what the migration and a restored answer version use.

### Changed

- Requires `laravel/ai ^1.0` and `laravel/mcp ^1.0`.
- Gemini takes the effort as `thinking_level` (`low`, `medium`, `high`) on the Interactions API laravel/ai 1.0 speaks, in place of `thinkingConfig.thinkingLevel`; an app that passes raw Gemini options through `providerOptions()` renames them the same way (see laravel/ai's upgrade guide).
- `EnforceBudget` and `AttachContext` run on `PendingStep`: the budget is checked and counted on the first step of a turn; the dynamic block is put on the question again on every step, so the model reads the same messages while it calls tools (`AttachContext` takes the agent in its constructor; `Agent::middleware()` passes it).
- `AgentConversationStore::storeUserMessage()` and `declinePending()` follow the 1.0 `ConversationStore` signatures; `pendingCalls()` and `pausedRows()` read paused answers by status; the cache breakpoint on Anthropic is a replay block (`AssistantMessage::$replayBlocks`).
- A resume the budget refuses (the assistant switched off, a limit reached) is refused before the stream starts: laravel/ai 1.0 runs an approved tool before the first step's middleware sees the turn, so the check moved ahead of it and the proposal stays waiting.
- An error the provider reports in the stream ends the turn through laravel/ai, which records the failed answer with the steps it completed (Regenerate under it), where the job threw first and left the question unanswered.
- The fresh-install migration creates `agent_conversation_messages` in the 1.0 shape (`steps`, `status`, the `participant_index` with the agent); the new `add_steps_to_agent_conversation_messages_table` migration upgrades an existing table and backfills it in chunks.

## 1.4.0 — 2026-09-25

The chat grows up: live updates over an event stream, files with a question, an answer continued or paged through its earlier versions, chats renamed, pinned, searched and exported, a rating with a note, a cost in money. And the assistant reaches beyond the chat: a headless run from a command or the scheduler, an email channel, MCP resources and prompts, events, and an eval harness for tests. Filament Agents 1.11 shows all of it in a panel.

Upgrading: run the migrations (two new tables, `agent_answer_versions` and `agent_pinned_conversations`; a `note` and a `turn_id` on `agent_message_feedback`; a `cost` on `agent_turns`). Nothing else changes: every existing method keeps its signature, the new arguments are optional.

### Added

- **An event stream.** `GET {chat.path}/chat/{conversation}/stream` pushes the turn state as server-sent events whenever it changes (`chat.stream_interval`, 150 ms; the stream closes after `chat.stream_seconds` and the browser reconnects), with an `end` event once nothing runs; the poll endpoint keeps returning the same object, now with the tools called so far (`AgentTurns::state($conversation)` builds both). See [Live updates](https://packstub.dev/docs/agents/assistant#live-updates).
- **Attachments.** `AgentChat::send($prompt, [$file])` sends laravel/ai files with the question; `AgentAttachments::store($upload)` puts an upload on the attachments disk (config `chat.attachments`: disk, directory, size cap, MIME types) and returns one, `describe()` gives a surface its name, type and URL, the files are deleted with the conversation. `AgentRun::with()` and `AgentEval::with()` take the same list.
- **Continue.** `AgentChat::continueAnswer()` carries on an answer the model's length limit cut short; `messages()` marks the continuation question (`continuation`, hidden by a surface) and the answer that follows (`continued`), and offers it as `continuable` on the last answer.
- **Answer versions.** Regenerate and Edit keep the earlier answer (`agent_answer_versions`, `AgentAnswerVersion`): `messages()` counts them on the question (`versions`), `AgentChat::versions($questionId)` lists them (each dated when its answer was given), `showVersion($questionId, $versionId)` puts one back and the chat carries on from it. `AgentConversationStore::dropMessagesAfter()` archives by default (`keepVersion: false` to delete), `versionsOf()` and `restoreVersion()` are the store's side.
- **Rename, pin, search, export.** `AgentChat::rename()`, `pin()`, `unpin()`, `pinned()` and `AgentChat::pinnedIds($user)` (`agent_pinned_conversations`); `AgentChat::search($user, $words)` finds the person's chats by message or title with a snippet; `transcript()` is the chat as Markdown.
- **A rating with a note and its turn.** `AgentChat::rate($messageId, $rating, $note)` stores what the person said with a thumbs-down (`note`) and the turn that produced the answer (`turn_id`), so an operator's turn log can show the rating; `messages()` returns `ratingNote`.
- **Cost in money.** Config `pricing.models` holds prices per million tokens by model name (a prefix matches every dated variant); when a turn ends its `cost` is computed from its usage (`AgentPricing::cost()`, `format()`, `currency()`), summed per chat in `AgentChat::history()['turns']['cost']` and written to the log line. No prices ship; the cost stays null until you fill them in. See [Cost in money](https://packstub.dev/docs/agents/budgets-and-limits#cost-in-money).
- **Events.** `TurnStarted`, `ToolCalled` (call id, tool, arguments), `ProposalDecided` (the call as proposed, approved or not) and `TurnEnded`, each with the `AgentTurn`.
- **The assistant without a chat.** `AgentRun::as($user)->in($team)->ask($question)` runs a turn inside the call whatever `chat.driver` says and returns an `AgentAnswer` (text, HTML, tools, turn, conversation, the proposals left waiting); `php artisan packstub-agents:run "…" --user= --tenant= --model= --context= --conversation= --json` does the same from the console. `AgentChat::sync()` is the switch underneath. See [The assistant without a chat](https://packstub.dev/docs/agents/assistant#the-assistant-without-a-chat).
- **The assistant by email.** `POST {chat.path}/email` behind a shared secret (`AGENT_EMAIL`, `AGENT_EMAIL_SECRET`) takes a mail provider's inbound webhook: a person's mail is asked as them and answered by reply (`AgentAnswerMail`), a reply continues the chat by the subject tag or the threading headers, a stranger's mail is dropped. `Agents::participantByEmailUsing()` names the person behind an address; `EmailChannel`, `InboundEmail` and `AuthenticateEmailWebhook` are the parts. See [The assistant by email](https://packstub.dev/docs/agents/assistant#the-assistant-by-email).
- **MCP resources and prompts.** Every server serves `agents://resources` (the agent resources with their filter vocabulary), `record://{resource}/{id}` (one record summarized), and the prompts `what-needs-attention` and `ask-about-record` — a subclass lists its own `$resources` and `$prompts` to replace them. See [Resources and prompts](https://packstub.dev/docs/agents/mcp-clients#resources-and-prompts).
- **An eval harness.** `Packstub\Agents\Testing\AgentEval::as($user)->expecting([...])->ask($question)` runs the whole engine on a faked provider and asserts which tools the agent called with which arguments, what it proposed and what it answered (`assertCalled()`, `assertCalledInOrder()`, `assertProposed()`, `assertAnswerContains()`, `assertRefused()`…); `then()` continues the conversation, `decide()` answers a proposal. See [Evals](https://packstub.dev/docs/agents/testing#evals).
- `AgentTurns::owned($conversation, $user)` is the ownership check the endpoints share; `AgentTurns::snapshot()` takes the tools called so far; `AgentConversationStore::storeQuestion()` takes attachments and meta, `renameConversation()`, `isContinuation()`, `attachmentsOf()`.
- The new strings ship in the German, Spanish, Romanian and Russian files, with three that were missing ("Approved", "Rejected", the daily-limit refusal).

### Fixed

- **A question in the same second as the previous answer.** `AgentChat::messages()` ordered the rows by their timestamp and put a question written within the same second as the answer before it above that answer; the rows are UUIDv7 and now sort by id, the order they were written in.

## 1.3.0 — 2026-09-16

The chat logic Filament Agents' pages held moves here, so a chat surface of your own — a JSON API, a Livewire or Inertia page, a command — reads and drives a conversation without a panel. Filament Agents 1.10 delegates to these classes; nothing changes for it.

### Added

- **`AgentChat`.** One person's chat without a UI: `AgentChat::for($user, $conversation, $model, $context)` (the person an Eloquent model; a conversation that is not the person's is not found), then `send()`, `decide()`, `retry()`, `regenerate()`, `resend()`, `stop()`, `removeQueued()`, `editQueued()`, `rate()` (a message outside the chat is not found), `compress()` (false when nothing is older; `ChatBusy` while a turn runs or a decision waits, since a surface offers it while idle), `continueInNew()`, each returning the `AgentTurn` it queued where one is; `messages()` (the transcript with each proposal's question and state, charts, tables, ratings and what may be offered on the last exchange), `live()`, `idle()`, `history()` (the context meter and what the chat cost), `suggestions()`, `title()`, `owns()`, `ownConversations()`; and the static helpers a surface phrases things with (`question()`, `resultText()`, `chartFromResult()`, `tableFromResult()`, `cutShortText()`, `duration()`, `breakdownLabels()`, `modelMenu()`, `writeToolNames()`). See [A chat surface of your own](https://packstub.dev/docs/agents/assistant#a-chat-surface-of-your-own).
- **`AgentTokens`.** What an agent access form needs — `availableTools()`, `toolTitles()`, `expiryOptions()`, `mcpUrl()`, `serverSlug()` — and `mint($user, $label, $abilities, $tools, $expires, $tenantSlug)`, which scopes the token to the named tools the role allows (a write tool only on a token that may write), binds it to the workspace and sets the expiry. Naming only tools that cannot be scoped, or an expiry that is not "never" or a number of days, refuses the token rather than minting one without a scope or without an expiry.
- **`ApprovableTool::phrase($title, $arguments)`** is the one phrasing of a title and the first argument as a question, used for a tool without a sentence of its own and for a proposal whose tool is no longer registered.
- **`AgentTurn::statusLabel($status)`** gives a turn's status as a person reads it; **`AgentConversationStore::deleteConversation($id)`** deletes a conversation with its messages and rolling summary, keeping its turns in the operator's log.
- The strings these emit ("Rolling summary", "Queued", ":days days"…) ship in the package's German, Spanish, Romanian and Russian files, with the two cut-short reasons that had no translation.

### Fixed

- **A held decision keeps the chat busy.** Approving one of two proposals holds the decision until the other is decided; the chat still counted as idle meanwhile, so editing or regenerating the question under it deleted the paused answer and the held decision failed when it ran. `AgentChat::idle()` (and `messages()`'s `editable` / `regenerable`) now count a held decision, `live()` lists it under `held`, and only the other decision is accepted until both are in. Filament Agents' page had the same gap.
- **Deleting a chat deletes its ratings.** `deleteConversation()` removes the `agent_message_feedback` rows of the messages it deletes, as dropping an answer already did.

## 1.2.1 — 2026-09-15

### Changed

- **Docs.** The installation page shows the OpenRouter key next to the other providers. The configuration page's `models` section is a field table and two worked examples: a provider without entries of its own (OpenRouter, pinned to Mercury 2.5, GLM 5.3 Flash and DeepSeek 4.1 Flash) and a mixed catalog (Claude, Gemini Flash and a local Ollama model, with the keys and the failover rules as a list); the assistant page points there instead of repeating the rules.

### Fixed

- **A question over a pending proposal.** Asking something else while a proposal waited for Approve / Reject left the conversation with two proposals and no decision that could be applied ("Approval decisions do not match the pending tool calls"): laravel/ai cannot continue over a pending call, and the next answer proposed the same change again. A question now declines what is still pending first, recorded like a rejection with a note the model reads (`AgentTurns::supersededResult()`), so the chat shows the earlier proposal as declined and the new answer stands on its own.

## 1.2.0 — 2026-09-10

### Added

- **Starter questions.** `Agent::suggestions()` returns the questions an empty chat offers as one-click prompts, in the person's language: by default what needs attention today, the latest records of the first two agent resources and what the assistant can do, or, when the chat was opened from a record, two questions about that record. An app returns its own from the domain ("Which orders are waiting for a phone call?"). Filament Agents 1.9 shows them on a new chat.

### Fixed

- **Page context without a panel.** `PageContext::resolve('widgets/12')` resolved the record through a Filament resource method, so a headless `AgentResource` (one registered with `Agents::useResources()` in a plain app) raised an error instead of a label; it now falls back to the resource's `getEloquentQuery()` or its model.

## 1.1.0 — 2026-09-10

### Added

- **A proposed call as a question.** `AgentTool::describe(array $arguments): ?string` lets a write tool phrase its own calls ("Confirm order RO-00016 for Acme?"); `ApprovableTool::question($tool, $arguments)` returns that sentence, or the tool's title and the first scalar argument when the tool has no `describe()`, and `ApprovableTool` passes it as the approval's reason, so the pending approval stored by laravel/ai carries the sentence a client shows. Filament Agents 1.8 renders the proposal with it.
- **A missing worker is named.** A turn handed to the queue that no worker takes within `chat.worker_wait` seconds (`AGENT_WORKER_WAIT`, 10) gets a status line that says so, with the command to run or the sync driver to set, instead of "Thinking…" until `chat.job_timeout`. `AgentTurns::statusText($turn)` gives a chat surface the line the poll endpoint returns; `awaitingWorker($turn)` the bare check.

## 1.0.0 — 2026-09-09

The engine of [packstub/filament-agents](https://github.com/packstub/filament-agents) 1.6, extracted into its own package so a plain Laravel app can install it without Filament. Same `Packstub\Agents\` namespace, same `config/packstub-agents.php` and environment variables, same migration file names, same class names: a panel app installs `packstub/filament-agents` ^1.7, which requires this package, and has nothing to run.

### Added

- **One tool list for the agent and the MCP server.** `AgentTool` is a `laravel/mcp` tool with an `$ability`, a `run()` returning data for the model and domain errors mapped to tool errors; `AgentServer` carries the name, the instructions and the `$tools`; the app names them with `Agents::useServer()` or `Agents::useTools()`. The `packstub-agents:tool` and `packstub-agents:agent` scaffolds and the `packstub-agents:install` command print the service-provider registration, or the plugin call when the Filament layer is installed.

- **Writes are proposals.** A tool without `#[IsReadOnly]` is wrapped as an `ApprovableTool` for the agent, so laravel/ai pauses the turn until the person approves or rejects it; over MCP it needs a write token and then runs directly with the person's role.

- **MCP over HTTP.** `POST /mcp` behind `throttle`, `auth:sanctum` and `AuthenticateAgent`, registered whenever `mcp.enabled` is on (the package's own server until the app names one). Sanctum tokens carry `read` / `write` abilities, `tool:{name}` scopes that limit a token to named tools, `tenant:{slug}` that binds it to a workspace, and an optional expiry; a read token cannot run write tools, a scoped token sees only its tools. `AgentTool::tokenRefusal()`, `accessToken()`, `tokenTools()` and `tokenIsScoped()` expose the checks to the app's own tools.

- **Queued turns with a record.** `AgentTurns::enqueue()` and the `RunAgentTurn` job produce an answer in a worker (or inside the request with `chat.driver` = `sync`), stream it into an `agent_turns` row, honour Stop, run follow-ups in order per conversation, and keep the record when the turn ends: provider and model, tokens (prompt, completion, cache reads and writes, reasoning), tools called, duration and how it ended. `GET {chat.path}/chat/{conversation}/turn` under `chat.middleware` reads the answer so far. `log.channel` writes one line per ended turn; `chat.keep_turns_days` prunes them with `model:prune`.

- **Who is acting and where.** `Contracts\AgentContext`, bound as `Support\Context\LaravelContext`: the person on the guard in use, the workspace from `Agents::tenantUsing()`, membership through the user's `canAccessTenant()`, the workspace model and slug from `Agents::tenantModel()`, and `Agents::enteringTenant()` for what a database switch needs when a worker or an MCP request enters a workspace — what it returns runs on leaving. A turn records the guard it was asked on and the worker signs the person in on it.

- **The base `Agent`.** `persona()` and `domain()` slots on top of generic working and answering rules, a dynamic block (date, workspace, person, role, language, page context) prepended to the question by the `AttachContext` middleware so the system prompt and the settled history stay cacheable, Anthropic cache breakpoints, reasoning effort or thinking level per model, and a middleware pipeline: `EnforceBudget` first, the app's own classes after it (`Agents::useMiddleware()` or config `middleware`), `TurnRefused` to stop a turn with a message.

- **Providers, models and failover.** A `models` catalog per provider (Anthropic, OpenAI, Gemini, xAI out of the box, entries named after their model; any other laravel/ai text provider on its smartest and cheapest models), entries that run on another provider, `AgentModels::resolve()` with the ordered provider list a turn runs on, and `failover` (`AGENT_FAILOVER`) to move to the next provider when the first refuses a turn before answering, with `AgentFailedOver` fired and the answering provider recorded.

- **Long conversations.** `AgentConversationStore` replays a token-budgeted window (`history.max_tokens`), replaces old tool results with a placeholder, folds what falls out into a rolling summary in `agent_conversation_summaries` extended in place, and offers `compactNow()` and continue-in-a-new-chat.

- **Budgets and limits.** Questions per minute per user, answers and tokens per day and per month per workspace, tokens per day and per month per user, and a prompt length cap, from `config/packstub-agents.php` and overridden by `agent_limits` rows (global, per workspace, per user; empty fields inherit) on `limits_connection`. `AgentBudget::refusal()` and `summary()`, `AgentLimits::effective()`.

- **Filters and summaries.** `Contracts\AgentResource` with `agentKey()`, `agentSummary()`, `agentContextLabel()` and `agentFilters()`, the `Filter` vocabulary (text, enum, boolean, flag, date, number) with its JSON schema, normalisation and query closure, `AgentResources` to share it with the app's search tools, `PageContext` for the record a chat was opened from, and the generic `draw-chart` tool; `Concerns\InteractsWithAgent` gives a Filament resource the defaults.

- **Tenancy.** `mcp/{tenant}` in the MCP path resolves the workspace by slug, checks membership and the token's `tenant:{slug}` ability and enters it before any tool runs; `credentialsUsing()` lets a workspace bring its own provider, key and model; `run_migrations` and `limits_connection` for database-per-tenant apps.
