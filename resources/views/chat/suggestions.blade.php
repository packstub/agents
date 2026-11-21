{{-- A new chat: the assistant's name and its starter questions (Agent::suggestions), each sent on click. Gone the
     moment a question is on its way (busy), and not rendered once the conversation exists. Part of chat.blade.php,
     included with its variables; publish this file alone to change only this part. --}}
@agentsPart('suggestions')
@if ($suggestions)
    <div class="fi-chat-empty" x-show="! busy">
        <span class="fi-chat-empty-icon"><x-dynamic-component :component="$ui.'::icon'" icon="heroicon-o-sparkles" /></span>
        <p class="fi-chat-empty-title">{{ \Packstub\Agents\Facades\Agents::name() }}</p>
        <p class="fi-chat-empty-lead">{{ $chat->contextLabel() ? __('Ask anything about :record, or start with one of these.', ['record' => $chat->contextLabel()]) : __('Ask anything about your workspace, or start with one of these.') }}</p>
        <div class="fi-chat-suggestions">
            @foreach ($suggestions as $suggestion)
                <button type="button" class="fi-chat-suggestion" x-on:click="enqueue(@js($suggestion))">{{ $suggestion }}</button>
            @endforeach
        </div>
        <p class="fi-chat-empty-hint">{{ $host->canMention() ? __('Type / for these questions, @ to mention a record.') : __('Type / for these questions.') }}</p>
    </div>
@endif
@endAgentsPart
