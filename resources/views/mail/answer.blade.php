<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $name }}</title>
</head>
<body style="margin: 0; padding: 24px; background: #f6f7f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #111827; font-size: 15px; line-height: 1.55;">
    <div style="max-width: 640px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px 28px;">
        <p style="margin: 0 0 16px; font-size: 12px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280;">{{ $name }}</p>

        @if ($failed)
            <p style="margin: 0 0 12px;">{{ __('The assistant could not answer.') }} {{ $error }}</p>
        @else
            <div style="margin: 0;">{!! $html !!}</div>
        @endif

        @if ($proposals->isNotEmpty())
            <div style="margin-top: 20px; padding: 12px 16px; border: 1px solid #fde68a; background: #fffbeb; border-radius: 8px;">
                <p style="margin: 0 0 6px; font-weight: 600;">{{ __('Waiting for your decision') }}</p>
                <ul style="margin: 0; padding-left: 18px;">
                    @foreach ($proposals as $proposal)
                        <li>{{ $proposal['question'] }}</li>
                    @endforeach
                </ul>
                <p style="margin: 8px 0 0; font-size: 13px; color: #6b7280;">{{ __('A change is only made after you approve it in the chat.') }}</p>
            </div>
        @endif

        <p style="margin: 24px 0 0; font-size: 13px; color: #6b7280;">
            {{ __('Reply to this email to continue the conversation.') }}
            @if ($chatUrl)
                <a href="{{ $chatUrl }}" style="color: #4f46e5;">{{ __('Open it in the app') }}</a>
            @endif
        </p>
    </div>
</body>
</html>
