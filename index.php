<?php
/**
 * OAuth Hub - auth.giobi.com
 *
 * Multi-provider OAuth gateway for Giobi's applications.
 * Routes callbacks to provider-specific handlers.
 */

require_once __DIR__ . '/lib/env.php';
require_once __DIR__ . '/lib/router.php';

$router = new Router();

// Universal callback (for future providers)
$router->add('/callback', 'callback.php');

// Provider routes (legacy - keep for existing OAuth apps)
$router->add('/microsoft/callback', 'providers/microsoft.php');
$router->add('/microsoft', 'microsoft-select.php');
$router->add('/google/callback', 'providers/google.php');
$router->add('/google', 'providers/google.php');
$router->add('/google-login/callback', 'providers/google-login.php');
$router->add('/google-login', 'providers/google-login.php');
$router->add('/dropbox/callback', 'providers/dropbox.php');
$router->add('/dropbox', 'providers/dropbox.php');
$router->add('/canva/callback', 'providers/canva.php');
$router->add('/canva', 'providers/canva.php');

// Contract API (abchat <-> hub, JWT HS256)
$router->add('/api/token', 'api/token.php');

// Admin/status
$router->add('/admin', 'admin/index.php');
$router->add('/status', 'status.php');
$router->add('/privacy', 'privacy.php');

// Root: if OAuth callback params present, route to callback handler
$router->add('/', isset($_GET['code']) || isset($_GET['error']) ? 'callback.php' : 'hub.php');

// Default: show hub info
$router->setDefault('hub.php');

$router->dispatch($_SERVER['REQUEST_URI']);
