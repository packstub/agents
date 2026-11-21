@props(['tag' => 'a', 'type' => 'button', 'href' => null, 'size' => 'md', 'color' => 'primary', 'icon' => null])
@php($classes = ['fi-chat-ui-link', 'fi-chat-ui-link-'.$size, 'fi-chat-ui-link-'.$color])
@if ($tag === 'button')
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>@if ($icon)<x-packstub-agents-chat::icon :icon="$icon" />@endif<span>{{ $slot }}</span></button>
@else
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>@if ($icon)<x-packstub-agents-chat::icon :icon="$icon" />@endif<span>{{ $slot }}</span></a>
@endif
