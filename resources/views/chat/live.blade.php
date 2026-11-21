{{-- The live part of the transcript: the question being sent, the running turn's tools and streamed answer, the
     questions waiting their turn. Reads $live. Part of chat.blade.php, included with its variables; publish this file
     alone to change only this part. --}}
{{-- Live areas (data-agents-ignore: the page's own, a refresh leaves them alone). The question being sent is drawn
     client-side the moment it is sent (agent-chat.js) and handed over to the stored transcript on the refresh; the
     answer, the tools and the status come from the turn's event stream (or the poll endpoint) while the job produces
     them. --}}
<div data-agents-ignore data-key="live-sending" class="fi-chat-sending-row">
    <template x-if="sending">
        <div class="fi-chat-sending fi-chat-bubble fi-chat-narrow" x-text="sending.text"></div>
    </template>
</div>
<div data-agents-ignore data-key="live-tools" class="fi-chat-timeline fi-chat-timeline-live" x-show="(turn !== null || handing) && live.tools.length > 0" x-cloak>
    <template x-for="(tool, i) in live.tools" :key="i">
        <div class="fi-chat-timeline-row">
            <span class="fi-chat-timeline-call">
                <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-magnifying-glass" class="fi-chat-timeline-icon" />
                <span class="fi-chat-timeline-name" x-text="tool"></span>
                <span class="fi-chat-timeline-result" x-show="i === live.tools.length - 1 && live.status.endsWith('…') && ! live.status.startsWith(@js(__('Writing'))) && ! live.status.startsWith(@js(__('Reasoning')))" x-text="@js(__('running…'))"></span>
            </span>
        </div>
    </template>
</div>
{{-- The streamed answer is written by agent-chat.js (stream()), revealed at a steady pace rather than chunk by chunk. --}}
<div data-agents-ignore data-key="live-answer" class="fi-chat-md fi-chat-live-answer" :class="{ 'fi-chat-streaming': turn !== null || handing }" x-ref="answer" x-show="live.html !== ''"></div>
<div data-agents-ignore data-key="live-status" class="fi-chat-status" x-show="turn !== null || handing" x-cloak role="status" aria-live="polite">
    <x-dynamic-component :component="$ui.'::loading-indicator'" class="fi-chat-icon" />
    <span x-text="live.status || @js(__('Thinking…'))"></span>
</div>

{{-- Questions waiting their turn on the server (kept per conversation): sent next, one at a time; editable until then. --}}
<div class="fi-chat-line" data-key="queued" data-agents-queued data-turns="{{ json_encode(array_column($live['queued'], 'id')) }}">
    @foreach ($live['queued'] as $queued)
        <div class="fi-chat-line-item" data-key="queued-{{ $queued['id'] }}">
            <div class="fi-chat-queued fi-chat-narrow">{{ $queued['text'] }}</div>
            <div class="fi-chat-line-meta">
                <span>{{ __('Queued') }}</span>
                <button type="button" x-on:click="editQueued(@js($queued['id']))">{{ __('Edit') }}</button>
                <button type="button" x-on:click="removeQueued(@js($queued['id']))">{{ __('Remove') }}</button>
            </div>
        </div>
    @endforeach
</div>

{{-- The first question waits for the budget: opening the chat tried to start it and the budget said no. It is
     kept as it is and tried again on the next open, so nothing is offered under it but the reason. --}}
@if ($live['deferred']['error'] ?? null)
    <div class="fi-chat-deferred" role="status" data-key="deferred-{{ $live['deferred']['id'] }}">
        <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-exclamation-circle" class="fi-chat-icon fi-chat-warn" />
        <span>{{ $live['deferred']['error'] }} {{ __('It will be answered the next time you open this chat.') }}</span>
    </div>
@endif

{{-- Questions on their way to the server (a moment, while an earlier send is in flight). --}}
<div data-agents-ignore data-key="live-outbox" class="fi-chat-line">
    <template x-for="queued in outbox" :key="queued.id">
        <div class="fi-chat-line-item">
            <div class="fi-chat-queued fi-chat-narrow" x-text="queued.text"></div>
            <div class="fi-chat-line-meta">
                <span>{{ __('Queued') }}</span>
                <button type="button" x-on:click="edit(queued.id)">{{ __('Edit') }}</button>
                <button type="button" x-on:click="remove(queued.id)">{{ __('Remove') }}</button>
            </div>
        </div>
    </template>
</div>
