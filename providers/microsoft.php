<?php
/**
 * Microsoft OAuth Provider
 *
 * Handles OAuth callback for Microsoft Graph (Office 365).
 * Exchanges code for tokens and delivers via webhook POST.
 *
 * Flow:
 * 1. App redirects user to Microsoft with state=app_name
 * 2. User authorizes
 * 3. Microsoft redirects here with code + state
 * 4. We exchange code for tokens
 * 5. We POST tokens to app's webhook URL (from database)
 * 6. Show success page
 */

require_once __DIR__ . '/../lib/db.php';

// Check for OAuth code
if (!isset($_GET['code'])) {
    http_response_code(400);
    die('Missing authorization code. Start OAuth flow from your application.');
}

$code = $_GET['code'];
$app_name = $_GET['state'] ?? null;

// Get credentials from env
$client_id = env('MS_GRAPH_CLIENT_ID');
$client_secret = env('MS_GRAPH_CLIENT_SECRET');
$tenant_id = env('MS_GRAPH_TENANT_ID');
$redirect_uri = env('MS_GRAPH_REDIRECT_URI', 'https://auth.giobi.com/microsoft/callback');

// Validate credentials
if (empty($client_id) || empty($client_secret) || empty($tenant_id)) {
    http_response_code(500);
    die('Microsoft Graph credentials not configured in .env');
}

// Exchange code for tokens
$token_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";

$post_data = [
    'client_id' => $client_id,
    'client_secret' => $client_secret,
    'code' => $code,
    'redirect_uri' => $redirect_uri,
    'grant_type' => 'authorization_code',
    'scope' => 'Calendars.Read Calendars.ReadWrite Files.Read.All Mail.Read Mail.ReadBasic Mail.ReadWrite Mail.ReadWrite.Shared Mail.Send offline_access User.Read'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$response = curl_exec($ch);
$curl_error = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Debug on error
if ($http_code !== 200) {
    http_response_code(500);
    $error = json_decode($response, true);
    ?>
    <!DOCTYPE html>
    <html>
    <head><title>OAuth Error</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 20px; border-radius: 5px; }
        pre { background: #f5f5f5; padding: 15px; overflow-x: auto; border-radius: 5px; }
        h2 { color: #721c24; }
    </style>
    </head>
    <body>
        <h2>Token Exchange Failed</h2>
        <div class="error">
            <p><strong>HTTP Code:</strong> <?= $http_code ?></p>
            <p><strong>Error:</strong> <?= htmlspecialchars($error['error'] ?? 'unknown') ?></p>
            <p><strong>Description:</strong> <?= htmlspecialchars($error['error_description'] ?? 'No description') ?></p>
            <?php if ($curl_error): ?>
            <p><strong>Curl Error:</strong> <?= htmlspecialchars($curl_error) ?></p>
            <?php endif; ?>
        </div>
        <h3>Debug Info</h3>
        <pre>Token URL: <?= htmlspecialchars($token_url) ?>

Client ID: <?= $client_id ? substr($client_id, 0, 8) . '...' : 'MISSING' ?>
Tenant ID: <?= $tenant_id ? substr($tenant_id, 0, 8) . '...' : 'MISSING' ?>
Redirect URI: <?= htmlspecialchars($redirect_uri) ?></pre>
        <h3>Raw Response</h3>
        <pre><?= htmlspecialchars($response) ?></pre>
    </body>
    </html>
    <?php
    exit;
}

$tokens = json_decode($response, true);

if (!isset($tokens['refresh_token'])) {
    http_response_code(500);
    die('No refresh token received. Ensure offline_access scope is granted in Azure AD.');
}

// Webhook delivery
$webhook_sent = false;
$webhook_error = null;

if ($app_name) {
    $pdo = get_db();
    $webhook_url = get_app_callback($pdo, $app_name);

    if ($webhook_url) {
        // POST tokens to webhook
        $webhook_payload = json_encode([
            'provider' => 'microsoft',
            'app' => $app_name,
            'tokens' => [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_in' => $tokens['expires_in'],
                'token_type' => $tokens['token_type'],
                'scope' => $tokens['scope']
            ],
            'timestamp' => date('c')
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $webhook_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $webhook_payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Auth-Provider: auth.giobi.com',
            'X-Auth-Signature: ' . hash_hmac('sha256', $webhook_payload, env('WEBHOOK_SECRET', 'default-secret'))
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $webhook_response = curl_exec($ch);
        $webhook_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $webhook_curl_error = curl_error($ch);
        curl_close($ch);

        if ($webhook_http_code >= 200 && $webhook_http_code < 300) {
            $webhook_sent = true;
        } else {
            $webhook_error = "HTTP $webhook_http_code: $webhook_curl_error";
        }
    }
}

// Fallback: save to local .env (for apps on same server)
$env_file = __DIR__ . '/../.env';
if (file_exists($env_file)) {
    $env_contents = file_get_contents($env_file);

    if (strpos($env_contents, 'MS_GRAPH_REFRESH_TOKEN=') !== false) {
        $env_contents = preg_replace(
            '/MS_GRAPH_REFRESH_TOKEN="[^"]*"/',
            'MS_GRAPH_REFRESH_TOKEN="' . $tokens['refresh_token'] . '"',
            $env_contents
        );
    } else {
        $env_contents .= "\n# Auto-saved by OAuth callback - " . date('Y-m-d H:i:s') . "\n";
        $env_contents .= 'MS_GRAPH_REFRESH_TOKEN="' . $tokens['refresh_token'] . '"' . "\n";
    }

    file_put_contents($env_file, $env_contents);
}

// Success page
?>
<!DOCTYPE html>
<html>
<head>
    <title>OAuth Success - Microsoft Graph</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            line-height: 1.6;
            text-align: center;
        }
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .info {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            text-align: left;
        }
        code {
            background: #eee;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 90%;
        }
        h1 { color: #155724; }
    </style>
</head>
<body>
    <h1>OAuth Authorization Successful!</h1>

    <div class="success">
        <p><strong>Microsoft Graph tokens received.</strong></p>
        <?php if ($webhook_sent): ?>
        <p>Tokens delivered to <code><?= htmlspecialchars($app_name) ?></code> via webhook.</p>
        <?php else: ?>
        <p>Refresh token stored locally.</p>
        <?php endif; ?>
    </div>

    <?php if ($app_name && !$webhook_sent): ?>
    <div class="warning">
        <p><strong>Webhook delivery failed:</strong></p>
        <p><?= htmlspecialchars($webhook_error ?? 'App not found in database') ?></p>
        <p>Token saved locally as fallback.</p>
    </div>
    <?php endif; ?>

    <div class="info">
        <h3>Token Details</h3>
        <ul>
            <li><strong>Access token expires in:</strong> <?= $tokens['expires_in'] ?> seconds (~1 hour)</li>
            <li><strong>Refresh token:</strong> Saved (valid ~90 days)</li>
            <li><strong>Token type:</strong> <?= $tokens['token_type'] ?></li>
            <?php if ($app_name): ?>
            <li><strong>App:</strong> <?= htmlspecialchars($app_name) ?></li>
            <?php endif; ?>
        </ul>

        <h3>Granted Scopes</h3>
        <p><code><?= htmlspecialchars($tokens['scope']) ?></code></p>
    </div>

    <p><small>You can close this window.</small></p>
</body>
</html>
