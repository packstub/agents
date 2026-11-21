@extends($layout ?: 'packstub-agents-chat::layout')

@section('title', $chat->title())

@section('content')
    <x-agents-chat :view="$chat" />
@endsection
