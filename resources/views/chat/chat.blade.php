{{-- The chat: the header, the starter questions, the transcript and the composer. Rendered by ChatView for a page
     and again for every refresh (an action's answer, a turn that ended), which the page morphs in: an element with
     data-agents-ignore is the page's own and is left alone. Buttons, icons and dropdowns come from the host's kit
     ($ui): this package's components in a plain app, Filament's in a panel. --}}
<div
    @class(['fi-chat', 'fi-chat-plain' => $ui !== 'filament', 'fi-chat-embedded' => $embedded, 'fi-chat-started' => $started, 'fi-chat-off' => $unavailable])
    {{-- Only ever adds the class: an x-bind:class object would strip the server's fi-chat-started whenever busy is false. --}}
    x-effect="if (busy) $el.classList.add('fi-chat-started')"
    @if ($load = $host->slot('script'))
        x-load
        x-load-src="{{ $load }}"
    @endif
    x-data="agentChat()"
    data-agents-chat
>
    {{-- What the component reads per render. Kept out of x-data on purpose: a changed x-data expression would make the
         morph re-initialise the component, which would drop the outbox and the polling state. --}}
    <div x-ref="state" hidden @foreach ($state as $name => $value) data-{{ $name }}="{{ $value }}" @endforeach></div>

    @include('packstub-agents-chat::header')

    @unless ($unavailable)
        @include('packstub-agents-chat::suggestions')
    @endunless

    <div class="fi-chat-transcript" x-ref="transcript" role="log" aria-live="polite" aria-relevant="additions text" aria-label="{{ __('Conversation') }}">
        @if ($history && $history['source'])
            <p class="fi-chat-origin" data-key="origin">
                {{ __('Continued from') }}
                <a href="{{ $host->chatUrl($history['source']) }}">{{ $history['sourceTitle'] ?? __('an earlier chat') }}</a>
                — {{ __('the assistant starts from a summary of it.') }}
            </p>
        @endif

        @foreach ($messages as $message)
            @if ($message['role'] === 'user')
                @include('packstub-agents-chat::question')
            @else
                @include('packstub-agents-chat::answer')
            @endif
        @endforeach

        @include('packstub-agents-chat::live')
    </div>

    @if ($unavailable)
        {{-- The assistant cannot be used here: said once, in place of the starters and the composer (kept in the page,
             hidden, for the script). An earlier chat stays readable above. --}}
        <div class="fi-chat-empty fi-chat-unavailable" role="status">
            <span class="fi-chat-empty-icon"><x-dynamic-component :component="$ui.'::icon'" icon="heroicon-o-sparkles" /></span>
            <p class="fi-chat-empty-lead">{{ $unavailable }}</p>
        </div>
    @endif

    @include('packstub-agents-chat::composer')

    {{-- Notices (a refused question, a failed turn, "compressed"): shown here unless the host shows them itself. --}}
    <div class="fi-chat-toasts" data-agents-ignore role="status" aria-live="polite">
        <template x-for="toast in toasts" :key="toast.id">
            <div class="fi-chat-toast" x-bind:class="'fi-chat-toast-' + toast.level">
                <div class="fi-chat-toast-text">
                    <p class="fi-chat-toast-title" x-text="toast.title"></p>
                    <p class="fi-chat-toast-body" x-show="toast.body" x-text="toast.body"></p>
                </div>
                <button type="button" class="fi-chat-toast-close" aria-label="{{ __('Close') }}" x-on:click="dismiss(toast.id)">×</button>
            </div>
        </template>
    </div>
</div>
