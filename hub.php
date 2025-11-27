<?php
/**
 * OAuth Hub - Landing page
 */
?>
<!DOCTYPE html>
<html>
<head>
    <title>OAuth Hub - auth.giobi.com</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 700px;
            margin: 50px auto;
            padding: 20px;
            line-height: 1.6;
            background: #f8f9fa;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #333; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 30px; }
        .provider {
            background: #f5f5f5;
            padding: 20px;
            margin: 15px 0;
            border-radius: 8px;
            border-left: 4px solid #007bff;
        }
        .provider.microsoft { border-left-color: #00a4ef; }
        .provider.google { border-left-color: #4285f4; }
        .provider h3 { margin: 0 0 10px 0; }
        code {
            background: #e9ecef;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 90%;
        }
        .status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status.active { background: #d4edda; color: #155724; }
        .status.planned { background: #fff3cd; color: #856404; }
    </style>
</head>
<body>
    <div class="container">
        <h1>OAuth Hub</h1>
        <p class="subtitle">Multi-provider authentication gateway for Giobi's applications</p>

        <h2>Available Providers</h2>

        <div class="provider microsoft">
            <h3>Microsoft Graph (Office 365) <span class="status active">Active</span></h3>
            <p><strong>Callback:</strong> <code>https://auth.giobi.com/microsoft/callback</code></p>
            <p><strong>Used by:</strong> gigio-brain (Gigio Fasoli Office 365)</p>
            <p><strong>Scopes:</strong> Mail, Calendar, Files, User</p>
        </div>

        <div class="provider google">
            <h3>Google OAuth (Full Access) <span class="status active">Active</span></h3>
            <p><strong>Callback:</strong> <code>https://auth.giobi.com/google/callback</code></p>
            <p><strong>Used by:</strong> brain tools (Gmail, Drive, Calendar, Analytics)</p>
            <p><strong>Scopes:</strong> Full Google Workspace</p>
        </div>

        <div class="provider google">
            <h3>Google Login (SSO) <span class="status active">Active</span></h3>
            <p><strong>Callback:</strong> <code>https://auth.giobi.com/google-login/callback</code></p>
            <p><strong>Used by:</strong> status.giobi.com, ledger.giobi.com</p>
            <p><strong>Scopes:</strong> openid, email, profile</p>
        </div>

        <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">
        <p style="color: #999; font-size: 14px;">
            Giobi &copy; <?= date('Y') ?> |
            <a href="/status" style="color: #007bff;">Status</a>
        </p>
    </div>
</body>
</html>
