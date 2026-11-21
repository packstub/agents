{{-- The person's chats: pinned first, the latest on top, a search over titles and messages. Reads $chats (a paginator
     of conversations), $pinned (their ids), $query and $host. --}}
@if ($assets)
    @include('packstub-agents-chat::assets')
@endif
<div @class(['fi-chats', 'fi-chat-plain' => $ui !== 'filament'])>
    <div class="fi-chat-header">
        <div class="fi-chat-header-main">
            <div class="fi-chat-header-title"><h1>{{ __('Chats') }}</h1></div>
        </div>
        <div class="fi-chat-header-tools">
            <x-dynamic-component :component="$ui.'::button'" tag="a" :href="$host->chatUrl()" size="sm" icon="heroicon-m-plus">{{ __('New chat') }}</x-dynamic-component>
        </div>
    </div>

    <form method="GET" class="fi-chats-search" role="search">
        <input type="search" name="q" value="{{ $query }}" class="fi-chat-note-input" placeholder="{{ __('Search chats') }}" aria-label="{{ __('Search chats') }}" />
    </form>

    @if ($chats->isEmpty())
        <p class="fi-chats-empty">{{ $query === '' ? __('No chats yet.') : __('No chat matches.') }}</p>
    @else
        <ul class="fi-chats-list">
            @foreach ($chats as $conversation)
                <li>
                    <a href="{{ $host->chatUrl($conversation->id) }}" class="fi-chats-item">
                        @if (in_array($conversation->id, $pinned, true))
                            <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-s-bookmark" class="fi-chat-icon fi-chats-pin" />
                        @endif
                        <span class="fi-chats-title">{{ $conversation->title ?: __('Chat') }}</span>
                        <time class="fi-chats-time" datetime="{{ $conversation->updated_at?->toIso8601String() }}">{{ $conversation->updated_at?->diffForHumans() }}</time>
                    </a>
                </li>
            @endforeach
        </ul>

        @if ($chats->hasPages())
            <nav class="fi-chats-pages">
                @if ($chats->previousPageUrl())
                    <x-dynamic-component :component="$ui.'::link'" :href="$chats->previousPageUrl()" size="sm" color="gray">{{ __('Newer') }}</x-dynamic-component>
                @endif
                @if ($chats->nextPageUrl())
                    <x-dynamic-component :component="$ui.'::link'" :href="$chats->nextPageUrl()" size="sm" color="gray">{{ __('Older') }}</x-dynamic-component>
                @endif
            </nav>
        @endif
    @endif
</div>
