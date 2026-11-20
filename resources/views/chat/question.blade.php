{{-- A question in the transcript, with its files and mentions, Edit on the last one, and how it ended when it got no
     answer. Reads $message and $live. Part of chat.blade.php, included with its variables; publish this file alone to
     change only this part. --}}
@unless ($message['continuation'])
    @agentsPart('message-'.$message['id'], \Packstub\Agents\Chat\ChatParts::rev($message, $ui, app()->getLocale()))
    <div class="fi-chat-exchange fi-chat-ask">
        @if ($message['editable'])
            {{-- The last question: edit it and send it again (the answer is kept as a version). --}}
            <button type="button" class="fi-chat-exchange-tools fi-chat-tool" title="{{ __('Edit and send again') }}" aria-label="{{ __('Edit and send again') }}" x-show="! busy" x-on:click="editLast(@js($message['text']))">
                <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-pencil-square" class="fi-chat-icon" />
            </button>
        @endif
        <div class="fi-chat-question">
            @if ($message['attachments'])
                <div class="fi-chat-attachments">
                    @foreach ($message['attachments'] as $attachment)
                        @if ($attachment['image'] && $attachment['url'])
                            <a href="{{ $attachment['url'] }}" target="_blank" rel="noopener" class="fi-chat-attachment-image" title="{{ $attachment['name'] }}"><img src="{{ $attachment['url'] }}" alt="{{ $attachment['name'] }}" /></a>
                        @else
                            <a @if ($attachment['url']) href="{{ $attachment['url'] }}" target="_blank" rel="noopener" @endif class="fi-chat-attachment-file">
                                <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-paper-clip" class="fi-chat-icon-sm" />
                                <span>{{ $attachment['name'] }}</span>
                            </a>
                        @endif
                    @endforeach
                </div>
            @endif
            <div class="fi-chat-bubble">{!! $message['html'] !!}</div>
            @if ($message['mentions'])
                <div class="fi-chat-mentions">
                    @foreach ($message['mentions'] as $mention)
                        <span class="fi-chat-mention" title="{{ $mention['ref'] }}">@ {{ $mention['label'] }}</span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    @endAgentsPart
@endunless
@if ($message['unanswered'])
    {{-- Recorded but never answered (refused by a middleware, the provider failed, or the person stopped it): say how
         its turn ended and offer to send it again, under the last question and under any earlier one the chat moved
         on from. Retry on an earlier one moves it to the end of the chat, where its answer lands. --}}
    @php($ended = $message['ended'] ?? ($loop->last ? $live['ended'] : null))
    @agentsPart('unanswered-'.$message['id'], \Packstub\Agents\Chat\ChatParts::rev($ended, $ui, app()->getLocale()))
    <div class="fi-chat-unanswered" x-show="! busy" role="status">
        <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-exclamation-circle" class="fi-chat-icon {{ ($ended['reason'] ?? null) === 'refused' ? 'fi-chat-warn' : 'fi-chat-bad' }}" />
        <span>
            @if (($ended['status'] ?? null) === \Packstub\Agents\Models\AgentTurn::STOPPED)
                {{ __('Stopped before an answer.') }}
            @elseif (($ended['reason'] ?? null) === 'refused')
                {{ $ended['error'] }}
            @elseif (($ended['status'] ?? null) === \Packstub\Agents\Models\AgentTurn::FAILED)
                {{-- A provider or app exception (`failed`) is the operator's to read in the turn log: its text can name
                     config keys and is not translated. The engine's own reasons (an interrupted worker) are shown. --}}
                {{ __('The assistant could not answer.') }} @if (($ended['reason'] ?? null) !== 'failed') {{ $ended['error'] }} @endif
            @else
                {{ __('The assistant did not answer.') }}
            @endif
        </span>
        {{-- A bound attribute: a Blade expression inside a component tag's plain attribute is not compiled. --}}
        <x-dynamic-component :component="$ui.'::link'" tag="button" size="sm" icon="heroicon-m-arrow-path" :x-on:click="'retry('.\Illuminate\Support\Js::from($message['id']).')'">{{ __('Retry') }}</x-dynamic-component>
    </div>
    @endAgentsPart
@endif
