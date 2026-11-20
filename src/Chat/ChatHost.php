<?php

namespace Packstub\Agents\Chat;

/**
 * What the chat asks of the place it is rendered in: where its pages and
 * endpoints live, which records "@" offers, and what draws its buttons,
 * icons and embeds. A plain Laravel app gets RouteHost (named routes from
 * config, the package's own Blade components); Filament Agents binds a host
 * that answers with the panel's URLs and Filament's components.
 */
interface ChatHost
{
    /**
     * The page of a chat, or of a new one.
     *
     * @param  array<string, mixed>  $query
     */
    public function chatUrl(?string $conversation = null, array $query = []): string;

    /** The list of the person's chats, when the host has one. */
    public function chatsUrl(): ?string;

    /**
     * One of the chat's endpoints: act, view, mentions, export, turn or stream.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function endpoint(string $name, array $parameters = []): string;

    /** Whether "@" in the composer can offer records. */
    public function canMention(): bool;

    /**
     * The records "@…" offers, each one the person may see.
     *
     * @return list<array{ref: string, label: string, resource: string}>
     */
    public function mentions(string $query): array;

    /** The Blade component namespace that draws buttons, links, icons, dropdowns and the spinner. */
    public function kit(): string;

    /** The view of an embed under an answer ("chart", "table"), or null when the host cannot show it. */
    public function embedView(string $kind): ?string;

    /** What the host adds to the chat at a named place ("composer.tools", "composer.after"), as HTML. */
    public function slot(string $name): string;

    /** Whether the host shows the chat's notices itself (it listens for `agents-notify`); otherwise the chat shows a toast. */
    public function notifies(): bool;
}
