<?php
/**
 * GET /api/token?provider=<p>&email=<e>
 * Contract abchat <-> auth.giobi.com.
 * Auth: Authorization: Bearer <JWT HS256 firmato con ABCHAT_AUTH_HUB_SECRET>.
 * Ritorna il token GIA salvato dal hub (oauth_tokens.sqlite). NON parla con Google.
 * Niente token nei log.
 */

header('Content-Type: application/json');

function _b64url_decode($d) {
    return base64_decode(strtr($d, '-_', '+/') . str_repeat('=', (4 - strlen($d) % 4) % 4));
}
function _fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    error_log("[api/token] DENY $code $msg ip=" . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    exit;
}

$secret = env('ABCHAT_AUTH_HUB_SECRET');
if (!$secret) { _fail(500, 'hub auth not configured'); }

$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (stripos($hdr, 'Bearer ') !== 0) { _fail(401, 'missing bearer'); }
$jwt = trim(substr($hdr, 7));
$parts = explode('.', $jwt);
if (count($parts) !== 3) { _fail(401, 'malformed token'); }
list($h, $p, $sig) = $parts;

$expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", $secret, true)), '+/', '-_'), '=');
if (!hash_equals($expected, $sig)) { _fail(401, 'bad signature'); }

$claims = json_decode(_b64url_decode($p), true);
if (!is_array($claims)) { _fail(401, 'bad claims'); }
if (($claims['iss'] ?? '') !== 'abchat') { _fail(401, 'bad iss'); }
if (($claims['aud'] ?? '') !== 'auth.giobi.com') { _fail(401, 'bad aud'); }
if (!isset($claims['exp']) || time() >= (int)$claims['exp']) { _fail(401, 'expired'); }
if (isset($claims['iat']) && (int)$claims['iat'] > time() + 60) { _fail(401, 'bad iat'); }

require_once __DIR__ . '/../lib/oauth_db.php';
$db = new OAuthDB();

$code = trim($_GET['code'] ?? '');
if ($code !== '') {
    // path preferito: handoff code monouso (consumato qui)
    $h = $db->redeemHandoff($code);
    if (!$h) { _fail(404, 'invalid or expired code'); }
    $email = $h['email'];
    $provider = $h['provider'];
} else {
    // back-compat: lookup diretto per email+provider
    $provider = preg_replace('/[^a-z0-9_-]/i', '', $_GET['provider'] ?? '');
    $email = trim($_GET['email'] ?? '');
    if ($provider === '' || $email === '') { _fail(400, 'provider+email or code required'); }
}
$tok = $db->getTokens($email, $provider);
if (!$tok || empty($tok['refresh_token'])) {
    _fail(404, 'no stored token — user must authenticate at auth.giobi.com');
}

error_log("[api/token] OK email=$email provider=$provider ip=" . ($_SERVER['REMOTE_ADDR'] ?? '?'));
echo json_encode([
    'provider'      => $provider,
    'email'         => $email,
    'access_token'  => $tok['access_token'] ?? null,
    'refresh_token' => $tok['refresh_token'] ?? null,
    'expires_at'    => isset($tok['expires_at']) ? (int)$tok['expires_at'] : null,
]);
