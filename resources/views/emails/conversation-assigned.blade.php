<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $notification->title }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f8fafc; margin: 0; padding: 0;">
    <div style="max-width: 600px; margin: 0 auto; padding: 40px 20px;">
        <div style="background: white; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="text-align: center; margin-bottom: 24px;">
                <div style="width: 48px; height: 48px; background: #3b82f6; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center;">
                    <span style="color: white; font-size: 24px; font-weight: bold;">C</span>
                </div>
            </div>

            <h2 style="color: #0f172a; font-size: 20px; margin: 0 0 8px 0; text-align: center;">
                {{ $notification->title }}
            </h2>

            <p style="color: #475569; font-size: 16px; line-height: 1.6; text-align: center; margin: 0 0 24px 0;">
                {{ $notification->message }}
            </p>

            <div style="text-align: center;">
                <a href="{{ $actionUrl }}"
                   style="display: inline-block; background: #3b82f6; color: white; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-weight: 600; font-size: 14px;">
                    View Conversation
                </a>
            </div>
        </div>

        <p style="color: #94a3b8; font-size: 12px; text-align: center; margin-top: 24px;">
            You received this email because you have email notifications enabled in RChat.
        </p>
    </div>
</body>
</html>
