<?php
/**
 * Google OAuth Provider (Multi-App)
 *
 * Handles OAuth for multiple Google Cloud projects:
 * - ?app=brain → GMAIL_* credentials (default)
 * - ?app=brain-test → GOOGLE_BRAIN_TEST_* credentials (extended scopes)
 *
 * Usage:
 *   https://auth.giobi.com/google?app=brain
 *   https://auth.giobi.com/google?app=brain-test
 */

require_once __DIR__ . '/../lib/env.php';
require_once __DIR__ . '/../lib/logger.php';
require_once '/home/web/circus/vendor/autoload.php';

use Google\Client as Google_Client;

session_start();

// Detect app from state parameter (callback) or query param (initial request)
if (strpos($_SERVER['REQUEST_URI'], '/callback') !== false) {
    // Callback: extract app from state parameter
    $state = $_GET['state'] ?? '';
    $app = 'brain'; // default

    if (preg_match('/^(brain-test|brain)-/', $state, $matches)) {
        $app = $matches[1];
    }

    logOAuthAttempt('google_callback', [
        'app' => $app,
        'state' => $state,
        'has_code' => isset($_GET['code']),
        'has_error' => isset($_GET['error']),
    ]);
} else {
    // Initial request: get app from query param
    $app = $_GET['app'] ?? 'brain';
    // from= : return URL dinamico, validato SUBITO contro l'allowlist (fail-fast)
    $returnUrl = $_GET['from'] ?? '';
    if ($returnUrl !== '' && !return_host_allowed($returnUrl)) {
        http_response_code(400);
        header('Content-Type: text/plain');
        logOAuthAttempt('google_from_rejected', ['app' => $app]); // niente url completo nei log
        exit('from: return URL non consentito (host non in ALLOWED_RETURN_HOSTS)');
    }
    logOAuthAttempt('google_start', ['app' => $app, 'has_from' => $returnUrl !== '']);
}

// Get app configuration
$config = getAppConfig($app);

// Setup Google Client
$client = new Google_Client();
$client->setClientId($config['client_id']);
$client->setClientSecret($config['client_secret']);
$client->setRedirectUri('https://auth.giobi.com/google/callback');
$client->setAccessType('offline');
$client->setPrompt('consent');  // Force consent screen ALWAYS
$client->setApprovalPrompt('force');  // Force approval (legacy parameter)
$client->setIncludeGrantedScopes(false);  // Don't use incremental auth
$client->addScope($config['scopes']);

/**
 * Return URL allowlist: https + host in ALLOWED_RETURN_HOSTS (wildcard *.dominio).
 * Anti open-redirect: from= accettato SOLO se l'host matcha l'allowlist.
 */
function return_host_allowed($url) {
    $p = @parse_url((string)$url);
    if (!$p || ($p['scheme'] ?? '') !== 'https') return false;
    if (!empty($p['user']) || !empty($p['pass'])) return false;
    $host = strtolower($p['host'] ?? '');
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return false;
    $raw = function_exists('env') ? env('ALLOWED_RETURN_HOSTS', '') : getenv('ALLOWED_RETURN_HOSTS');
    foreach (array_filter(array_map('trim', explode(',', (string)$raw))) as $pat) {
        $pat = strtolower($pat);
        if (strpos($pat, '*.') === 0) {
            $base = substr($pat, 2);
            if ($host === $base ||
                (strlen($host) > strlen($base) + 1 &&
                 substr($host, -(strlen($base) + 1)) === '.' . $base)) {
                return true;
            }
        } elseif ($host === $pat) {
            return true;
        }
    }
    return false;
}

/**
 * Get configuration for specific app
 */
