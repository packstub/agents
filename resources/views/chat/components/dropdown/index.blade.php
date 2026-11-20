@props(['placement' => 'bottom-start', 'width' => null, 'shift' => false, 'trigger' => null])
{{-- A small menu: the trigger toggles it, a click outside or Escape closes it. `close()` is in scope for its items. --}}
<div
    x-data="{ open: false, toggle() { this.open = ! this.open }, close() { this.open = false } }"
    x-on:keydown.escape="close()"
    x-on:click.outside="close()"
    {{ $attributes->class(['fi-chat-ui-dropdown']) }}
>
    <div class="fi-chat-ui-dropdown-trigger" x-on:click="toggle()" x-bind:aria-expanded="open">{{ $trigger }}</div>
    <div class="fi-chat-ui-dropdown-panel fi-chat-ui-dropdown-{{ $placement }} fi-chat-ui-dropdown-w-{{ $width ?? 'auto' }}" x-show="open" x-cloak x-transition.opacity.duration.100ms>{{ $slot }}</div>
</div>
