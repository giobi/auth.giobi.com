<?php
/**
 * auth.giobi.com Admin Dashboard
 * Manage apps, emails, magic links
 */

session_start();
require_once __DIR__ . '/../lib/db.php';

$pdo = get_db();

// Check if logged in
$current_user = $_SESSION['admin_user'] ?? null;
$is_logged_in = $current_user && is_email_admin($pdo, $current_user);

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin/');
    exit;
}

// Handle login redirect
if (!$is_logged_in && !isset($_GET['token'])) {
    // Redirect to Google OAuth
    $state = bin2hex(random_bytes(16));
    $_SESSION['admin_state'] = $state;
    header('Location: /google-login?app=auth.giobi.com&state=' . $state);
    exit;
}

// Handle OAuth callback (token from google-login)
if (isset($_GET['token']) && !$is_logged_in) {
    $token = $_GET['token'];
    $parts = explode('.', $token);

    if (count($parts) === 2) {
        $env = [];
        foreach (file('/home/claude/brain/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, '=') !== false && $line[0] !== '#') {
                list($k, $v) = explode('=', $line, 2);
                $env[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
            }
        }

        $secret = hash('sha256', $env['GOOGLE_INTERNAL_CLIENT_SECRET'] ?? '');
        $expected = hash_hmac('sha256', $parts[0], $secret);

        if (hash_equals($expected, $parts[1])) {
            $data = json_decode(base64_decode($parts[0]), true);
            if ($data && isset($data['email']) && is_email_admin($pdo, $data['email'])) {
                $_SESSION['admin_user'] = $data['email'];
                $_SESSION['admin_name'] = $data['name'] ?? $data['email'];
                $_SESSION['admin_picture'] = $data['picture'] ?? '';
                log_access($pdo, $data['email'], 'auth.giobi.com', 'admin_login');
                header('Location: /admin/');
                exit;
            }
        }
    }
    die('Access denied. You must be an admin.');
}

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_logged_in) {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'add_email':
                $stmt = $pdo->prepare("INSERT INTO allowed_emails (email, name, is_admin) VALUES (?, ?, ?)");
                $stmt->execute([
                    strtolower(trim($_POST['email'])),
                    trim($_POST['name']) ?: null,
                    isset($_POST['is_admin']) ? 1 : 0
                ]);
                $message = 'Email added successfully';
                break;

            case 'toggle_email':
                $stmt = $pdo->prepare("UPDATE allowed_emails SET is_active = NOT is_active WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $message = 'Email status toggled';
                break;

            case 'delete_email':
                $stmt = $pdo->prepare("DELETE FROM allowed_emails WHERE id = ? AND email != ?");
                $stmt->execute([$_POST['id'], $current_user]);
                $message = 'Email deleted';
                break;

            case 'add_app':
                $stmt = $pdo->prepare("INSERT INTO apps (name, callback_url) VALUES (?, ?)");
                $stmt->execute([
                    strtolower(trim($_POST['name'])),
                    trim($_POST['callback_url'])
                ]);
                $message = 'App added successfully';
                break;

            case 'toggle_app':
                $stmt = $pdo->prepare("UPDATE apps SET is_active = NOT is_active WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $message = 'App status toggled';
                break;

            case 'create_magic_link':
                $token = create_magic_link(
                    $pdo,
                    strtolower(trim($_POST['email'])),
                    $_POST['app'],
                    (int) $_POST['hours'],
                    $current_user
                );
                $message = 'Magic link created: https://auth.giobi.com/magic?token=' . $token;
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load data
$emails = $pdo->query("SELECT * FROM allowed_emails ORDER BY is_active DESC, email")->fetchAll();
$apps = $pdo->query("SELECT * FROM apps ORDER BY is_active DESC, name")->fetchAll();
$magic_links = $pdo->query("SELECT * FROM magic_links ORDER BY created_at DESC LIMIT 20")->fetchAll();
$recent_logs = $pdo->query("SELECT * FROM access_logs ORDER BY created_at DESC LIMIT 50")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auth Admin - auth.giobi.com</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: { brand: '#e07850' }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen">
    <nav class="bg-gray-800 border-b border-gray-700">
        <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <span class="text-2xl">🔐</span>
                <span class="font-bold text-lg">auth.giobi.com</span>
            </div>
            <div class="flex items-center gap-4">
                <?php if ($_SESSION['admin_picture'] ?? ''): ?>
                    <img src="<?= htmlspecialchars($_SESSION['admin_picture']) ?>" class="w-8 h-8 rounded-full">
                <?php endif; ?>
                <span class="text-sm text-gray-400"><?= htmlspecialchars($_SESSION['admin_name'] ?? '') ?></span>
                <a href="?logout=1" class="text-sm text-red-400 hover:text-red-300">Logout</a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 py-8">
        <?php if ($message): ?>
            <div class="mb-6 p-4 bg-green-900/50 border border-green-700 rounded-lg text-green-300">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-6 p-4 bg-red-900/50 border border-red-700 rounded-lg text-red-300">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Allowed Emails -->
            <section class="bg-gray-800 rounded-lg p-6">
                <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
                    <span>📧</span> Allowed Emails
                </h2>

                <form method="POST" class="mb-4 flex gap-2">
                    <input type="hidden" name="action" value="add_email">
                    <input type="email" name="email" placeholder="email@example.com" required
                           class="flex-1 px-3 py-2 bg-gray-700 rounded border border-gray-600 focus:border-brand focus:outline-none">
                    <input type="text" name="name" placeholder="Name (optional)"
                           class="w-32 px-3 py-2 bg-gray-700 rounded border border-gray-600 focus:border-brand focus:outline-none">
                    <label class="flex items-center gap-1 text-sm">
                        <input type="checkbox" name="is_admin" class="rounded">
                        Admin
                    </label>
                    <button type="submit" class="px-4 py-2 bg-brand rounded hover:bg-orange-600 transition">Add</button>
                </form>

                <div class="space-y-2 max-h-64 overflow-y-auto">
                    <?php foreach ($emails as $email): ?>
                        <div class="flex items-center justify-between p-2 bg-gray-700/50 rounded <?= $email['is_active'] ? '' : 'opacity-50' ?>">
                            <div>
                                <span class="font-mono text-sm"><?= htmlspecialchars($email['email']) ?></span>
                                <?php if ($email['name']): ?>
                                    <span class="text-gray-400 text-sm ml-2">(<?= htmlspecialchars($email['name']) ?>)</span>
                                <?php endif; ?>
                                <?php if ($email['is_admin']): ?>
                                    <span class="ml-2 px-2 py-0.5 bg-purple-900 text-purple-300 text-xs rounded">admin</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex gap-2">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle_email">
                                    <input type="hidden" name="id" value="<?= $email['id'] ?>">
                                    <button type="submit" class="text-sm px-2 py-1 bg-gray-600 rounded hover:bg-gray-500">
                                        <?= $email['is_active'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                                <?php if ($email['email'] !== $current_user): ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this email?')">
                                        <input type="hidden" name="action" value="delete_email">
                                        <input type="hidden" name="id" value="<?= $email['id'] ?>">
                                        <button type="submit" class="text-sm px-2 py-1 bg-red-900 rounded hover:bg-red-800">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Apps -->
            <section class="bg-gray-800 rounded-lg p-6">
                <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
                    <span>🚀</span> Registered Apps
                </h2>

                <form method="POST" class="mb-4 flex gap-2">
                    <input type="hidden" name="action" value="add_app">
                    <input type="text" name="name" placeholder="app.domain.com" required
                           class="flex-1 px-3 py-2 bg-gray-700 rounded border border-gray-600 focus:border-brand focus:outline-none">
                    <input type="url" name="callback_url" placeholder="https://app.domain.com/auth/callback" required
                           class="flex-1 px-3 py-2 bg-gray-700 rounded border border-gray-600 focus:border-brand focus:outline-none">
                    <button type="submit" class="px-4 py-2 bg-brand rounded hover:bg-orange-600 transition">Add</button>
                </form>

                <div class="space-y-2 max-h-64 overflow-y-auto">
                    <?php foreach ($apps as $app): ?>
                        <div class="flex items-center justify-between p-2 bg-gray-700/50 rounded <?= $app['is_active'] ? '' : 'opacity-50' ?>">
                            <div>
                                <span class="font-bold"><?= htmlspecialchars($app['name']) ?></span>
                                <span class="text-gray-400 text-xs block"><?= htmlspecialchars($app['callback_url']) ?></span>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="toggle_app">
                                <input type="hidden" name="id" value="<?= $app['id'] ?>">
                                <button type="submit" class="text-sm px-2 py-1 bg-gray-600 rounded hover:bg-gray-500">
                                    <?= $app['is_active'] ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Magic Links -->
            <section class="bg-gray-800 rounded-lg p-6">
                <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
                    <span>🔗</span> Magic Links
                </h2>

                <form method="POST" class="mb-4 space-y-2">
                    <input type="hidden" name="action" value="create_magic_link">
                    <div class="flex gap-2">
                        <input type="email" name="email" placeholder="guest@example.com" required
                               class="flex-1 px-3 py-2 bg-gray-700 rounded border border-gray-600 focus:border-brand focus:outline-none">
                        <select name="app" required class="px-3 py-2 bg-gray-700 rounded border border-gray-600 focus:border-brand focus:outline-none">
                            <?php foreach ($apps as $app): ?>
                                <?php if ($app['is_active']): ?>
                                    <option value="<?= htmlspecialchars($app['name']) ?>"><?= htmlspecialchars($app['name']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <select name="hours" class="px-3 py-2 bg-gray-700 rounded border border-gray-600">
                            <option value="1">1 hour</option>
                            <option value="24" selected>24 hours</option>
                            <option value="168">7 days</option>
                            <option value="720">30 days</option>
                        </select>
                        <button type="submit" class="px-4 py-2 bg-brand rounded hover:bg-orange-600 transition">Generate</button>
                    </div>
                </form>

                <div class="space-y-2 max-h-48 overflow-y-auto text-sm">
                    <?php foreach ($magic_links as $link): ?>
                        <?php
                        $expired = strtotime($link['expires_at']) < time();
                        $used = !empty($link['used_at']);
                        ?>
                        <div class="p-2 bg-gray-700/50 rounded <?= ($expired || $used) ? 'opacity-50' : '' ?>">
                            <div class="flex justify-between">
                                <span class="font-mono"><?= htmlspecialchars($link['email']) ?></span>
                                <span class="text-gray-400"><?= htmlspecialchars($link['app_name']) ?></span>
                            </div>
                            <div class="flex justify-between text-xs text-gray-500">
                                <span>Expires: <?= $link['expires_at'] ?></span>
                                <?php if ($used): ?>
                                    <span class="text-green-400">Used: <?= $link['used_at'] ?></span>
                                <?php elseif ($expired): ?>
                                    <span class="text-red-400">Expired</span>
                                <?php else: ?>
                                    <button onclick="navigator.clipboard.writeText('https://auth.giobi.com/magic?token=<?= $link['token'] ?>')" class="text-brand hover:underline">Copy link</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Recent Access Logs -->
            <section class="bg-gray-800 rounded-lg p-6">
                <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
                    <span>📊</span> Recent Access
                </h2>

                <div class="space-y-1 max-h-64 overflow-y-auto text-sm font-mono">
                    <?php foreach ($recent_logs as $log): ?>
                        <div class="p-2 bg-gray-700/30 rounded flex justify-between">
                            <span>
                                <span class="text-brand"><?= htmlspecialchars($log['email']) ?></span>
                                <span class="text-gray-500">→</span>
                                <span><?= htmlspecialchars($log['app_name']) ?></span>
                                <span class="text-gray-600 text-xs ml-2">[<?= htmlspecialchars($log['method']) ?>]</span>
                            </span>
                            <span class="text-gray-500 text-xs"><?= $log['created_at'] ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($recent_logs)): ?>
                        <p class="text-gray-500 text-center py-4">No access logs yet</p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
