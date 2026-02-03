<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Team Invitation</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            background-color: #6366f1;
            border-radius: 10px;
            color: white;
            font-size: 24px;
            margin-bottom: 10px;
        }
        .logo-text {
            font-size: 24px;
            font-weight: bold;
            color: #1f2937;
        }
        h1 {
            color: #1f2937;
            font-size: 24px;
            margin-bottom: 20px;
        }
        p {
            color: #4b5563;
            margin-bottom: 16px;
        }
        .highlight {
            color: #6366f1;
            font-weight: 600;
        }
        .role-badge {
            display: inline-block;
            background-color: #e0e7ff;
            color: #4338ca;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        .button {
            display: inline-block;
            background-color: #6366f1;
            color: #ffffff !important;
            text-decoration: none;
            padding: 14px 32px;
            border-radius: 8px;
            font-weight: 600;
            margin: 24px 0;
        }
        .button:hover {
            background-color: #4f46e5;
        }
        .details {
            background-color: #f9fafb;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .details-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .details-row:last-child {
            border-bottom: none;
        }
        .details-label {
            color: #6b7280;
            font-size: 14px;
        }
        .details-value {
            color: #1f2937;
            font-weight: 500;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #9ca3af;
            font-size: 14px;
        }
        .footer a {
            color: #6366f1;
            text-decoration: none;
        }
        .url-fallback {
            word-break: break-all;
            font-size: 12px;
            color: #9ca3af;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <div class="logo-icon">💬</div>
            <div class="logo-text">RChat</div>
        </div>

        <h1>You're Invited!</h1>

        <p>
            <span class="highlight">{{ $inviterName }}</span> has invited you to join
            <span class="highlight">{{ $companyName }}</span> on RChat.
        </p>

        <p>
            You've been assigned the role of <span class="role-badge">{{ $role }}</span>
        </p>

        <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f9fafb; border-radius: 8px; margin: 20px 0;">
            <tr>
                <td style="padding: 12px 20px; color: #6b7280; font-size: 14px; border-bottom: 1px solid #e5e7eb; width: 40%;">Company</td>
                <td style="padding: 12px 20px; color: #1f2937; font-weight: 500; border-bottom: 1px solid #e5e7eb;">{{ $companyName }}</td>
            </tr>
            <tr>
                <td style="padding: 12px 20px; color: #6b7280; font-size: 14px; border-bottom: 1px solid #e5e7eb;">Role</td>
                <td style="padding: 12px 20px; color: #1f2937; font-weight: 500; border-bottom: 1px solid #e5e7eb;">{{ $role }}</td>
            </tr>
            <tr>
                <td style="padding: 12px 20px; color: #6b7280; font-size: 14px;">Invitation expires</td>
                <td style="padding: 12px 20px; color: #1f2937; font-weight: 500;">{{ $expiresAt }}</td>
            </tr>
        </table>

        <p style="text-align: center;">
            <a href="{{ $acceptUrl }}" class="button">Accept Invitation</a>
        </p>

        <p class="url-fallback">
            If the button doesn't work, copy and paste this link into your browser:<br>
            {{ $acceptUrl }}
        </p>

        <div class="footer">
            <p>
                This invitation will expire on {{ $expiresAt }}.<br>
                If you didn't expect this invitation, you can safely ignore this email.
            </p>
            <p>
                &copy; {{ date('Y') }} RChat. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>
