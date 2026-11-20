@props(['icon' => null, 'color' => 'gray', 'size' => 'md', 'label' => null, 'tooltip' => null])
<button type="button" title="{{ $tooltip ?? $label }}" aria-label="{{ $label }}" {{ $attributes->class(['fi-chat-ui-icon-btn', 'fi-chat-ui-icon-btn-'.$size, 'fi-chat-ui-icon-btn-'.$color]) }}><x-packstub-agents-chat::icon :icon="$icon" /></button>
