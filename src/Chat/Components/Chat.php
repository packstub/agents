<?php

namespace Packstub\Agents\Chat\Components;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Packstub\Agents\Chat\ChatView;

/**
 * <x-agents-chat /> — the chat on a page of your own:
 *
 *     <x-agents-chat :conversation="$conversation" />
 *
 * Without a conversation it reads the route's {conversation} parameter, and a
 * new chat starts when there is none. `context` is the record the chat is
 * about (PageContext), `prompt` a question waiting in the composer, `embedded`
 * the narrow layout of a slide-over; each falls back to the query string.
 * The stylesheet and the script come with it once per page (`:assets="false"`
 * when you load them yourself, with @agentsChatAssets).
 */
class Chat extends Component
{
    public function __construct(
        public ?string $conversation = null,
        public ?string $context = null,
        public ?string $prompt = null,
        public ?bool $embedded = null,
        public bool $assets = true,
        public ?ChatView $view = null,
    ) {}

    public function render(): Htmlable
    {
        $request = request();
        $routed = $request->route('conversation');

        $view = $this->view ?? ChatView::open(
            $this->conversation ?? (is_string($routed) && $routed !== '' ? $routed : null),
            $this->context ?? (is_string($request->query('context')) ? $request->query('context') : null),
            $this->embedded ?? (bool) $request->query('embedded'),
            $this->prompt ?? (is_string($request->query('prompt')) ? $request->query('prompt') : null),
        );

        return new HtmlString(($this->assets ? view('packstub-agents-chat::assets')->render() : '').$view->render());
    }
}
