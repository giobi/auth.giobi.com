<?php
/**
 * Privacy Policy - auth.giobi.com
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - auth.giobi.com</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 700px;
            margin: 50px auto;
            padding: 20px;
            line-height: 1.8;
            background: #f8f9fa;
            color: #333;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #333; margin-bottom: 10px; border-bottom: 2px solid #007bff; padding-bottom: 15px; }
        h2 { color: #444; margin-top: 30px; }
        .last-updated { color: #666; font-size: 14px; margin-bottom: 30px; }
        ul { margin: 15px 0; padding-left: 25px; }
        li { margin: 8px 0; }
        .contact { background: #f5f5f5; padding: 20px; border-radius: 8px; margin-top: 30px; }
        a { color: #007bff; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Privacy Policy</h1>
        <p class="last-updated">Last updated: <?= date('F j, Y') ?></p>

        <h2>1. Introduction</h2>
        <p>
            This Privacy Policy describes how auth.giobi.com ("we", "our", or "the Service")
            handles information when you use our OAuth authentication gateway.
        </p>
        <p>
            <strong>auth.giobi.com is a personal authentication gateway</strong> operated by
            Giobi Fasoli for internal use. It is not a public service and is intended
            exclusively for the owner's personal applications and automation tools.
        </p>

        <h2>2. Information We Access</h2>
        <p>When you authorize this application, we may request access to:</p>
        <ul>
            <li><strong>Google Services:</strong> Gmail (labels), Google Drive (app-created files),
                Google Calendar (free/busy status), Google Search Console, YouTube (downloads)</li>
            <li><strong>Microsoft Services:</strong> Office 365 Mail, Calendar, OneDrive, User Profile</li>
            <li><strong>Basic Profile:</strong> Email address, name, profile picture</li>
        </ul>

        <h2>3. How We Use Information</h2>
        <p>The accessed data is used exclusively for:</p>
        <ul>
            <li>Personal productivity automation (email management, calendar sync)</li>
            <li>File organization and backup</li>
            <li>SEO monitoring and analytics</li>
            <li>Development and testing of personal tools</li>
        </ul>
        <p><strong>We do not:</strong></p>
        <ul>
            <li>Sell or share your data with third parties</li>
            <li>Use your data for advertising</li>
            <li>Store data beyond what is necessary for the service to function</li>
        </ul>

        <h2>4. Data Storage</h2>
        <p>
            OAuth tokens are stored securely on our private server. Access tokens are refreshed
            automatically and old tokens are discarded. We implement reasonable security measures
            to protect stored credentials.
        </p>

        <h2>5. Data Retention</h2>
        <p>
            We retain OAuth tokens only as long as necessary to provide the service.
            You can revoke access at any time through your Google or Microsoft account settings.
        </p>

        <h2>6. Your Rights</h2>
        <p>You have the right to:</p>
        <ul>
            <li>Revoke access at any time via your Google/Microsoft account</li>
            <li>Request information about what data we access</li>
            <li>Request deletion of any stored tokens</li>
        </ul>

        <h2>7. Third-Party Services</h2>
        <p>
            This service integrates with Google and Microsoft APIs. Your use of those services
            is also governed by their respective privacy policies:
        </p>
        <ul>
            <li><a href="https://policies.google.com/privacy" target="_blank">Google Privacy Policy</a></li>
            <li><a href="https://privacy.microsoft.com/privacystatement" target="_blank">Microsoft Privacy Statement</a></li>
        </ul>

        <h2>8. Changes to This Policy</h2>
        <p>
            We may update this Privacy Policy from time to time. Changes will be posted on this page
            with an updated revision date.
        </p>

        <div class="contact">
            <h2 style="margin-top: 0;">9. Contact</h2>
            <p>For privacy-related inquiries:</p>
            <p>
                <strong>Giobi Fasoli</strong><br>
                Email: <a href="mailto:info@giobi.com">info@giobi.com</a><br>
                Website: <a href="https://giobi.com">giobi.com</a>
            </p>
        </div>

        <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">
        <p style="text-align: center;">
            <a href="/">← Back to OAuth Hub</a>
        </p>
    </div>
</body>
</html>
