{{-- The bottom of the page: Jump to latest, the composer with the context ring, and what the host adds (the slots
     "composer.tools" in the composer's row and "composer.after" under it; Filament Agents fills them from its render
     hooks). One row: the question, then the tools, Stop while an answer runs, the model and a square Send. The field
     never locks: a question typed while an answer runs waits its turn on the server, and the placeholder says so
     meanwhile. Enter sends, Shift+Enter breaks the line, ↑ edits the last waiting question, Stop cuts the running
     answer short, @ offers records, / the starter questions, the paper clip attaches files. Reads $history, $model and
     $view. Part of chat.blade.php, included with its variables; publish this file alone to change only this part. --}}
@php
    use Packstub\Agents\Facades\Agents;
    use Packstub\Agents\Support\AgentChat;
    use Packstub\Agents\Support\AgentModels;
    use Packstub\Agents\Support\AgentPricing;

    $placeholder = Agents::name().'…';
    $hint = __('Keep typing — the next question is sent when this answer is done.');
    $attachments = $view->attachments();
    $menu = AgentChat::modelMenu();
    $pickedLabel = collect($menu)->collapse()->get($model)['label'] ?? \Illuminate\Support\Str::headline($model);
@endphp
<div data-agents-ignore data-key="jump">
    <button type="button" x-show="busy && ! atBottom" x-cloak x-transition.opacity x-on:click="scrollToBottom(true)" class="fi-chat-jump">
        <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-arrow-down" class="fi-chat-icon-sm" />
        {{ __('Jump to latest') }}
    </button>
</div>

{{-- The composer and what the host adds under it stick to the bottom together. --}}
<div class="fi-chat-bottom">
<form
    x-on:submit.prevent="submit()"
    class="fi-agent-composer fi-chat-composer"
    x-on:dragover.prevent="dragging = true"
    x-on:dragleave.prevent="dragging = false"
    x-on:drop.prevent="dragging = false; dropFiles($event)"
    x-bind:class="{ 'fi-agent-composer-dragging': dragging }"
