{{-- The chat's header: the title (rename), what the chat is about, pin, export and New chat. Part of chat.blade.php,
     included with its variables; publish this file alone to change only this part. --}}
@agentsPart('header')
<div class="fi-chat-header">
    <div class="fi-chat-header-main" x-data="{ renaming: false, title: @js($title) }">
        <div class="fi-chat-header-title" x-show="! renaming">
            <h1>{{ $title }}</h1>
            @if ($conversation)
                <button type="button" class="fi-chat-header-tool" title="{{ __('Rename') }}" aria-label="{{ __('Rename') }}" x-on:click="renaming = true; $nextTick(() => $refs.title.select())">
                    <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-pencil" class="fi-chat-icon" />
                </button>
            @endif
        </div>
        @if ($conversation)
            <form class="fi-chat-rename" x-show="renaming" x-cloak x-on:submit.prevent="renaming = false; act('rename', { title })">
                <input x-ref="title" x-model="title" type="text" maxlength="100" class="fi-chat-rename-input" aria-label="{{ __('Chat title') }}" x-on:keydown.escape.prevent="renaming = false; title = @js($title)" />
                <x-dynamic-component :component="$ui.'::button'" type="submit" size="xs">{{ __('Save') }}</x-dynamic-component>
                <x-dynamic-component :component="$ui.'::link'" tag="button" type="button" size="sm" color="gray" :x-on:click="'renaming = false; title = '.\Illuminate\Support\Js::from($title)">{{ __('Cancel') }}</x-dynamic-component>
            </form>
        @endif
        @if ($label = $chat->contextLabel())
            {{-- What the chat is about, linked to the record's own page (opened over the slide-over's frame, not inside it). --}}
            <p class="fi-chat-about">
                @if ($recordUrl = $chat->contextUrl())
                    {!! __('About :record', ['record' => '<a href="'.e($recordUrl).'" target="_top">'.e($label).'</a>']) !!}
                @else
                    {{ __('About :record', ['record' => $label]) }}
                @endif
            </p>
        @endif
    </div>
    <div class="fi-chat-header-tools">
        @if ($conversation)
            @php($pinned = $chat->pinned())
            <button type="button" x-on:click="act('pin')" class="fi-chat-header-tool {{ $pinned ? 'fi-chat-header-tool-on' : '' }}" title="{{ $pinned ? __('Unpin') : __('Pin') }}" aria-label="{{ $pinned ? __('Unpin') : __('Pin') }}" aria-pressed="{{ $pinned ? 'true' : 'false' }}">
                <x-dynamic-component :component="$ui.'::icon'" :icon="$pinned ? 'heroicon-s-bookmark' : 'heroicon-o-bookmark'" class="fi-chat-icon" />
            </button>
            <a href="{{ $view->conversationUrl('export') }}" download class="fi-chat-header-tool" title="{{ __('Export as Markdown') }}" aria-label="{{ __('Export as Markdown') }}">
                <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-o-arrow-down-tray" class="fi-chat-icon" />
            </a>
        @endif
        @if ($embedded)
            <x-dynamic-component :component="$ui.'::link'" :href="$view->pageUrl()" target="_top" size="sm" color="gray" icon="heroicon-m-arrow-top-right-on-square">{{ __('Open full page') }}</x-dynamic-component>
            <x-dynamic-component :component="$ui.'::button'" tag="a" :href="$view->newChatUrl()" size="sm" color="gray" outlined icon="heroicon-m-plus">{{ __('New chat') }}</x-dynamic-component>
        @else
            @if ($chatsUrl = $host->chatsUrl())
                <x-dynamic-component :component="$ui.'::link'" :href="$chatsUrl" size="sm" color="gray" class="fi-chat-gap-start">{{ __('All chats') }}</x-dynamic-component>
            @endif
            <x-dynamic-component :component="$ui.'::button'" tag="a" :href="$view->newChatUrl()" size="sm" color="gray" outlined icon="heroicon-m-plus" class="fi-chat-gap-start">{{ __('New chat') }}</x-dynamic-component>
        @endif
    </div>
</div>
@endAgentsPart
