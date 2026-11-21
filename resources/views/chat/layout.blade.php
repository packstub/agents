{{-- The page around the package's own chat pages (chat.ui.page). Name a layout of your app in chat.ui.layout: a view
     that yields "content" — or publish this file. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>@yield('title', \Packstub\Agents\Facades\Agents::name()) · {{ config('app.name') }}</title>
    @stack('head')
</head>
<body class="fi-chat-page">
    <main class="fi-chat-page-main">
        @yield('content')
    </main>
</body>
</html>