function getAppConfig($app) {
    $brainEnvPath = '/home/claude/brain/.env';

    if ($app === 'brain-test') {
        return [
            'name' => 'Brain Test',
            'client_id' => getEnvVar('GOOGLE_BRAIN_TEST_CLIENT_ID'),
            'client_secret' => getEnvVar('GOOGLE_BRAIN_TEST_CLIENT_SECRET'),
            'token_var' => 'GOOGLE_BRAIN_TEST_REFRESH_TOKEN',
            'scopes' => [
                'https://www.googleapis.com/auth/gmail.modify',
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/calendar',
                'https://www.googleapis.com/auth/calendar.events',
                'https://www.googleapis.com/auth/drive',
                'https://www.googleapis.com/auth/drive.file',
                'https://www.googleapis.com/auth/analytics.edit',           // Extended!
                'https://www.googleapis.com/auth/analytics.readonly',
            'https://www.googleapis.com/auth/analytics.edit',
            'https://www.googleapis.com/auth/analytics.manage.users',
            'https://www.googleapis.com/auth/webmasters.readonly',
                'https://www.googleapis.com/auth/webmasters',
                'https://www.googleapis.com/auth/youtube.readonly',
                'https://www.googleapis.com/auth/userinfo.email',
                'https://www.googleapis.com/auth/userinfo.profile',
            ],
        ];
    }

    // Default: brain (GMAIL_* vars)
    return [
        'name' => 'Brain',
        'client_id' => getEnvVar('GMAIL_CLIENT_ID'),
        'client_secret' => getEnvVar('GMAIL_CLIENT_SECRET'),
        'token_var' => 'GMAIL_REFRESH_TOKEN',
        'scopes' => [
            'https://www.googleapis.com/auth/gmail.modify',
            'https://www.googleapis.com/auth/gmail.send',
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/drive',
            'https://www.googleapis.com/auth/drive.file',
            'https://www.googleapis.com/auth/analytics.readonly',
            'https://www.googleapis.com/auth/analytics.edit',
            'https://www.googleapis.com/auth/analytics.manage.users',
            'https://www.googleapis.com/auth/webmasters.readonly',
            'https://www.googleapis.com/auth/youtube.readonly',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ],
    ];
}

// Handle callback
if (strpos($_SERVER['REQUEST_URI'], '/callback') !== false) {
    handleCallback($client, $config, $app);
    exit;
}

// Start OAuth flow with state parameter containing app identifier
$state = $app . '-' . bin2hex(random_bytes(16));
// associa il return URL (già validato) allo state, sopravvive al round-trip Google
if (!empty($returnUrl)) {
    require_once __DIR__ . '/../lib/oauth_db.php';
    (new OAuthDB())->saveStateReturn($state, $returnUrl);
}
$client->setState($state);
$authUrl = $client->createAuthUrl();

logOAuthAttempt('google_redirect', [
    'app' => $app,
    'state' => $state,
    'redirect_uri' => $client->getRedirectUri(),
]);

header('Location: ' . $authUrl);
exit;


/**
 * Handle OAuth callback from Google
 */
