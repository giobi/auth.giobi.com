<?php
/**
 * Dropbox OAuth Provider
 *
 * Handles OAuth callback for Dropbox API (Full access)
 *
 * Flow:
 * 1. Visit /dropbox → redirects to Dropbox consent screen
 * 2. User authorizes → Dropbox redirects to /dropbox/callback
 * 3. Callback exchanges code for tokens → saves to specified .env
 *
 * Usage:
 *   https://auth.giobi.com/dropbox     - Start OAuth flow (saves to brain/.env)
 *   https://auth.giobi.com/dropbox?env=/path/to/.env  - Specify custom .env path
 */

require_once __DIR__ . '/../lib/env.php';
require_once __DIR__ . '/../lib/oauth_db.php';

/**
 * Get env var from auth.giobi.com/.env
 */
function getEnvVar($key) {
    $envPath = __DIR__ . '/../.env';
    if (!file_exists($envPath)) return null;

    $lines = file($envPath, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $line) {
        if (strpos($line, "$key=") === 0) {
            $value = substr($line, strlen($key) + 1);
            return trim($value, " \t\n\r\0\x0B\"'");
        }
    }
    return null;
}

// Default env path (brain)
$defaultEnvPath = '/home/claude/brain/.env';

// Get env path from query param or use default
$envPath = $_GET['env'] ?? $defaultEnvPath;

// Validate env path (security: only allow whitelisted paths)
$allowedEnvPaths = [
    '/home/claude/brain/.env',
    '/var/abchat/workspaces',  // Any workspace .env
];

$isAllowed = false;
foreach ($allowedEnvPaths as $allowed) {
    if (strpos($envPath, $allowed) === 0) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    die('Error: Invalid env path');
}

// Dropbox OAuth config
$clientId = getenv('DROPBOX_APP_KEY') ?: getEnvVar('DROPBOX_APP_KEY');
$clientSecret = getenv('DROPBOX_APP_SECRET') ?: getEnvVar('DROPBOX_APP_SECRET');
$redirectUri = 'https://auth.giobi.com/dropbox/callback';

if (!$clientId || !$clientSecret) {
    die('Error: DROPBOX_APP_KEY or DROPBOX_APP_SECRET not configured in auth.giobi.com/.env');
}

// Handle callback
if (strpos($_SERVER['REQUEST_URI'], '/callback') !== false) {
    handleCallback($clientId, $clientSecret, $redirectUri, $envPath);
    exit;
}

// Start OAuth flow
$state = bin2hex(random_bytes(16));
session_start();
$_SESSION['oauth_state'] = $state;
$_SESSION['env_path'] = $envPath;

$authUrl = 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'token_access_type' => 'offline',  // Request refresh token
    'state' => $state
]);

header('Location: ' . $authUrl);
exit;


/**
 * Handle OAuth callback from Dropbox
 */
function handleCallback($clientId, $clientSecret, $redirectUri, $envPath) {
    session_start();

    // Verify state
    if (!isset($_GET['state']) || !isset($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
        showError('Invalid state parameter (CSRF protection)');
        return;
    }

    // Get env path from session
    $envPath = $_SESSION['env_path'] ?? $envPath;

    // Check for error
    if (isset($_GET['error'])) {
        showError($_GET['error_description'] ?? $_GET['error']);
        return;
    }

    // Check for authorization code
    if (!isset($_GET['code'])) {
        showError('No authorization code received');
        return;
    }

    try {
        // Exchange code for tokens
        $ch = curl_init('https://api.dropboxapi.com/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $_GET['code'],
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri
            ])
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            showError("Token exchange failed (HTTP $httpCode): $response");
            return;
        }

        $token = json_decode($response, true);

        if (isset($token['error'])) {
            showError($token['error_description'] ?? $token['error']);
            return;
        }

        $accessToken = $token['access_token'];
        $refreshToken = $token['refresh_token'] ?? null;
        $expiresIn = $token['expires_in'] ?? null;
        $expiresAt = $expiresIn ? (time() + $expiresIn) : null;

        // Get account info first to get email
        $accountInfo = getDropboxAccountInfo($accessToken);
        $userEmail = $accountInfo['email'] ?? 'unknown@example.com';

        // Save to centralized OAuth DB
        $db = new OAuthDB();
        $db->saveTokens($userEmail, 'dropbox', $accessToken, $refreshToken, $expiresAt);

        $updated = ['Saved to OAuth DB for ' . $userEmail];

        // Show success
        showSuccess($accessToken, $refreshToken, $userEmail, $updated, $accountInfo);

    } catch (Exception $e) {
        showError($e->getMessage());
    }
}


