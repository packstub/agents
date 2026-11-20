@props(['icon' => null])
@php($svg = \Packstub\Agents\Chat\ChatIcons::svg($icon))
@if ($svg !== '')
    {!! preg_replace('/<svg /', '<svg '.$attributes->class(['fi-chat-ui-icon'])->toHtml().' ', $svg, 1) !!}
@endif