function handleCallback($client, $config, $app) {
    if (isset($_GET['error'])) {
        logOAuthAttempt('google_error', [
            'app' => $app,
            'error' => $_GET['error'],
            'description' => $_GET['error_description'] ?? '',
        ]);
        showError($_GET['error_description'] ?? $_GET['error'], $config);
        return;
    }

    if (!isset($_GET['code'])) {
        logOAuthAttempt('google_no_code', ['app' => $app]);
        showError('No authorization code received', $config);
        return;
    }

    try {
        logOAuthAttempt('google_exchange_start', [
            'app' => $app,
            'client_id' => $config['client_id'],
            'has_secret' => !empty($config['client_secret']),
        ]);

        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

        if (isset($token['error'])) {
            logOAuthAttempt('google_token_error', [
                'app' => $app,
                'error' => $token['error'],
            ]);
            showError($token['error_description'] ?? $token['error'], $config);
            return;
        }

        $refreshToken = $token['refresh_token'] ?? null;

        logOAuthAttempt('google_token_received', [
            'app' => $app,
            'has_refresh_token' => !empty($refreshToken),
            'has_access_token' => !empty($token['access_token']),
            'token_keys' => array_keys($token),
        ]);

        // SECURITY: Get user info FIRST, before writing anything
        $userInfo = getUserInfo($token['access_token']);
        $email = $userInfo['email'] ?? null;

        // === Contract abchat <-> hub: token nel DB transiente + handoff monouso ===
        if ($email && (!empty($refreshToken) || !empty($token['access_token']))) {
            require_once __DIR__ . '/../lib/oauth_db.php';
            require_once __DIR__ . '/../lib/db.php';
            $odb = new OAuthDB();
            $exp = !empty($token['expires_in']) ? (time() + (int)$token['expires_in']) : null;
            $odb->saveTokens($email, 'google', $token['access_token'] ?? null, $refreshToken, $exp);
            $GLOBALS['HANDOFF_CODE'] = $odb->createHandoff($email, 'google');
            logOAuthAttempt('google_contract_stored', ['app' => $app, 'email' => $email]); // niente token nei log
            // 1) return URL dinamico (from=, già allowlistato in entrata, ri-validato qui)
            $ret = $odb->popStateReturn($_GET['state'] ?? '');
            if ($ret && return_host_allowed($ret)) {
                $sep = (strpos($ret, '?') !== false) ? '&' : '?';
                header('Location: ' . $ret . $sep . 'code=' . urlencode($GLOBALS['HANDOFF_CODE']));
                exit;
            }
            // 2) callback_url registrato nella tabella apps (fallback)
            if (function_exists('getCallbackUrl')) {
                $cb = getCallbackUrl($app);
                if ($cb) {
                    $sep = (strpos($cb, '?') !== false) ? '&' : '?';
                    header('Location: ' . $cb . $sep . 'code=' . urlencode($GLOBALS['HANDOFF_CODE']));
                    exit;
                }
            }
        }

        // Check if this email has an associated .env to update
        $envPath = getEnvPathForEmail($email);

        if ($envPath && $refreshToken) {
            // Authorized email: update .env
            $updated = [];
            updateEnvFile($envPath, $config['token_var'], $refreshToken);
            $updated[] = $config['token_var'];

            // Re-encrypt .env.gpg
            $encryptResult = reencryptEnv();

            logOAuthAttempt('google_success', [
                'app' => $app,
                'email' => $email,
                'token_var' => $config['token_var'],
                'env_path' => $envPath,
            ]);

            showSuccess($updated, $userInfo, $encryptResult, $config);
        } else {
            // Unknown email or no refresh token: show token on screen, don't touch .env
            logOAuthAttempt('google_token_display_only', [
                'app' => $app,
                'email' => $email,
                'reason' => !$envPath ? 'email_not_authorized' : 'no_refresh_token',
            ]);

            showTokenOnly($token, $userInfo, $config);
        }

    } catch (Exception $e) {
        logOAuthAttempt('google_exception', [
            'app' => $app,
            'message' => $e->getMessage(),
        ]);
        showError($e->getMessage(), $config);
    }
}


/**
 * Map authorized email → .env path
 * Only emails in this list can write to server .env files
 */
function getEnvPathForEmail($email) {
    $authorized = [
        'giobimail@gmail.com' => '/home/claude/brain/.env',
        'giobi@giobi.com'     => '/home/claude/brain/.env',
    ];

    return $authorized[$email] ?? null;
}


/**
 * Handle refresh-only request (use existing refresh token)
 */
function handleRefreshOnly($client, $envPath) {
    try {
        $refreshToken = getEnvVar('GMAIL_REFRESH_TOKEN');

        if (!$refreshToken) {
            showError('No refresh token in .env - full OAuth required');
            return;
        }

        $client->setAccessToken(['refresh_token' => $refreshToken]);
        $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

        if (isset($newToken['error'])) {
            showError("Refresh failed: {$newToken['error']}. Full OAuth required.");
            return;
        }

        updateEnvFile($envPath, 'GMAIL_ACCESS_TOKEN', $newToken['access_token']);
        $encryptResult = reencryptEnv();

        showSuccess(['GMAIL_ACCESS_TOKEN (refreshed)'], null, $encryptResult);

    } catch (Exception $e) {
        showError("Refresh failed: {$e->getMessage()}. Full OAuth required.");
    }
}


/**
 * Update single key in .env file
 */
function updateEnvFile($path, $key, $value) {
    logOAuthAttempt('env_update_start', [
        'key' => $key,
        'value_length' => strlen($value),
        'file_exists' => file_exists($path),
        'file_writable' => is_writable($path),
    ]);

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

    $bytes_written = file_put_contents($path, implode("\n", $lines));

    logOAuthAttempt('env_update_done', [
        'key' => $key,
        'updated_existing' => $updated,
        'bytes_written' => $bytes_written,
        'success' => $bytes_written !== false,
    ]);
}


/**
 * Re-encrypt .env to .env.gpg
 */
function reencryptEnv() {
    $cmd = "cd /home/claude/brain && /home/claude/brain/tools/security/encrypt-env.sh 2>&1";
    $output = shell_exec($cmd);
    return $output;
}


