<?php
/**
 * Universal OAuth Callback Handler
 * 
 * Generic callback endpoint that routes to provider-specific handlers.
 * For future apps, register redirect_uri as: https://auth.giobi.com/callback
 * 
 * Routing logic:
 * 1. Check session for 'oauth_provider'
 * 2. Check state parameter format
 * 3. Delegate to provider handler
 */

session_start();

// Detect provider from session or state
$provider = $_SESSION['oauth_provider'] ?? null;

// Fallback: try to detect from state parameter
if (!$provider && isset($_GET['state'])) {
    $state = $_GET['state'];
    
    // State format convention: provider-randomstring
    if (strpos($state, 'google-') === 0) {
        $provider = 'google';
    } elseif (strpos($state, 'microsoft-') === 0) {
        $provider = 'microsoft';
    } elseif (strpos($state, 'dropbox-') === 0) {
        $provider = 'dropbox';
    } elseif (strpos($state, 'canva-') === 0) {
        $provider = 'canva';
    }
}

// Route to provider handler
switch ($provider) {
    case 'google':
        require __DIR__ . '/providers/google.php';
        break;
        
    case 'microsoft':
        require __DIR__ . '/providers/microsoft.php';
        break;
        
    case 'dropbox':
        require __DIR__ . '/providers/dropbox.php';
        break;

    case 'canva':
        require __DIR__ . '/providers/canva.php';
        break;

    default:
        showError('Unknown OAuth provider. Session may have expired.');
}

function showError($message) {
?>
<!DOCTYPE html>
<html>
<head>
    <title>OAuth Callback Error</title>
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
            text-align: center;
        }
        h1 { color: #721c24; }
        .error-box {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .btn {
            display: inline-block;
            background: #007bff;
            color: white;
            padding: 12px 24px;
            border-radius: 5px;
            text-decoration: none;
            margin-top: 20px;
        }
        .btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <div class="container">
        <h1>❌ OAuth Error</h1>
        
        <div class="error-box">
            <?= htmlspecialchars($message) ?>
        </div>
        
        <a href="/" class="btn">← Back to OAuth Hub</a>
    </div>
</body>
</html>
<?php
    exit;
}
