<?php

use Packstub\Agents\Http\Middleware\AuthenticateAgent;

/*
|--------------------------------------------------------------------------
| Packstub Agents — the in-panel assistant and the MCP server
|--------------------------------------------------------------------------
|
| Provider credentials live in config/ai.php (ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY, XAI_API_KEY…).
| This file says which provider the platform uses by default, which models the
| picker offers and how much a workspace may spend. Most of it can also be set
| fluently on AgentsPlugin in the panel provider; the plugin mirrors those
| values here so every runtime (queue, console, MCP requests) sees one truth.
|
*/

return [
    // How the assistant introduces itself in the panel ("Ask Acme"). AgentsPlugin::make()->name() overrides it.
    'name' => env('AGENT_NAME', 'Assistant'),

    // The panel the assistant lives in. Set by AgentsPlugin when it registers; stays null without Filament.
    'panel' => null,

    // 'anthropic', 'openai', 'gemini' or 'xai' have picker entries below; any other laravel/ai text provider (ollama,
    // openrouter, mistral, groq, deepseek…) runs on its smartest and cheapest models. A workspace may bring its own.
    'provider' => env('AGENT_PROVIDER', 'anthropic'),

    // Providers to fall back to, in order, when the platform provider refuses a turn before it started answering
    // (overloaded, rate limited, unreachable, out of credits): AGENT_FAILOVER=gemini,openai. Each runs the picker
    // entry of its own catalog below (or its smartest / cheapest model) and needs its key in config/ai.php; the
    // answer says which provider it came from. A workspace on its own key has no fallback.
    'failover' => array_values(array_filter(array_map('trim', explode(',', (string) env('AGENT_FAILOVER', ''))))),

    // null = enabled when a key exists for the provider in use (platform or workspace) or for the provider of any
    // picker entry below. AGENT_ENABLED=false hides the chat.
    'enabled' => env('AGENT_ENABLED'),

    // What the model picker offers: the list of the provider in use. A null label names the entry after its model
    // ("Claude Opus 5"; a second entry on the same model adds its key: "Claude Opus 5 · Deep"); set one to show
    // something else ("Fast"). A null model means "the provider's smartest" (auto, deep) or "the provider's cheapest"
    // (fast) as laravel/ai knows them; AGENT_MODEL* pin explicit names. Effort is passed as Anthropic
    // output_config.effort, OpenAI and xAI reasoning.effort (reasoning models only) or Gemini's thinking level (low,
    // medium, high; xhigh is sent as high). A provider without entries here gets its smartest (auto) and cheapest
    // (fast) models with no effort.
    //
    // An entry may name another provider to run on, so one picker offers Claude and Gemini side by side; it is
    // listed when that provider has a key in config/ai.php, under a provider heading, and its effort is in that
    // provider's terms. A 'failover' list on an entry replaces the global one for it ([] keeps a local model local).
    // A workspace on its own key sees only the entries of its provider.
    'models' => [
        'anthropic' => [
            'auto' => ['label' => null, 'model' => env('AGENT_MODEL', 'claude-opus-5'), 'effort' => 'medium'],
            'fast' => ['label' => null, 'model' => env('AGENT_MODEL_FAST', 'claude-haiku-4-5'), 'effort' => null],
            'deep' => ['label' => null, 'model' => env('AGENT_MODEL_DEEP', 'claude-opus-5'), 'effort' => 'xhigh'],
            // 'flash' => ['label' => 'Gemini Flash', 'provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite', 'effort' => 'low'],
            // 'local' => ['label' => 'Local', 'provider' => 'ollama', 'model' => 'llama3.3', 'effort' => null, 'failover' => []],
        ],
        'openai' => [
            'auto' => ['label' => null, 'model' => env('AGENT_MODEL'), 'effort' => 'medium'],
            'fast' => ['label' => null, 'model' => env('AGENT_MODEL_FAST'), 'effort' => 'low'],
            'deep' => ['label' => null, 'model' => env('AGENT_MODEL_DEEP'), 'effort' => 'high'],
        ],
        'gemini' => [
            'auto' => ['label' => null, 'model' => env('AGENT_MODEL', 'gemini-3.8-flash'), 'effort' => 'medium'],
            'fast' => ['label' => null, 'model' => env('AGENT_MODEL_FAST', 'gemini-3.5-flash-lite'), 'effort' => 'low'],
            'deep' => ['label' => null, 'model' => env('AGENT_MODEL_DEEP', 'gemini-3.8-flash'), 'effort' => 'high'],
        ],
        'xai' => [
            'auto' => ['label' => null, 'model' => env('AGENT_MODEL', 'grok-4.6'), 'effort' => 'medium'],
            'fast' => ['label' => null, 'model' => env('AGENT_MODEL_FAST', 'grok-4.6'), 'effort' => 'low'],
            'deep' => ['label' => null, 'model' => env('AGENT_MODEL_DEEP', 'grok-4.6'), 'effort' => 'xhigh'],
        ],
    ],

    // How many tool round-trips one turn may take before the agent has to answer, and how long an answer may be.
    'max_steps' => 12,
    'max_tokens' => 4096,

    // Long chats replay fewer messages: the answers are short and every replayed message is billed again.
    'max_conversation_messages' => 40,

    // Your own agent middleware, run on every model round-trip of a turn after the package's guard rails (the
    // budget check, the prompt guard): classes with handle(PendingStep $step, Closure $next) — an audit log, a
    // tenant check; $step->isFirstStep() tells the question from the tool steps that follow. Throw
    // Packstub\Agents\Exceptions\TurnRefused to stop a turn with a message the person reads under their
    // question. AgentsPlugin::make()->middleware([...]) appends to this list.
    'middleware' => [],

    // The prompt guard: before the assistant reads a question, a side agent on a small model classifies it as safe,
    // injection, jailbreak, data_exfiltration or off_topic. A category on the refuse list stops the turn with a
    // friendly message under the question; anything but safe is logged with its reason and fires
    // Packstub\Agents\Events\PromptFlagged. One extra call per question, so it is off until you switch it on.
    'prompt_guard' => [
        'enabled' => (bool) env('AGENT_PROMPT_GUARD', false),
        // Where the classifier runs: null = the provider the turn runs on, its cheapest model. A local model works
        // (AGENT_PROMPT_GUARD_PROVIDER=ollama, AGENT_PROMPT_GUARD_MODEL=llama-guard3).
        'provider' => env('AGENT_PROMPT_GUARD_PROVIDER'),
        'model' => env('AGENT_PROMPT_GUARD_MODEL'),
        // The categories that stop a turn. Switch 'off_topic' on to keep the assistant on the workspace.
        'refuse' => ['injection' => true, 'jailbreak' => true, 'data_exfiltration' => true, 'off_topic' => false],
        // When the classifier itself fails (the provider is down): true lets the turn run — the tools' ability
        // checks and approvals still apply — false refuses it.
        'fail_open' => true,
    ],

    // Typed decisions: a reply typed while proposals wait for a decision is read with your rule
    // (Agents::decideTypedUsing()), then the word lists (lang/vendor/packstub-agents/<locale>/decisions.php for your
    // own language), then — when switched on — this classifier, a small model that may decide each proposal on its
    // own ("Yes, but only Alpha."). It runs in the request that sends the reply, only when the lists cannot read it,
    // and never approves what they reject; when it fails or is unsure the reply is a question of its own.
    'decision_classifier' => [
        'enabled' => (bool) env('AGENT_DECISION_CLASSIFIER', false),
        // null = the provider of the model the reply was sent with, its cheapest model.
        'provider' => env('AGENT_DECISION_CLASSIFIER_PROVIDER'),
        'model' => env('AGENT_DECISION_CLASSIFIER_MODEL'),
    ],

    // Redaction: secrets and personal data are replaced in what the assistant writes — on the answer while it
    // streams, so a value is never shown and then taken back — and in the tool results stored with it, which the
    // model reads again as history. Each turn that had something replaced logs one `critical` line and fires
    // Packstub\Agents\Events\OutputRedacted (the kinds, never the values). Off until you switch it on.
    'redact' => [
        'enabled' => (bool) env('AGENT_REDACT', false),
        // The built-in detectors: payment card numbers (Luhn-checked), US social security numbers, API keys and
        // tokens (OpenAI, Anthropic, Stripe, AWS, GitHub, Slack, Google, JWTs, Sanctum, private key blocks).
        'detect' => ['card' => true, 'ssn' => true, 'api_key' => true],
        // Your own, label => regex: 'iban' => '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){3,7}\b/'. For anything a regex
        // cannot say, Agents::redactUsing(fn (string $text): string => …) runs after these.
        'patterns' => [],
        'replacement' => '[redacted]',
    ],

    // Classification: after an answer, a side agent on the provider's cheapest model says what the chat is about
    // (topic), how the person sounds (sentiment: positive, neutral, negative) and whether they got what they came
    // for (resolved), kept in agent_conversation_classifications for a list of chats to filter and sort by.
    // One extra cheap call per question, so it is off until you switch it on.
    'classify' => [
        'enabled' => (bool) env('AGENT_CLASSIFY', false),
        // A fixed list to pick from (['orders', 'billing', 'how-to']; 'other' is added); empty = the model names
        // the topic in a word or two.
        'topics' => [],
    ],

    // Web search in the chat, run by the provider (Anthropic, OpenAI, Gemini, xAI, OpenRouter; a provider without
    // it answers without). Give it an allow-list: the assistant then reads only those domains, which keeps answers
    // on sources you trust and web text — a prompt-injection vector — off pages you do not. An empty list means
    // the whole web. The prompt tells the assistant to keep workspace data and web information apart and to link
    // what it found; the chat lists the sources under the answer.
    'web_search' => [
        'enabled' => (bool) env('AGENT_WEB_SEARCH', false),
        'allow' => array_values(array_filter(array_map('trim', explode(',', (string) env('AGENT_WEB_SEARCH_ALLOW', ''))))),
        'max' => (int) env('AGENT_WEB_SEARCH_MAX', 3), // searches per turn
        'location' => ['city' => null, 'region' => null, 'country' => null], // refines results ('country' => 'RO')
    ],

    // The knowledge base: the app's own documents — guides, policies, how-to articles — for "how do I…" questions.
    // `model` is an Eloquent model with an embedding column (pgvector): the search-knowledge-base tool runs a
    // similarity search over it for the chat and for MCP clients, and `php artisan packstub-agents:embed` fills
    // the column. `stores` are provider-hosted vector store ids, searched by laravel/ai's FileSearch in the chat.
    // Agents::knowledgeBase(…) registers the same with closures (how a title, the content and the url are read,
    // which documents count, a search of your own).
    'knowledge_base' => [
        'model' => null, // App\Models\Article::class
        'column' => 'embedding',
        'title' => 'title',
        'content' => 'content',
        'url' => null, // an attribute holding the article's page, if it has one
        'min_similarity' => 0.5,
        'limit' => 5,
        'stores' => [],
        'ability' => null, // the ability required to search it; null = any member
    ],

    // What a long chat replays: the most recent messages that fit the token budget (estimated from what is stored),
    // cut on turn boundaries so a tool call keeps its result. Older tool results are replaced by a one-line placeholder,
    // and what falls out of the window is folded into a rolling summary the model reads first. The chat page shows a
    // context meter and, from notice_share of the budget, suggests continuing in a new chat.
    'history' => [
        'max_tokens' => (int) env('AGENT_HISTORY_MAX_TOKENS', 24000),
        'keep_tool_results_turns' => 3,
        'notice_share' => 0.7,
        'meter_share' => 0.25, // the context ring in the composer shows from this share of the window
        'compress_keep_turns' => 2, // exchanges "Compress now" keeps verbatim
    ],

    // How a chat turn runs. The answer is produced by the RunAgentTurn job, which writes what it has so far to the
    // agent_turns table; the page polls it, so an answer survives a reload, a closed tab and shows in every tab of the
    // chat, and Stop can cut it short.
    'chat' => [
        // 'queue' hands the job to a queue worker (the default; run one). 'sync' runs it inside the request that asked —
        // no worker needed, everything else the same, except that an answer ends with the tab that asked for it.
        // AgentsPlugin::make()->chat(driver: 'sync') sets it from the panel provider.
        'driver' => env('AGENT_TURN_DRIVER', 'queue'),
        // Where the job goes on the queue driver. A null connection or queue means the app's default.
        'queue_connection' => env('AGENT_QUEUE_CONNECTION'),
        'queue' => env('AGENT_QUEUE'),
        // How long one turn may run on the worker, in seconds (every tool round-trip included). A turn whose job
        // stopped writing for longer than this is shown as failed, with a Retry.
        'job_timeout' => (int) env('AGENT_JOB_TIMEOUT', 600),
        // How long a turn may wait for a worker to take it, in seconds, before the status line says none has (with the
        // command to run, or the sync driver). Only the queue driver waits.
        'worker_wait' => (int) env('AGENT_WORKER_WAIT', 10),
        // How often the page asks for the answer so far while a turn runs, in milliseconds — the fallback when the
        // browser cannot hold the event stream open. The stream endpoint (GET …/chat/{conversation}/stream) pushes
        // every change instead, checking the row every stream_interval milliseconds and closing after stream_seconds
        // (the browser reconnects on its own).
        'poll_interval' => (int) env('AGENT_POLL_INTERVAL', 600),
        'stream_interval' => (int) env('AGENT_STREAM_INTERVAL', 150),
        'stream_seconds' => (int) env('AGENT_STREAM_SECONDS', 55),
        // Files a person attaches to a question (a screenshot, an invoice, a CSV). Stored on this disk under this
        // directory and sent to the provider with the question; deleted with the conversation. Images go as images,
        // everything else as a document — check what your provider reads. temporary_urls asks the disk for signed
        // URLs (S3) when the chat shows a thumbnail.
        'attachments' => [
            'enabled' => (bool) env('AGENT_ATTACHMENTS', true),
            'disk' => env('AGENT_ATTACHMENTS_DISK'), // null = the default filesystem disk
            'directory' => 'agent-attachments',
            'max_kb' => (int) env('AGENT_ATTACHMENTS_MAX_KB', 10240),
            'max_files' => 5,
            'mimes' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv', 'text/markdown', 'application/json'],
            'temporary_urls' => (bool) env('AGENT_ATTACHMENTS_TEMPORARY_URLS', false),
        ],
        // Without a panel, the poll endpoint (GET {path}/chat/{conversation}/turn) is registered here, under this
        // middleware; the person must be the conversation's participant. A panel that shows the chat registers its own.
        'path' => 'agents',
        'middleware' => ['web', 'auth'],
        // Ended turns (the per-turn record: model, tokens, duration, how it ended) are kept this many days for the
        // operator's AI turns page and the daily and monthly budget counters (keep at least 31 days, or the monthly
        // counters shrink); null keeps them forever. Pruned by `model:prune --model=Packstub\\Agents\\Models\\AgentTurn`.
        'keep_turns_days' => env('AGENT_KEEP_TURNS_DAYS', 90),
    ],

    // What a turn cost in money, from its token usage: prices per million tokens by model name (a key is also a
    // prefix, so 'claude-opus-5' covers every dated variant). The package ships no prices — they change without
    // notice — so the cost stays null, and the tokens alone show, until you fill this in from your provider's
    // price list. `in` and `out` are the prompt and the answer (reasoning tokens count as out), `cache_read` and
    // `cache_write` the cached prefix where the provider reports it (else they cost `in`).
    'pricing' => [
        'currency' => env('AGENT_PRICING_CURRENCY', 'USD'),
        'models' => [
            // 'claude-opus-5' => ['in' => 15, 'out' => 75, 'cache_read' => 1.5, 'cache_write' => 18.75],
            // 'gpt-5' => ['in' => 1.25, 'out' => 10, 'cache_read' => 0.125],
        ],
    ],

    // The assistant by email: an inbound mail webhook (Postmark, Mailgun, SES, your own) posts the message to
    // POST {chat.path}/email with this secret in the X-Agent-Secret header; the sender must be a person of the app
    // (found by email on the guard's provider, or by Agents::participantByEmailUsing()), the answer goes back as a
    // reply, and a reply to that mail continues the same chat. Off until a secret is set.
    'email' => [
        'enabled' => (bool) env('AGENT_EMAIL', false),
        'secret' => env('AGENT_EMAIL_SECRET'),
        'from' => env('AGENT_EMAIL_FROM'), // null = the app's mail.from
        'middleware' => ['api'],
    ],

    // One log line per turn — who asked, the provider and model that answered, tokens in and out, the tools called,
    // the wall time and how it ended — on this channel (a name from config/logging.php). null logs nothing; the same
    // record is on the agent_turns row and on the operator's AI turns page either way.
    'log' => [
        'channel' => env('AGENT_LOG_CHANNEL'),
    ],

    // Spending guard rails, enforced before a turn calls the provider (this file is the platform's ceiling; the
    // operator's AI limits page overrides it per workspace and per user; the provider's own hard spend limit is
    // the real backstop). null disables a limit.
    'limits' => [
        'turns_per_minute' => (int) env('AGENT_TURNS_PER_MINUTE', 6),      // per user
        'turns_per_day' => (int) env('AGENT_TURNS_PER_DAY', 150),          // per workspace
        'tokens_per_day' => (int) env('AGENT_TOKENS_PER_DAY', 600000),      // per workspace, all token kinds
        'tokens_per_month' => (int) env('AGENT_TOKENS_PER_MONTH', 3000000), // per workspace, all token kinds
        'user_tokens_per_day' => (int) env('AGENT_USER_TOKENS_PER_DAY', 100000),      // per user, inside a workspace
        'user_tokens_per_month' => (int) env('AGENT_USER_TOKENS_PER_MONTH', 1500000), // per user, inside a workspace
        'prompt_max_chars' => (int) env('AGENT_PROMPT_MAX_CHARS', 2000),
    ],

    // The database connection of the agent_limits table. null = the default connection. Database-per-tenant apps
    // point it at the central connection, since limits are the operator's, not the workspace's.
    'limits_connection' => env('AGENT_LIMITS_CONNECTION'),

    // The MCP server for external agents (Claude Code, Claude Desktop, Cursor…). Bearer = a token from the
    // Agent access page. Put {tenant} in the path when the panel has tenancy: "mcp/{tenant}".
    'mcp' => [
        'enabled' => (bool) env('AGENT_MCP_ENABLED', true),
        'path' => 'mcp',
        // An AgentServer subclass with your name, instructions and tool list; null = the package's server with the
        // tools registered on the plugin.
        'server' => null,
        'middleware' => ['throttle:60,1', 'auth:sanctum', AuthenticateAgent::class],
    ],

    // Migrations auto-run from the package by default. Database-per-tenant apps set this to false, publish them
    // (vendor:publish --tag=packstub-agents-migrations) and move the chat tables into the tenant migrations.
    'run_migrations' => true,
];
