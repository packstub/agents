{{-- Under an answer: what the assistant looked up (its read-only tool calls), one line per call with the arguments and
     result folded. Reads $message. Part of chat.blade.php, included with its variables; publish this file alone to
     change only this part. --}}
@php($reads = collect($message['tools'])->where('readOnly', true)->values())
@if ($reads->isNotEmpty())
    <div class="fi-chat-timeline" x-data="{ open: null }">
        @foreach ($reads as $i => $tool)
            <div class="fi-chat-timeline-row" data-key="tool-{{ $message['id'] }}-{{ $tool['id'] ?? $i }}">
                <button type="button" class="fi-chat-timeline-call" x-on:click="open = open === {{ $i }} ? null : {{ $i }}" x-bind:aria-expanded="open === {{ $i }}">
                    <x-dynamic-component :component="$ui.'::icon'" :icon="$tool['tool'] === 'web_search' ? 'heroicon-m-globe-alt' : 'heroicon-m-magnifying-glass'" class="fi-chat-timeline-icon" />
                    <span class="fi-chat-timeline-name">{{ $tool['name'] }}</span>
                    @if ($summary = \Packstub\Agents\Chat\ChatPresenter::callSummary($tool['arguments']))
                        <span class="fi-chat-timeline-args">{{ $summary }}</span>
                    @endif
                    @if ($outcome = \Packstub\Agents\Chat\ChatPresenter::resultSummary($tool['result']))
                        <span class="fi-chat-timeline-result">{{ $outcome }}</span>
                    @endif
                    <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-chevron-right" class="fi-chat-proposal-chevron" x-bind:class="{ 'fi-chat-proposal-chevron-open': open === {{ $i }} }" />
                </button>
                <div class="fi-chat-proposal-details" x-show="open === {{ $i }}" x-collapse x-cloak>
                    @if ($tool['arguments'])
                        <dl>
                            @foreach ($tool['arguments'] as $key => $value)
                                <dt>{{ \Illuminate\Support\Str::headline($key) }}</dt>
                                <dd>{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</dd>
                            @endforeach
                        </dl>
                    @endif
                    @if (($result = \Packstub\Agents\Support\AgentChat::resultText($tool['result'])) !== null)
                        <p class="fi-chat-proposal-label">{{ __('Result') }}</p>
                        <pre>{{ \Illuminate\Support\Str::limit($result, 4000) }}</pre>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
