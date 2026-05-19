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
            max-width: 500px;
            margin: 100px auto;
            padding: 20px;
            background: #f8f9fa;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 40px;
            font-size: 16px;
        }
        .login-btn {
            display: block;
            width: 100%;
            padding: 16px;
            margin: 15px 0;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            color: white;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .microsoft {
            background: linear-gradient(135deg, #00a4ef 0%, #0078d4 100%);
        }
        .google {
            background: linear-gradient(135deg, #4285f4 0%, #357ae8 100%);
        }
        .dropbox {
            background: linear-gradient(135deg, #0061ff 0%, #004fc4 100%);
        }
        .footer {
            margin-top: 30px;
            color: #999;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Login with</h1>
        <p class="subtitle">Choose your authentication provider</p>

        <a href="/google?app=brain&state=login" class="login-btn google">
            Google (Brain)
        </a>

        <a href="/google?app=brain-test&state=login" class="login-btn google">
            Google (Brain Test)
        </a>

        <a href="/dropbox?state=login" class="login-btn dropbox">
            Dropbox
        </a>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
            <p style="color: #999; font-size: 14px; margin-bottom: 10px;">Private tenants:</p>
            <a href="/microsoft" style="color: #00a4ef; text-decoration: none; font-size: 14px;">
                → Microsoft / Office 365
            </a>
        </div>

        <div class="footer">
            <a href="/privacy" style="color: #007bff; text-decoration: none;">Privacy Policy</a>
        </div>
    </div>
</body>
</html>
