{{-- An answer in the transcript: the reasoning, then the parts below (lookups, proposals, the text, embeds, sources,
     the footer). Reads $message. Part of chat.blade.php, included with its variables; publish this file alone to
     change only this part. --}}
@php
    // What the answer is drawn from: the message, and for the last one how its turn ended and the versions of its question.
    $pendingTool = collect($message['tools'])->contains(fn ($tool) => ! $tool['readOnly'] && $tool['pending']);
    $rev = \Packstub\Agents\Chat\ChatParts::rev($message, $message['regenerable'] ? [$lastQuestionId, $lastQuestionVersions] : null, $pendingTool ? [$live['ended'], $live['held']] : null, $ui, app()->getLocale());
@endphp
@agentsPart('message-'.$message['id'], $rev)
<div class="fi-chat-exchange fi-chat-answer {{ $message['continued'] ? 'fi-chat-continued' : '' }}">
    @if ($message['reasoning'] !== '')
        {{-- What the model thought before it answered, when the provider reports it: folded, one line to open it. --}}
        <div class="fi-chat-reasoning" x-data="{ open: false }">
            <button type="button" class="fi-chat-timeline-call" x-on:click="open = ! open" x-bind:aria-expanded="open">
                <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-light-bulb" class="fi-chat-timeline-icon" />
                <span class="fi-chat-timeline-name">{{ __('Reasoning') }}</span>
                <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-chevron-right" class="fi-chat-proposal-chevron" x-bind:class="{ 'fi-chat-proposal-chevron-open': open }" />
            </button>
            <div class="fi-chat-reasoning-text" x-show="open" x-collapse x-cloak>{{ $message['reasoning'] }}</div>
        </div>
    @endif

    @include('packstub-agents-chat::answer.lookups')

    @include('packstub-agents-chat::answer.proposals')

    @if (trim($message['html']) !== '')
        <div class="fi-chat-md" data-text="{{ $message['text'] }}">{!! $message['html'] !!}</div>
    @endif

    @include('packstub-agents-chat::answer.embeds')

    @if ($message['sources'])
        {{-- The web pages the answer cites (the provider's web search): what came from outside the workspace, and where from. --}}
        <div class="fi-chat-sources">
            <span class="fi-chat-sources-label">{{ __('Sources') }}</span>
            @foreach ($message['sources'] as $source)
                <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer nofollow" class="fi-chat-source" title="{{ $source['url'] }}">
                    <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-globe-alt" class="fi-chat-icon-sm" />
                    <span>{{ \Illuminate\Support\Str::limit($source['title'], 60) }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @include('packstub-agents-chat::answer.footer')
</div>
@endAgentsPart
