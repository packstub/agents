@props(['tag' => 'button', 'type' => 'button', 'size' => 'md', 'color' => 'primary', 'outlined' => false, 'icon' => null, 'href' => null, 'labelSrOnly' => false])
@php($classes = ['fi-chat-ui-btn', 'fi-chat-ui-btn-'.$size, 'fi-chat-ui-btn-'.$color, 'fi-chat-ui-btn-outlined' => $outlined, 'fi-chat-ui-btn-square' => $labelSrOnly])
@if ($tag === 'a')
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>@if ($icon)<x-packstub-agents-chat::icon :icon="$icon" />@endif<span @class(['fi-chat-ui-sr' => $labelSrOnly])>{{ $slot }}</span></a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>@if ($icon)<x-packstub-agents-chat::icon :icon="$icon" />@endif<span @class(['fi-chat-ui-sr' => $labelSrOnly])>{{ $slot }}</span></button>
@endif
