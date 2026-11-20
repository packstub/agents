# The chat

The package ships the chat itself: the transcript, the composer, approvals, queued questions and the streamed answer. It is Blade, plain HTTP and a small Alpine component, so it needs no Filament, no Livewire and no Tailwind in your app. Filament Agents renders the same views inside a panel.

## On a page of your own

Put the component in any Blade view behind your `auth` middleware:

```blade
<x-agents-chat :conversation="$conversation" />
```

Give the page a route with an optional `{conversation}` parameter and name it in the config, so the chat knows where a chat lives (New chat, the address bar after the first question, "Continued from"):

```php
Route::get('/assistant/{conversation?}', fn (?string $conversation = null) => view('assistant', ['conversation' => $conversation]))
    ->middleware(['web', 'auth'])
    ->name('assistant');
```

```php
// config/packstub-agents.php
'chat' => [
    'ui' => ['route' => 'assistant'],
],
```

Without a `conversation` the component reads the route's `{conversation}` parameter, and starts a new chat when there is none. Another person's conversation is a 404.

| Attribute | What it does |
| --- | --- |
| `conversation` | the chat to open; none for a new one |
| `context` | the record the chat is about (`orders/12`), as [page context](assistant.md) |
| `prompt` | a question waiting in the composer for the person to send |
| `embedded` | the narrow layout, for a side panel or a frame |
| `:assets="false"` | leave the stylesheet and the script out (you load them with `@agentsChatAssets`) |

`context`, `prompt` and `embedded` fall back to the query string (`?context=orders/12`). A question your app wants sent on load goes through the session: `session(['packstub-agents.prompt' => $text])`, then redirect to the page.

## Ready-made pages

To start without writing a view, turn the package's own pages on:

```dotenv
AGENT_CHAT_PAGE=true
```

That registers `GET {chat.path}/chat/{conversation?}` and `GET {chat.path}/chats` (`/agents/chat` and `/agents/chats` by default) under `chat.middleware`. They render in a bare page; name a layout of yours that yields `content` to put them inside your app:

```php
'ui' => ['page' => true, 'layout' => 'layouts.app'],
```

## The list of chats

```blade
<x-agents-chats />
```

The signed-in person's chats, pinned first, the latest on top, with a search over titles and messages. With a page of your own for it, name its route in `chat.ui.chats_route` and the chat links to it as "All chats".

## Styles and scripts

The component brings both, once per page. The stylesheet is plain CSS under `fi-chat-*` and `fi-agent-*` class names. The script registers the chat on the Alpine your page starts (Livewire's, or your own on `window.Alpine`) and starts a bundled copy when the page has none. `chat.ui.alpine` overrides the guess: `bundled` always starts the bundled copy, `external` never does.

Loaded from your layout instead (an app that swaps pages without a full load should do this):

```blade
<head>
    @agentsChatAssets
</head>
...
<x-agents-chat :assets="false" />
```

### Colours and dark mode

The chat reads its colours from CSS variables, so one rule gives it your accent:

```css
:root {
    --agents-primary-500: #14b8a6;
    --agents-primary-600: #0d9488;
}
```

Every shade it uses can be set (`--agents-primary-*`, `--agents-gray-*`, `--agents-success-*`, `--agents-warning-*`, `--agents-danger-*`). Dark mode follows a `dark` class on an ancestor, as Tailwind's class strategy does.

The composer sticks to the bottom of the window once a chat has started. `--fi-chat-offset` is the height above the chat that the page takes (a navbar plus padding); set it on `.fi-chat` when the composer sits too low or leaves a gap.

### Changing a part

The chat is assembled from parts, so you can publish and change one and keep the package's version of the rest:

```bash
php artisan vendor:publish --tag=packstub-agents-chat-views
```

Keep only the file you change under `resources/views/vendor/packstub-agents-chat/`: `header`, `suggestions`, `question`, `answer` (with `answer/lookups`, `answer/proposals`, `answer/embeds`, `answer/footer`), `live`, `composer`. Each file names the variables it reads. A part wrapped in `@agentsPart … @endAgentsPart` has one root element.

## What the page calls

The chat talks to routes the package registers under `chat.path` and `chat.middleware` (`web` and `auth` by default). Each one checks that the conversation is the signed-in person's.

| Route | What it does |
| --- | --- |
| `POST {path}/chat/act/{action}` | one action: `send`, `resend`, `regenerate`, `continue`, `retry`, `decide`, `stop`, `queued-edit`, `queued-remove`, `feedback`, `version`, `rename`, `pin`, `compress`, `continue-in-new` |
| `POST {path}/chat/view` | the chat rendered again, when a turn ends |
| `GET {path}/chat/{conversation}/turn`, `…/stream` | the running turn: polled, or pushed as an event stream |
| `GET {path}/chat/{conversation}/export` | the chat as a Markdown file |
| `GET {path}/chat/mentions` | the records "@" offers |

An action's body names the `conversation` (none for a new chat), the `model` and what the action needs (`prompt`, `call` and `approve`, `message` and `rating`). It answers with the turn to listen to, any notices, and the chat's HTML. A question with files is a multipart request with `files[]`.

For a front end of your own (Inertia, React, a mobile app), [`AgentChat`](assistant.md#a-chat-surface-of-your-own) is the same logic without the markup.

## In a Filament panel

[Filament Agents](https://packstub.dev/docs/filament-agents/assistant) shows this chat as a panel page with Filament's buttons and menus, the slide-over, the "@" record search over your resources and live resource tables in answers. Tables in answers and the record search need a panel.

## Testing

The chat is ordinary routes, so a feature test drives it over HTTP:

```php
Assistant::fake(['Two orders are waiting for a call.']);

$response = $this->actingAs($user)
    ->postJson(route('packstub-agents.act', ['action' => 'send']), ['prompt' => 'What needs attention?'])
    ->assertOk();

$this->get(route('assistant', ['conversation' => $response->json('turn.conversation')]))
    ->assertSee('Two orders are waiting for a call');
```