/**
 * Get Dropbox account info
 */
function getDropboxAccountInfo($accessToken) {
    $ch = curl_init('https://api.dropboxapi.com/2/users/get_current_account');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => 'null'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}


/**
 * Update env file with key=value
 */
function updateEnvFile($path, $key, $value) {
    $content = file_get_contents($path);
    $lines = explode("\n", $content);
    $updated = false;

    foreach ($lines as &$line) {
        if (strpos($line, "$key=") === 0) {
            $line = "$key=$value";
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        $lines[] = "$key=$value";
    }

    file_put_contents($path, implode("\n", $lines));
}


/**
 * Get env var from specific file
 */
function getEnvVarFromFile($envPath, $key) {
    if (!file_exists($envPath)) {
        return null;
    }

    $content = file_get_contents($envPath);
    if (preg_match("/^$key=(.*)$/m", $content, $matches)) {
        return trim($matches[1], '"\'');
    }
    return null;
}


/**
 * Show success page
 */
function showSuccess($accessToken, $refreshToken, $userEmail, $updated, $accountInfo) {
    $accountName = $accountInfo['name']['display_name'] ?? 'Unknown';
    $accountEmail = $accountInfo['email'] ?? 'Unknown';
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Dropbox OAuth - Success</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                max-width: 800px;
                margin: 50px auto;
                padding: 20px;
                background: #f8f9fa;
            }
            .container {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            h1 { color: #28a745; margin-bottom: 10px; }
            .info { background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 20px 0; }
            .token { background: #f5f5f5; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 12px; word-break: break-all; margin: 10px 0; }
            .label { font-weight: 600; color: #666; margin-bottom: 5px; }
            .success { color: #28a745; font-weight: 600; }
            code { background: #e9ecef; padding: 2px 6px; border-radius: 3px; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>✓ Dropbox OAuth Successful</h1>

            <div class="info">
                <p><strong>Account:</strong> <?= htmlspecialchars($accountName) ?> (<?= htmlspecialchars($accountEmail) ?>)</p>
                <p><strong>Tokens saved to:</strong> <code>auth.giobi.com OAuth DB</code></p>
                <p><strong>Email:</strong> <code><?= htmlspecialchars($userEmail) ?></code></p>
                <p><strong>Status:</strong> <?= implode(', ', array_map('htmlspecialchars', $updated)) ?></p>
            </div>

            <h3>Access Token</h3>
            <div class="token"><?= htmlspecialchars($accessToken) ?></div>

            <?php if ($refreshToken): ?>
            <h3>Refresh Token</h3>
            <div class="token"><?= htmlspecialchars($refreshToken) ?></div>
            <?php endif; ?>

            <h3>Test the wrapper</h3>
            <pre style="background: #f5f5f5; padding: 15px; border-radius: 5px;">
from tools.lib.dropbox_client import DropboxClient

# Initialize client for this user
client = DropboxClient(email='<?= htmlspecialchars($userEmail) ?>')

# List files
files = client.list_files('/')

# Get account info
info = client.get_account_info()
print(f"Connected as: {info['name']}")
            </pre>

            <p style="margin-top: 30px;">
                <a href="/hub" style="background: #007bff; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">← Back to OAuth Hub</a>
            </p>
        </div>
    </body>
    </html>
    <?php
}


/**
 * Show error page
 */
function showError($message) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Dropbox OAuth - Error</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                max-width: 700px;
                margin: 50px auto;
                padding: 20px;
                background: #f8f9fa;
            }
            .container {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            h1 { color: #dc3545; }
            .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>✗ Dropbox OAuth Error</h1>
            <div class="error">
                <?= htmlspecialchars($message) ?>
            </div>
            <p><a href="/dropbox" style="color: #007bff;">← Try again</a></p>
        </div>
    </body>
    </html>
    <?php
}