/**
 * Get user info from Google
 */
function getUserInfo($accessToken) {
    $url = "https://www.googleapis.com/oauth2/v2/userinfo";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken"]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}


/**
 * Get env var from brain/.env
 */
function getEnvVar($key) {
    $envPath = '/home/claude/brain/.env';
    if (!file_exists($envPath)) return null;

    $lines = file($envPath, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $line) {
        if (strpos($line, "$key=") === 0) {
            $value = substr($line, strlen($key) + 1);
            // Remove quotes if present
            return trim($value, " \t\n\r\0\x0B\"'");
        }
    }
    return null;
}


/**
 * Show success page
 */
function showSuccess($updated, $userInfo, $encryptResult, $config) {
    $email = $userInfo['email'] ?? 'Unknown';
    $appName = $config['name'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Google OAuth - Success</title>
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
        <h1>✅ OAuth Success!</h1>
        <?php if (!empty($GLOBALS['HANDOFF_CODE'])): ?>
        <p class="info">Handoff code (monouso, valido ~2 min) — l'app lo scambia per il token via back-channel:</p>
        <p><code><?= htmlspecialchars($GLOBALS['HANDOFF_CODE']) ?></code></p>
        <?php endif; ?>

        <div class="success-box">
            <strong>App:</strong> Google (<?= htmlspecialchars($appName) ?>)<br>
            <strong>Authorized as:</strong> <?= htmlspecialchars($email) ?>
        </div>

        <h3>Updated in brain/.env:</h3>
        <ul class="updated-keys">
            <?php foreach ($updated as $key): ?>
                <li><code><?= htmlspecialchars($key) ?></code></li>
            <?php endforeach; ?>
        </ul>

        <p class="info">
            <strong>Encryption:</strong>
            <?= $encryptResult ? 'Updated .env.gpg' : 'Skipped (script not found)' ?>
        </p>

        <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">

        <p>
            <a href="https://auth.giobi.com" style="color: #007bff;">← Back to OAuth Hub</a>
        </p>

        <p class="info">
            Token will be auto-refreshed every 45 minutes by brain cron.
            If it expires completely (7 days in Test mode), return here.
        </p>
    </div>
</body>
</html>
<?php
}


/**
 * Show token on screen (for non-authorized emails)
 * Token is displayed but NOT written to any .env
 */
function showTokenOnly($token, $userInfo, $config) {
    $email = $userInfo['email'] ?? 'Unknown';
    $appName = $config['name'];
    $refreshToken = $token['refresh_token'] ?? '(not provided)';
    $accessToken = $token['access_token'] ?? '(not provided)';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Google OAuth - Token</title>
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
        h1 { color: #856404; }
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .token-box {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            font-family: monospace;
            font-size: 12px;
            word-break: break-all;
            position: relative;
        }
        .token-label {
            font-weight: bold;
            color: #666;
            margin-top: 15px;
            font-size: 14px;
        }
        .info { color: #666; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>&#x1f511; OAuth Token</h1>

        <div class="warning-box">
            <strong>App:</strong> Google (<?= htmlspecialchars($appName) ?>)<br>
            <strong>Email:</strong> <?= htmlspecialchars($email) ?><br><br>
            This email is not associated with a server environment.<br>
            Token is shown here but <strong>not saved</strong> anywhere.
        </div>

        <p class="token-label">Refresh Token:</p>
        <div class="token-box"><?= htmlspecialchars($refreshToken) ?></div>

        <p class="token-label">Access Token:</p>
        <div class="token-box"><?= htmlspecialchars($accessToken) ?></div>

        <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">

        <p>
            <a href="https://auth.giobi.com" style="color: #007bff;">&#8592; Back to OAuth Hub</a>
        </p>
    </div>
</body>
</html>
<?php
}


/**
 * Show error page
 */
function showError($message, $config = null) {
    $appName = $config ? $config['name'] : 'Google';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($appName) ?> OAuth - Error</title>
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

        <a href="/google" class="btn">Try Again →</a>

        <p style="margin-top: 30px; color: #666;">
            <a href="https://auth.giobi.com" style="color: #007bff;">← Back to OAuth Hub</a>
        </p>
    </div>
</body>
</html>
<?php
}
