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

// Provider routes
$router->add('/microsoft/callback', 'providers/microsoft.php');
$router->add('/microsoft', 'providers/microsoft.php');
$router->add('/google/callback', 'providers/google.php');
$router->add('/google', 'providers/google.php');
$router->add('/google-login/callback', 'providers/google-login.php');
$router->add('/google-login', 'providers/google-login.php');

// Admin/status
$router->add('/admin', 'admin/index.php');
$router->add('/status', 'status.php');

// Default: show hub info
$router->setDefault('hub.php');

$router->dispatch($_SERVER['REQUEST_URI']);
