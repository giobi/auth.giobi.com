<?php
/**
 * Microsoft OAuth - Tenant Selection
 */
?>
<!DOCTYPE html>
<html>
<head>
    <title>Microsoft OAuth - auth.giobi.com</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 600px;
            margin: 100px auto;
            padding: 20px;
            background: #f8f9fa;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 16px;
        }
        .tenant-btn {
            display: block;
            width: 100%;
            padding: 20px;
            margin: 15px 0;
            border: 2px solid #00a4ef;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            color: #00a4ef;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
            text-align: left;
        }
        .tenant-btn:hover {
            background: #00a4ef;
            color: white;
            transform: translateX(5px);
        }
        .tenant-name {
            display: block;
            font-size: 18px;
            margin-bottom: 5px;
        }
        .tenant-domain {
            display: block;
            font-size: 14px;
            opacity: 0.8;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #666;
            text-decoration: none;
            font-size: 14px;
        }
        .back-link:hover {
            color: #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏢 Microsoft OAuth</h1>
        <p class="subtitle">Select your organization</p>

        <a href="/microsoft/callback?state=<?= htmlspecialchars($_GET['state'] ?? 'fasolipiante') ?>" class="tenant-btn">
            <span class="tenant-name">Fasoli Piante</span>
            <span class="tenant-domain">@fasolipiante.it</span>
        </a>

        <!-- Future tenants will be added here -->

        <a href="/" class="back-link">← Back to home</a>
    </div>
</body>
</html>
