{{-- The chat's stylesheet and script, once per page. The script registers the chat on the app's Alpine when there is
     one (Livewire's, or the app's own) and starts its own copy when there is none. --}}
@once
    <link rel="stylesheet" href="{{ \Packstub\Agents\Chat\ChatAssets::url('agents-chat.css') }}" />
    <script src="{{ \Packstub\Agents\Chat\ChatAssets::url('agents-chat.js') }}" data-alpine="{{ config('packstub-agents.chat.ui.alpine', 'auto') }}"></script>
@endonce
