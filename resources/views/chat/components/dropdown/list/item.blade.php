@props(['icon' => null, 'disabled' => false])
<button type="button" role="menuitem" @disabled($disabled) {{ $attributes->class(['fi-chat-ui-dropdown-item']) }}>@if ($icon)<x-packstub-agents-chat::icon :icon="$icon" />@endif<span class="fi-dropdown-list-item-label">{{ $slot }}</span></button>
