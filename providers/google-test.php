<?php
/**
 * Google OAuth Provider - Brain Test (Extended Scopes)
 *
 * Handles OAuth callback for Google APIs with extended scopes
 * including Analytics Admin API, Drive, Calendar, etc.
 *
 * Client ID: 2919741655-l5nee95mr45vip89j9fch0rtre86gnkd.apps.googleusercontent.com
 *
 * Usage:
 *   https://auth.giobi.com/google-test     - Start OAuth flow
 */

require_once __DIR__ . '/../lib/env.php';

// Load Google client library
require_once '/home/web/circus/vendor/autoload.php';

use Google\Client as Google_Client;

// Paths
$brainEnvPath = '/home/claude/brain/.env';

// OAuth scopes - extended for brain test
$scopes = [
    'https://www.googleapis.com/auth/gmail.modify',
    'https://www.googleapis.com/auth/gmail.send',
    'https://www.googleapis.com/auth/calendar',
    'https://www.googleapis.com/auth/calendar.events',
    'https://www.googleapis.com/auth/drive',
    'https://www.googleapis.com/auth/drive.file',
    'https://www.googleapis.com/auth/analytics.edit',           // NEW: Analytics Admin
    'https://www.googleapis.com/auth/analytics.readonly',
    'https://www.googleapis.com/auth/youtube.readonly',
    'https://www.googleapis.com/auth/webmasters',               // Search Console
    'https://www.googleapis.com/auth/userinfo.email',
    'https://www.googleapis.com/auth/userinfo.profile',
];

// Setup Google Client with Brain Test credentials
$client = new Google_Client();
$client->setClientId(getEnvVar('GOOGLE_BRAIN_TEST_CLIENT_ID'));
$client->setClientSecret(getEnvVar('GOOGLE_BRAIN_TEST_CLIENT_SECRET'));
$client->setRedirectUri('https://auth.giobi.com/google-test/callback');
$client->setAccessType('offline');
$client->setPrompt('consent');
$client->addScope($scopes);

// Handle callback
if (strpos($_SERVER['REQUEST_URI'], '/callback') !== false) {
    handleCallback($client, $brainEnvPath);
    exit;
}

// Start OAuth flow
$authUrl = $client->createAuthUrl();
header('Location: ' . $authUrl);
exit;


/**
 * Handle OAuth callback from Google
 */
function handleCallback($client, $envPath) {
    if (isset($_GET['error'])) {
        showError($_GET['error_description'] ?? $_GET['error']);
        return;
    }

    if (!isset($_GET['code'])) {
        showError('No authorization code received');
        return;
    }

    try {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

        if (isset($token['error'])) {
            showError($token['error_description'] ?? $token['error']);
            return;
        }

        $accessToken = $token['access_token'];
        $refreshToken = $token['refresh_token'] ?? null;

        // Update brain/.env with BRAIN_TEST variables
        $updated = [];

        if ($refreshToken) {
            updateEnvFile($envPath, 'GOOGLE_BRAIN_TEST_REFRESH_TOKEN', $refreshToken);
            $updated[] = 'GOOGLE_BRAIN_TEST_REFRESH_TOKEN';
        }

        // Get user info
        $userInfo = getUserInfo($accessToken);

        // Re-encrypt .env.gpg
        $encryptResult = reencryptEnv();

        showSuccess($updated, $userInfo, $encryptResult);

    } catch (Exception $e) {
        showError($e->getMessage());
    }
}


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


function reencryptEnv() {
    $cmd = "/home/claude/brain/tools/security/encrypt-env.sh 2>&1";
    $output = shell_exec($cmd);
    return $output;
}


function getUserInfo($accessToken) {
    $url = "https://www.googleapis.com/oauth2/v2/userinfo";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken"]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}


function getEnvVar($key) {
    $envPath = '/home/claude/brain/.env';
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


function showSuccess($updated, $userInfo, $encryptResult) {
    $email = $userInfo['email'] ?? 'Unknown';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Google OAuth (Brain Test) - Success</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 600px;
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
        h1 { color: #155724; }
        .success-box {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .info { color: #666; font-size: 14px; }
        code {
            background: #e9ecef;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 13px;
        }
        .updated-keys {
            list-style: none;
            padding: 0;
        }
        .updated-keys li {
            padding: 5px 0;
        }
        .updated-keys li::before {
            content: '✅ ';
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>✅ OAuth Success! (Brain Test)</h1>

        <div class="success-box">
            <strong>Authorized as:</strong> <?= htmlspecialchars($email) ?><br>
            <strong>App:</strong> Brain Test (extended scopes)<br>
            <strong>Includes:</strong> Analytics Admin API, Drive, Calendar, Gmail
        </div>

        <h3>Updated in brain/.env:</h3>
        <ul class="updated-keys">
            <?php foreach ($updated as $key): ?>
                <li><code><?= htmlspecialchars($key) ?></code></li>
            <?php endforeach; ?>
        </ul>

        <p class="info">
            <strong>Encryption:</strong>
            <?= $encryptResult ? 'Updated .env.gpg' : 'Skipped' ?>
        </p>

        <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">

        <p>
            <a href="https://auth.giobi.com" style="color: #007bff;">← Back to OAuth Hub</a>
        </p>

        <p class="info">
            ✨ You can now create Google Analytics properties via Brain!
        </p>
    </div>
</body>
</html>
<?php
}


function showError($message) {
?>
<!DOCTYPE html>
<html>
<head>
    <title>Google OAuth (Brain Test) - Error</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 600px;
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
        h1 { color: #721c24; }
        .error-box {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .btn {
            display: inline-block;
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            margin-top: 15px;
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

        <a href="/google-test" class="btn">Try Again →</a>

        <p style="margin-top: 30px; color: #666;">
            <a href="https://auth.giobi.com" style="color: #007bff;">← Back to OAuth Hub</a>
        </p>
    </div>
</body>
</html>
<?php
}
