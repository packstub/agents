{{-- The row under an answer: copy, rating, regenerate, the version switcher, the time, the notes on how it ended, the
     rating note. Reads $message, $lastQuestionId and $lastQuestionVersions. Part of chat.blade.php, included with its
     variables; publish this file alone to change only this part. --}}
@if (trim($message['html']) !== '' || $message['stopped'] || $message['failed'] || $message['posted'])
    {{-- The controls under an answer show when it is hovered or focused (always on a touch screen); a rating
         that was given stays visible, and so do the notes on how the answer ended. --}}
    <div class="fi-chat-answer-footer" x-data="{ note: false }">
        <button type="button" class="fi-chat-answer-tool fi-chat-tool" title="{{ __('Copy') }}" aria-label="{{ __('Copy answer') }}" x-on:click="copy($event.currentTarget, @js($message['text']))">
            <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-clipboard-document" class="fi-chat-icon" />
        </button>
        <button type="button" x-on:click="act('feedback', { message: @js($message['id']), rating: 'up' })" class="fi-chat-tool fi-chat-tool-up {{ $message['rating'] === 'up' ? 'fi-chat-rated' : 'fi-chat-answer-tool' }}" title="{{ __('Helpful') }}" aria-label="{{ __('Helpful') }}" aria-pressed="{{ $message['rating'] === 'up' ? 'true' : 'false' }}">
            <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-hand-thumb-up" class="fi-chat-icon" />
        </button>
        <button type="button" x-on:click="note = ! note; if (note) $nextTick(() => $refs.note.focus())" class="fi-chat-tool fi-chat-tool-down {{ $message['rating'] === 'down' ? 'fi-chat-rated' : 'fi-chat-answer-tool' }}" title="{{ __('Not helpful') }}" aria-label="{{ __('Not helpful') }}" aria-pressed="{{ $message['rating'] === 'down' ? 'true' : 'false' }}">
            <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-hand-thumb-down" class="fi-chat-icon" />
        </button>
        @if ($message['regenerable'])
            {{-- The last answer: produce it again. --}}
            <button type="button" class="fi-chat-answer-tool fi-chat-tool" title="{{ __('Regenerate') }}" aria-label="{{ __('Regenerate') }}" x-show="! busy" x-on:click="regenerate()">
                <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-arrow-path" class="fi-chat-icon" />
            </button>
        @endif
        @if ($message['continuable'])
            <x-dynamic-component :component="$ui.'::link'" tag="button" size="sm" icon="heroicon-m-forward" class="fi-chat-gap-start" x-show="! busy" x-on:click="continueAnswer()">{{ __('Continue') }}</x-dynamic-component>
        @endif
        @if ($message['regenerable'] && $lastQuestionId && $lastQuestionVersions > 0)
            {{-- Earlier answers to this question: page through them, the current one being the newest. --}}
            <x-dynamic-component :component="$ui.'::dropdown'" placement="bottom-start" width="sm">
                <x-slot name="trigger">
                    <button type="button" class="fi-chat-versions" x-show="! busy" aria-label="{{ __('Earlier answers') }}">
                        <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-chevron-left" class="fi-chat-flip fi-chat-icon-sm" />
                        <span>{{ $lastQuestionVersions + 1 }}/{{ $lastQuestionVersions + 1 }}</span>
                        <x-dynamic-component :component="$ui.'::icon'" icon="heroicon-m-chevron-right" class="fi-chat-flip fi-chat-icon-sm" />
                    </button>
                </x-slot>
                <x-dynamic-component :component="$ui.'::dropdown.list'">
                    @foreach ($chat->versions($lastQuestionId) as $n => $version)
                        <x-dynamic-component :component="$ui.'::dropdown.list.item'" icon="heroicon-m-clock" :x-on:click="'close(); act(\'version\', { question: '.\Illuminate\Support\Js::from($lastQuestionId).', version: '.((int) $version['id']).' })'">
                            <span class="fi-chat-version-item">
                                <span class="fi-chat-version-label">{{ __('Answer :n', ['n' => $n + 1]) }} · @if ($version['at'])<time datetime="{{ $version['at']->toIso8601String() }}" data-agents-time>{{ $version['at']->format('H:i') }}</time>@endif</span>
                                <span class="fi-chat-version-snippet">{{ \Illuminate\Support\Str::limit($version['text'], 80) }}</span>
                            </span>
                        </x-dynamic-component>
                    @endforeach
                    <x-dynamic-component :component="$ui.'::dropdown.list.item'" icon="heroicon-m-check" :disabled="true">
                        <span class="fi-chat-version-item"><span class="fi-chat-version-label">{{ __('Answer :n', ['n' => $lastQuestionVersions + 1]) }} · {{ __('current') }}</span></span>
                    </x-dynamic-component>
                </x-dynamic-component>
            </x-dynamic-component>
        @endif
        {{-- The server's clock here; the chat's script prints it in the reader's own timezone. --}}
        <span class="fi-chat-answer-tool fi-chat-foot-note">@if ($message['at'])<time datetime="{{ $message['at']->toIso8601String() }}" data-agents-time>{{ $message['at']->format('H:i') }}</time>@endif</span>
        @if ($message['stopped'])
            <span class="fi-chat-foot-note">· {{ __('(stopped)') }}</span>
        @endif
        @if ($message['posted'])
            {{-- Written by the app as the assistant (a digest, a reminder), not by the model: nothing to produce again. --}}
            <span class="fi-chat-foot-note" title="{{ __('Posted by :name, not written by the model.', ['name' => \Packstub\Agents\Facades\Agents::name()]) }}">· {{ __('(posted)') }}</span>
        @endif
        @if ($message['failed'])
            {{-- The provider gave up midway: what arrived is kept; Regenerate produces the answer again. The provider's own
                 error stays with the operator, in the turn log. --}}
            <span class="fi-chat-foot-note fi-chat-foot-note-bad">· {{ __('(interrupted)') }}</span>
        @endif
        @if ($message['cutShort'])
            {{-- The provider ended the answer early; Regenerate (above, on the last answer) produces it again, Continue carries on. --}}
            <span class="fi-chat-foot-note" title="{{ \Packstub\Agents\Support\AgentChat::cutShortText($message['cutShort']) }}">· {{ __('(cut short)') }}</span>
        @endif
        @if ($message['answeredBy'])
            {{-- The first choice refused the turn and a failover provider took it. --}}
            <span class="fi-chat-foot-note" title="{{ __('The usual provider was unavailable; this answer came from :model.', ['model' => $message['answeredBy']['model']]) }}">· {{ __('(answered by :provider)', ['provider' => \Packstub\Agents\Support\AgentModels::providerLabel($message['answeredBy']['provider'])]) }}</span>
        @endif
        @if ($message['ratingNote'])
            <span class="fi-chat-foot-note fi-chat-foot-note-quote" title="{{ __('Your note') }}">· “{{ \Illuminate\Support\Str::limit($message['ratingNote'], 60) }}”</span>
        @endif
        {{-- A thumbs-down may say what went wrong; the rating is saved with or without the note. --}}
        <form class="fi-chat-note" x-show="note" x-cloak x-on:submit.prevent="act('feedback', { message: @js($message['id']), rating: 'down', note: $refs.note.value }); note = false">
            <input x-ref="note" type="text" maxlength="1000" class="fi-chat-note-input" placeholder="{{ __('What went wrong? (optional)') }}" aria-label="{{ __('What went wrong?') }}" x-on:keydown.escape.prevent="note = false" />
            <x-dynamic-component :component="$ui.'::button'" type="submit" size="xs" color="gray">{{ __('Send') }}</x-dynamic-component>
        </form>
    </div>
@endif