>
    <div class="fi-chat-composer-editing" x-show="editing" x-cloak>
        <span>
            <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-pencil-square" class="fi-chat-icon-sm" />
            {{ __('Editing your last question — its answer will be replaced.') }}
        </span>
        <button type="button" x-on:click="cancelEdit()">{{ __('Cancel') }}</button>
    </div>

    @if ($attachments)
        {{-- The files picked for the next question, each removable until it is sent. --}}
        <div class="fi-agent-composer-files" data-agents-ignore x-show="files.length > 0" x-cloak>
            <template x-for="(file, i) in files" :key="file.id">
                <span class="fi-agent-composer-file">
                    <template x-if="file.preview"><img x-bind:src="file.preview" alt="" /></template>
                    <template x-if="! file.preview"><span class="fi-agent-composer-file-icon"><x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-document" class="fi-chat-icon" /></span></template>
                    <span class="fi-agent-composer-file-name" x-text="file.name"></span>
                    <button type="button" x-on:click="removeFile(file.id)" title="{{ __('Remove') }}" x-bind:aria-label="@js(__('Remove')) + ' ' + file.name"><x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-x-mark" class="fi-chat-icon-sm" /></button>
                </span>
            </template>
        </div>
    @endif

    {{-- The picker: "@" opens it with the records the host knows, "/" with the starter questions; ↑ ↓ Enter pick, Esc closes. --}}
    <div class="fi-agent-picker" data-agents-ignore x-show="picker.open" x-cloak role="listbox" aria-label="{{ __('Records') }}" x-ref="picker">
        <template x-if="picker.kind === 'mention' && picker.items.length === 0 && ! picker.loading">
            <p class="fi-agent-picker-empty" x-text="picker.query === '' ? @js(__('Type to search records.')) : @js(__('No record matches.'))"></p>
        </template>
        <template x-for="(item, i) in picker.items" :key="i">
            <button type="button" class="fi-agent-picker-item" role="option" x-bind:aria-selected="i === picker.index" x-bind:class="{ 'fi-agent-picker-item-active': i === picker.index }" x-on:mousedown.prevent="pick(i)" x-on:mousemove="picker.index = i">
                <span class="fi-agent-picker-label" x-text="item.label"></span>
                <span class="fi-agent-picker-detail" x-text="item.detail || ''"></span>
            </button>
        </template>
    </div>

    <div class="fi-agent-composer-row">
        @if ($attachments)
            <input type="file" x-ref="files" hidden multiple accept="{{ $attachments['accept'] }}" data-max-files="{{ $attachments['maxFiles'] }}" data-max-kb="{{ $attachments['maxKb'] }}" x-on:change="addFiles($event.target.files); $event.target.value = ''" tabindex="-1" />
            <x-dynamic-component :component="$ui.'::icon-button'" icon="heroicon-m-paper-clip" color="gray" size="sm" :label="__('Attach a file')" :tooltip="__('Attach a file')" class="fi-agent-composer-attach" x-on:click="$refs.files.click()" />
        @endif
        <textarea
            x-ref="input"
            rows="1"
            placeholder="{{ $placeholder }}"
            x-bind:placeholder="busy ? @js($hint) : @js($placeholder)"
            class="fi-agent-composer-input"
            x-on:input="autosize(); typed()"
            x-on:keydown.enter="if (picker.open && picker.items.length) { $event.preventDefault(); pick(picker.index) } else if (! $event.shiftKey) { $event.preventDefault(); submit() }"
            x-on:keydown.up="picker.open ? (picker.index = Math.max(0, picker.index - 1), $event.preventDefault()) : recall($event)"
            x-on:keydown.down="if (picker.open) { picker.index = Math.min(picker.items.length - 1, picker.index + 1); $event.preventDefault() }"
            x-on:keydown.tab="if (picker.open && picker.items.length) { $event.preventDefault(); pick(picker.index) }"
            x-on:keydown.escape="picker.open ? closePicker() : (editing ? cancelEdit() : ($refs.input.value = '', autosize()))"
            x-on:blur="setTimeout(() => closePicker(), 150)"
            x-on:paste="pasteFiles($event)"
            aria-label="{{ $placeholder }}"
            aria-autocomplete="list"
            x-bind:aria-expanded="picker.open"
        ></textarea>
        <div class="fi-agent-composer-actions">
            {!! $host->slot('composer.tools') !!}
            @agentsPart('ring')
            @if ($history && $history['meter'])
                {{-- The context ring: how full the history window is, from history.meter_share on. Click for what fills it, what the chat cost so far, Compress now and Continue in a new chat. --}}
                @php($percent = (int) round($history['share'] * 100))
                @php($ringLabel = __('Context :percent%', ['percent' => $percent]).' · '.__('~:used of :budget tokens', ['used' => number_format($history['tokens']), 'budget' => number_format($history['budget'])]))
                <x-dynamic-component :component="$ui.'::dropdown'" placement="top-end" width="sm" shift>
                    <x-slot name="trigger">
                        <button
                            type="button"
                            class="fi-chat-ring"
                            aria-label="{{ __('Context :percent%', ['percent' => $percent]) }}"
                            @if ($ui === 'filament')
                                x-tooltip="{ content: @js($ringLabel), theme: $store.theme, touch: false }"
                            @else
                                title="{{ $ringLabel }}"
                            @endif
                        >
                            <svg viewBox="0 0 20 20" aria-hidden="true">
                                <circle class="fi-chat-ring-track" cx="10" cy="10" r="8" pathLength="100" />
                                <circle class="fi-chat-ring-fill {{ $history['notice'] ? 'fi-chat-ring-fill-high' : '' }}" cx="10" cy="10" r="8" pathLength="100" stroke-dasharray="{{ $percent }} 100" />
                            </svg>
                        </button>
                    </x-slot>

                    <div class="fi-chat-ring-panel">
                        <div>
                            <p class="fi-chat-ring-title">{{ __('History window') }}</p>
                            <p>{{ __('~:used of :budget tokens', ['used' => number_format($history['tokens']), 'budget' => number_format($history['budget'])]) }} · {{ __('estimated') }}</p>
                            @if ($history['turns']['last_tokens_in'] !== null)
                                <p>{{ __('The last question read :tokens tokens.', ['tokens' => number_format($history['turns']['last_tokens_in'])]) }}</p>
                            @endif
                            <dl>
                                @foreach (AgentChat::breakdownLabels() as $key => $label)
                                    @if ($history['breakdown'][$key] > 0)
                                        <dt>{{ $label }}</dt>
                                        <dd>{{ number_format($history['breakdown'][$key]) }}</dd>
                                    @endif
                                @endforeach
                            </dl>
                        </div>

                        @if ($history['turns']['last_tokens_in'] !== null)
                            <div>
                                <p class="fi-chat-ring-title">{{ __('This chat so far') }}</p>
                                <dl>
                                    <dt>{{ __('Turns') }}</dt>
                                    <dd>{{ number_format($history['turns']['count']) }}</dd>
                                    <dt>{{ __('Tokens in') }}</dt>
                                    <dd>{{ number_format($history['turns']['tokens_in']) }}</dd>
                                    <dt>{{ __('Tokens out') }}</dt>
                                    <dd>{{ number_format($history['turns']['tokens_out']) }}</dd>
                                    <dt>{{ __('Tool calls') }}</dt>
                                    <dd>{{ number_format($history['turns']['tool_calls']) }}</dd>
                                    <dt>{{ __('Time') }}</dt>
                                    <dd>{{ AgentChat::duration($history['turns']['duration_ms']) }}</dd>
                                    @if ($history['turns']['cost'] !== null)
                                        <dt>{{ __('Cost') }}</dt>
                                        <dd>{{ AgentPricing::format($history['turns']['cost']) }}</dd>
                                    @endif
                                </dl>
                            </div>
                        @endif

                        @if ($history['source'])
                            <p class="fi-chat-origin">
                                {{ __('Continued from') }}
                                <a href="{{ $host->chatUrl($history['source']) }}">{{ $history['sourceTitle'] ?? __('an earlier chat') }}</a>
                                — {{ __('the assistant starts from a summary of it.') }}
                            </p>
                        @elseif ($history['summarized'])
                            <p>{{ __('Older messages are summarized for the assistant.') }}</p>
                        @endif

                        @if ($history['notice'])
                            <p class="fi-chat-ring-notice">{{ __('This chat is getting long — answers stay sharpest in a new one.') }}</p>
                        @endif

                        <div class="fi-chat-ring-actions">
                            <x-dynamic-component :component="$ui.'::button'" size="xs" color="gray" outlined icon="heroicon-m-arrows-pointing-in" x-on:click="act('compress')" x-bind:disabled="busy">{{ __('Compress now') }}</x-dynamic-component>
                            <x-dynamic-component :component="$ui.'::button'" size="xs" color="gray" outlined icon="heroicon-m-arrow-right-circle" x-on:click="act('continue-in-new')" x-bind:disabled="busy">{{ __('Continue in a new chat') }}</x-dynamic-component>
                        </div>
                    </div>
                </x-dynamic-component>
            @endif
            @endAgentsPart
            <x-dynamic-component :component="$ui.'::icon-button'" icon="heroicon-m-stop" color="gray" size="sm" :label="__('Stop')" :tooltip="__('Stop')" class="fi-agent-composer-stop" x-show="turn !== null" x-cloak x-on:click="stop()" x-bind:disabled="stopping" />
            {{-- The model: a small text button that opens the list, the picked one ticked; entries of more than one
                 provider (a Gemini entry on an Anthropic picker) sit under provider headings. --}}
            <x-dynamic-component :component="$ui.'::dropdown'" placement="top-end" width="xs">
                <x-slot name="trigger">
                    <button type="button" class="fi-agent-composer-model" aria-label="{{ __('Model: :model', ['model' => $pickedLabel]) }}">
                        <span class="fi-agent-composer-model-name">{{ $pickedLabel }}</span>
                        <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-chevron-up-down" />
                    </button>
                </x-slot>
                <x-dynamic-component :component="$ui.'::dropdown.list'" class="fi-agent-composer-models">
                    @foreach ($menu as $modelProvider => $entries)
                        @if (count($menu) > 1)
                            <x-dynamic-component :component="$ui.'::dropdown.header'">{{ AgentModels::providerLabel($modelProvider) }}</x-dynamic-component>
                        @endif
                        @foreach ($entries as $key => $entry)
                            <x-dynamic-component :component="$ui.'::dropdown.list.item'" :class="$key === $model ? 'fi-agent-composer-model-picked' : ''" :x-on:click="'close(); setModel('.\Illuminate\Support\Js::from($key).')'">
                                <span class="fi-agent-composer-model-option">
                                    <span class="fi-agent-composer-model-label">{{ $entry['label'] }}</span>
                                    @if ($entry['detail'])
                                        <span class="fi-agent-composer-model-detail">{{ $entry['detail'] }}</span>
                                    @endif
                                </span>
                                @if ($key === $model)
                                    <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-check" class="fi-agent-composer-model-check" />
                                @endif
                            </x-dynamic-component>
                        @endforeach
                    @endforeach
                </x-dynamic-component>
            </x-dynamic-component>
            <x-dynamic-component :component="$ui.'::button'" type="submit" icon="heroicon-m-arrow-up" size="sm" label-sr-only class="fi-agent-composer-send">{{ __('Send') }}</x-dynamic-component>
        </div>
    </div>
</form>

{!! $host->slot('composer.after') !!}
</div>
