<?php
declare(strict_types=1);

/*
LiteInsurance — single-file PHP + SQLite (PDO) app for shared hosting

Deploy:
1) Upload `liteinsurance.php` into `public_html/`.
2) Ensure PHP 8+ with PDO + SQLite enabled.
3) Ensure `public_html/` is writable so `liteinsurance.db` can be created.
4) Visit `/liteinsurance.php` and register the first user (becomes Admin).
5) Import → Download sample CSV, then Import → Upload to test.
6) Optional: create writable folder `liteinsurance_uploads/` (CSV staging).
7) cPanel cron (recommended): `php /home/USER/public_html/liteinsurance.php action=cron_jobs cron_token=YOUR_TOKEN`.
8) Back up `liteinsurance.db` periodically.
*/

/*
Customize here
*/
const APP_NAME = 'LiteInsurance';
const DB_PATH = __DIR__ . DIRECTORY_SEPARATOR . 'liteinsurance.db';
const UPLOAD_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'liteinsurance_uploads';
const ACCENT_COLOR = '#2540ff';
const CURRENCY_SYMBOL = '$';
const SESSION_TIMEOUT_SECONDS = 30 * 60;
const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
const IMPORT_COMMIT_EVERY_ROWS = 300;
const IMPORT_PREVIEW_ROWS = 20;
const EXPECTED_POLICY_TERM_MONTHS_DEFAULT = 120;
const RETENTION_ADJUSTMENT_DEFAULT = 0.85;
const DEFAULT_CROSS_SELL_MULTIPLIER = 1.05;
const CROSS_SELL_MULTIPLIER_BY_POLICY_TYPE = [
    'term' => 1.06,
    'health' => 1.10,
    'auto' => 1.04,
    'life' => 1.08,
];
const CRON_TOKEN = '';
const DEBUG = false;
const ERROR_LOG_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'liteinsurance_error.log';
const LOGIN_RATE_LIMIT_MAX = 8;
const LOGIN_RATE_LIMIT_WINDOW_SECONDS = 15 * 60;
const REGISTER_RATE_LIMIT_MAX = 4;
const REGISTER_RATE_LIMIT_WINDOW_SECONDS = 60 * 60;
const IMPORT_RATE_LIMIT_MAX = 6;
const IMPORT_RATE_LIMIT_WINDOW_SECONDS = 10 * 60;
const SAMPLE_POLICY_COUNT = 200;
const SAMPLE_RANDOM_SEED = 71371;

ini_set('display_errors', '0');
error_reporting(E_ALL);

$GLOBALS['LI_CFG'] = load_env_file(__DIR__ . DIRECTORY_SEPARATOR . '.env');
$GLOBALS['CSP_NONCE'] = base64_encode(random_bytes(16));

set_exception_handler(static function (Throwable $e): void {
    li_log('php.exception', [
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Server error.\n");
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Server error.';
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    li_log('php.error', ['severity' => $severity, 'message' => $message, 'file' => $file, 'line' => $line]);
    return true;
});

register_shutdown_function(static function (): void {
    $e = error_get_last();
    if (!$e) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (in_array((int)($e['type'] ?? 0), $fatal, true)) {
        li_log('php.fatal', $e);
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code(500);
            echo 'Server error.';
        }
    }
});

if (PHP_SAPI !== 'cli') {
    send_security_headers((string)$GLOBALS['CSP_NONCE']);
}

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));
if ($action === '' && PHP_SAPI === 'cli') {
    $map = cli_kv($argv ?? []);
    if (isset($map['action'])) {
        $action = (string)$map['action'];
        $_GET = array_merge($_GET, $map);
    }
}

if ($action === 'download_sample_csv') {
    download_text('liteinsurance_sample.csv', sample_csv(), 'text/csv; charset=utf-8');
    exit;
}

session_init();
try {
    $pdo = db();
    migrate($pdo);
} catch (Throwable $e) {
    li_log('setup.boot_error', [
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'db_path' => db_path(),
        'upload_dir' => upload_dir(),
    ]);
    render_boot_setup_error($e);
    exit;
}

if ($action === 'cron_jobs') {
    run_cron_jobs($pdo);
    exit;
}

route_web($pdo);

function session_init(): void
{
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    $p = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $p['path'] ?? '/',
        'domain' => $p['domain'] ?? '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $now = time();
    $last = (int)($_SESSION['__last_activity'] ?? $now);
    if (($now - $last) > SESSION_TIMEOUT_SECONDS) {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['flash'] = ['type' => 'info', 'msg' => 'Session expired. Please sign in again.'];
        if ($uid > 0) {
            $_SESSION['__expired_user_id'] = $uid;
        }
    }
    $_SESSION['__last_activity'] = $now;
}

function db(): PDO
{
    if (!extension_loaded('pdo') || !extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('PDO SQLite is not enabled for this PHP runtime.');
    }
    $path = db_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        throw new RuntimeException('SQLite database directory does not exist: ' . $dir);
    }
    if ((is_file($path) && !is_writable($path)) || (!is_file($path) && !is_writable($dir))) {
        throw new RuntimeException('SQLite database path is not writable: ' . $path);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA synchronous=NORMAL;');
    $pdo->exec('PRAGMA temp_store=MEMORY;');
    $pdo->exec('PRAGMA busy_timeout=3000;');
    $pdo->exec('PRAGMA foreign_keys=OFF;');
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (\n"
        . "id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
        . "email TEXT UNIQUE NOT NULL,\n"
        . "password_hash TEXT NOT NULL,\n"
        . "role TEXT NOT NULL DEFAULT 'user',\n"
        . "created_at TEXT NOT NULL\n"
        . ")");

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (\n"
        . "id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
        . "user_id INTEGER,\n"
        . "action TEXT NOT NULL,\n"
        . "payload TEXT NOT NULL,\n"
        . "ts TEXT NOT NULL\n"
        . ")");

    $pdo->exec("CREATE TABLE IF NOT EXISTS policyholders (\n"
        . "id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
        . "policy_number TEXT NOT NULL,\n"
        . "name TEXT NOT NULL,\n"
        . "dob TEXT,\n"
        . "age INTEGER,\n"
        . "gender TEXT,\n"
        . "policy_type TEXT,\n"
        . "region TEXT,\n"
        . "premium_amount REAL,\n"
        . "tenure_months INTEGER,\n"
        . "sum_assured REAL,\n"
        . "ltv_estimate REAL,\n"
        . "risk_tier TEXT,\n"
        . "is_imputed INTEGER NOT NULL DEFAULT 0,\n"
        . "confidence_score INTEGER NOT NULL DEFAULT 0,\n"
        . "metadata TEXT NOT NULL DEFAULT '{}',\n"
        . "created_at TEXT NOT NULL\n"
        . ")");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rules (\n"
        . "id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
        . "name TEXT NOT NULL,\n"
        . "conditions_json TEXT NOT NULL,\n"
        . "actions_json TEXT NOT NULL,\n"
        . "priority INTEGER NOT NULL DEFAULT 100,\n"
        . "enabled INTEGER NOT NULL DEFAULT 1,\n"
        . "created_at TEXT NOT NULL\n"
        . ")");

    $pdo->exec("CREATE TABLE IF NOT EXISTS segments (\n"
        . "id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
        . "name TEXT NOT NULL,\n"
        . "rule_ids TEXT NOT NULL,\n"
        . "last_run_at TEXT\n"
        . ")");

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaigns (\n"
        . "id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
        . "name TEXT NOT NULL,\n"
        . "segment_id INTEGER NOT NULL,\n"
        . "assumptions TEXT NOT NULL,\n"
        . "projected_revenue REAL NOT NULL DEFAULT 0,\n"
        . "created_at TEXT NOT NULL\n"
        . ")");

    $pdo->exec("CREATE TABLE IF NOT EXISTS imports (\n"
        . "id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
        . "filename TEXT NOT NULL,\n"
        . "rows_total INTEGER NOT NULL DEFAULT 0,\n"
        . "rows_imported INTEGER NOT NULL DEFAULT 0,\n"
        . "errors_json TEXT NOT NULL DEFAULT '[]',\n"
        . "created_at TEXT NOT NULL\n"
        . ")");

    $pdo->exec("CREATE TABLE IF NOT EXISTS custom_field_defs (\n"
        . "id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
        . "field_key TEXT UNIQUE NOT NULL,\n"
        . "label TEXT NOT NULL,\n"
        . "source_header TEXT NOT NULL,\n"
        . "field_type TEXT NOT NULL DEFAULT 'text',\n"
        . "enabled INTEGER NOT NULL DEFAULT 1,\n"
        . "created_at TEXT NOT NULL,\n"
        . "updated_at TEXT NOT NULL\n"
        . ")");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (\n"
        . "bucket TEXT PRIMARY KEY,\n"
        . "hits INTEGER NOT NULL,\n"
        . "reset_at INTEGER NOT NULL\n"
        . ")");

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_policyholders_policy_number ON policyholders(policy_number)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_policyholders_risk_tier ON policyholders(risk_tier)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_policyholders_region ON policyholders(region)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_ts ON audit_log(ts)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_custom_field_defs_enabled ON custom_field_defs(enabled, field_key)');
}

function route_web(PDO $pdo): void
{
    $action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));
    $tab = (string)($_GET['tab'] ?? 'dashboard');
    $hasUsers = ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0);

    if (!$hasUsers && $action !== 'register' && $action !== 'register_form') {
        redirect_to(['action' => 'register_form']);
    }

    if ($action === 'logout') {
        require_csrf();
        $uid = (int)($_SESSION['user_id'] ?? 0);
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Signed out.'];
        if ($uid > 0) {
            audit($pdo, $uid, 'auth.logout', ['ip' => client_ip()]);
        }
        redirect_self();
    }

    $user = current_user($pdo);
    if (!$user) {
        if ($action === 'register') {
            handle_register($pdo);
            return;
        }
        if ($action === 'login') {
            handle_login($pdo);
            return;
        }
        $mode = ($action === 'register_form') ? 'register' : 'login';
        render_auth_page($mode, $hasUsers);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
    }

    if ($action === 'load_sample') {
        require_admin($user);
        handle_load_sample($pdo, $user);
        return;
    }
    if ($action === 'import_upload') {
        require_admin($user);
        handle_import_upload($pdo, $user);
        return;
    }
    if ($action === 'import_preview') {
        require_admin($user);
        handle_import_preview($pdo, $user);
        return;
    }
    if ($action === 'import_commit') {
        require_admin($user);
        handle_import_commit($pdo, $user);
        return;
    }

    if ($action === 'export_rules') {
        require_admin($user);
        export_rules_json($pdo);
        return;
    }
    if ($action === 'rule_test') {
        require_admin($user);
        json_rule_test($pdo);
        return;
    }
    if ($action === 'rule_save') {
        require_admin($user);
        handle_rule_save($pdo, $user);
        return;
    }
    if ($action === 'rule_delete') {
        require_admin($user);
        handle_rule_delete($pdo, $user);
        return;
    }
    if ($action === 'rule_toggle') {
        require_admin($user);
        handle_rule_toggle($pdo, $user);
        return;
    }

    if ($action === 'segment_save') {
        require_admin($user);
        handle_segment_save($pdo, $user);
        return;
    }
    if ($action === 'segment_delete') {
        require_admin($user);
        handle_segment_delete($pdo, $user);
        return;
    }
    if ($action === 'segment_estimate') {
        require_admin($user);
        handle_segment_estimate($pdo, $user);
        return;
    }
    if ($action === 'export_segment_prepare') {
        require_admin($user);
        handle_export_segment_prepare();
        return;
    }
    if ($action === 'export_segment') {
        require_admin($user);
        export_segment_csv($pdo);
        return;
    }

    if ($action === 'campaign_save') {
        require_admin($user);
        handle_campaign_save($pdo, $user);
        return;
    }
    if ($action === 'repair_apply') {
        require_admin($user);
        handle_repair_apply($pdo, $user);
        return;
    }
    if ($action === 'policy_cell_update') {
        require_admin($user);
        handle_policy_cell_update($pdo, $user);
        return;
    }

    if ($action === 'recompute_now') {
        require_admin($user);
        handle_recompute_now($pdo, $user);
        return;
    }

    render_app($pdo, $user, $tab);
}

function render_auth_page(string $mode, bool $hasUsers): void
{
    $isRegister = $mode === 'register';
    $title = $isRegister ? 'Create account' : 'Sign in';
    $subtitle = $isRegister ? ($hasUsers ? 'Create a new user.' : 'Create the first user (Admin).') : 'Sign in to access the dashboard.';
    $flash = consume_flash();
    $csrf = csrf_token();

    echo '<!doctype html><html lang="en"><head>';
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h(APP_NAME . ' — ' . $title) . '</title>';
    echo inter_font_css() . app_css();
    echo '</head><body>';
    echo '<main class="authWrap"><div class="authCard">';
    echo '<div class="authBrand">' . h(APP_NAME) . '</div>';
    echo '<h1 class="h1">' . h($title) . '</h1>';
    echo '<div class="muted">' . h($subtitle) . '</div>';
    if ($flash) {
        echo render_flash($flash);
    }
    echo '<form method="post" class="form" autocomplete="on">';
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">';
    echo '<input type="hidden" name="action" value="' . h($isRegister ? 'register' : 'login') . '">';
    echo '<label class="label">Email</label><input class="input" type="email" name="email" required maxlength="190">';
    echo '<label class="label">Password</label><input class="input" type="password" name="password" required minlength="8" maxlength="200">';
    if ($isRegister) {
        echo '<label class="label">Confirm password</label><input class="input" type="password" name="password2" required minlength="8" maxlength="200">';
    }
    echo '<button class="btn" type="submit">' . h($isRegister ? 'Create account' : 'Sign in') . '</button>';
    echo '</form>';
    echo '<div class="authFooter">';
    if ($isRegister) {
        echo '<a class="link" href="' . h(self_url(['action' => 'login_form'])) . '">Have an account? Sign in</a>';
    } else {
        echo '<a class="link" href="' . h(self_url(['action' => 'register_form'])) . '">Create an account</a>';
    }
    echo '<div class="tiny muted">Session secured, CSRF protected.</div>';
    echo '<div class="tiny muted"><a class="link" href="SETUP.md" target="_blank" rel="noopener">Docs</a> · <a class="link" href="SECURITY.md" target="_blank" rel="noopener">Security</a></div>';
    echo '</div>';
    echo '</div></main>';
    echo app_js();
    echo '</body></html>';
}

function handle_register(PDO $pdo): void
{
    require_csrf();
    if (!rate_limit_allow($pdo, 'auth.register', client_ip(), REGISTER_RATE_LIMIT_MAX, REGISTER_RATE_LIMIT_WINDOW_SECONDS)) {
        http_response_code(429);
        flash('error', 'Too many registration attempts. Try again later.');
        render_auth_page('register', true);
        return;
    }
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Enter a valid email.');
        redirect_to(['action' => 'register_form']);
    }
    if (strlen($password) < 8) {
        flash('error', 'Password must be at least 8 characters.');
        redirect_to(['action' => 'register_form']);
    }
    if (!hash_equals($password, $password2)) {
        flash('error', 'Passwords do not match.');
        redirect_to(['action' => 'register_form']);
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
    $st->execute([$email]);
    if ((int)$st->fetchColumn() > 0) {
        flash('error', 'An account with that email already exists.');
        redirect_to(['action' => 'register_form']);
    }

    $isFirst = ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0);
    $role = $isFirst ? 'admin' : 'user';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins = $pdo->prepare('INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, ?, ?)');
    $ins->execute([$email, $hash, $role, now_iso()]);
    $uid = (int)$pdo->lastInsertId();

    $_SESSION['user_id'] = $uid;
    session_regenerate_id(true);
    audit($pdo, $uid, 'auth.register', ['email' => $email, 'role' => $role, 'ip' => client_ip()]);
    flash('success', $isFirst ? 'Admin account created.' : 'Account created.');
    redirect_to(['tab' => 'dashboard']);
}

function handle_login(PDO $pdo): void
{
    require_csrf();
    if (!rate_limit_allow($pdo, 'auth.login', client_ip(), LOGIN_RATE_LIMIT_MAX, LOGIN_RATE_LIMIT_WINDOW_SECONDS)) {
        http_response_code(429);
        flash('error', 'Too many sign-in attempts. Try again later.');
        render_auth_page('login', true);
        return;
    }
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($email === '' || $password === '') {
        flash('error', 'Email and password are required.');
        redirect_self();
    }
    $st = $pdo->prepare('SELECT id, email, password_hash, role FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, (string)$u['password_hash'])) {
        audit($pdo, 0, 'auth.login_failed', ['email' => $email, 'ip' => client_ip()]);
        flash('error', 'Invalid credentials.');
        redirect_self();
    }
    $_SESSION['user_id'] = (int)$u['id'];
    session_regenerate_id(true);
    audit($pdo, (int)$u['id'], 'auth.login', ['email' => $email, 'role' => (string)$u['role'], 'ip' => client_ip()]);
    flash('success', 'Signed in.');
    redirect_to(['tab' => 'dashboard']);
}

function current_user(PDO $pdo): ?array
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT id, email, role, created_at FROM users WHERE id = ?');
    $st->execute([$uid]);
    $u = $st->fetch();
    return $u ?: null;
}

function require_login(): void
{
    if ((int)($_SESSION['user_id'] ?? 0) <= 0) {
        redirect_to(['action' => 'login_form']);
    }
}

function require_admin(array $user): void
{
    if ((string)($user['role'] ?? 'user') !== 'admin') {
        flash('error', 'Admin access required.');
        redirect_to(['tab' => 'dashboard']);
    }
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 16) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['csrf_token'];
}

function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $token = (string)($_POST['csrf_token'] ?? '');
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
        csrf_fail();
    }
}

function render_app(PDO $pdo, array $user, string $tab): void
{
    $flash = consume_flash();
    $tab = $tab !== '' ? $tab : 'dashboard';

    echo '<!doctype html><html lang="en"><head>';
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h(APP_NAME . ' — ' . ucfirst($tab)) . '</title>';
    echo inter_font_css() . app_css();
    echo '</head><body>';
    echo '<div class="appShell">';

    echo '<header class="topBar">';
    echo '<div class="brand">' . h(APP_NAME) . '</div>';
    echo '<div class="topRight">';
    echo '<div class="userPill">' . h((string)$user['email']) . '<span class="pillRole">' . h((string)$user['role']) . '</span></div>';
    echo '<form method="post" class="inline"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="logout"><button class="btnSecondary" type="submit">Logout</button></form>';
    echo '</div>';
    echo '</header>';

    echo '<aside class="sideNav">';
    echo nav_item('Dashboard', 'dashboard', $tab);
    echo nav_item('Import', 'import', $tab);
    echo nav_item('Profiles', 'profiles', $tab);
    echo nav_item('Rules', 'rules', $tab);
    echo nav_item('Segments', 'segments', $tab);
    echo nav_item('Campaigns', 'campaigns', $tab);
    echo nav_item('Settings', 'settings', $tab);
    echo '</aside>';

    echo '<main class="main">';
    if ($flash) {
        echo render_flash($flash);
    }
    echo '<div class="pageHead"><h1 class="h1">' . h(ucfirst($tab)) . '</h1><div class="muted">Manage imports, rules, segments, and campaigns.</div></div>';

    if ((string)$user['role'] !== 'admin' && in_array($tab, ['import', 'rules', 'segments', 'campaigns', 'settings'], true)) {
        echo '<div class="card"><div class="h2">Admin required</div><div class="muted">This tab is available to admins only.</div></div>';
    } else {
        if ($tab === 'dashboard') {
            render_tab_dashboard($pdo);
        } elseif ($tab === 'import') {
            render_tab_import($pdo);
        } elseif ($tab === 'profiles') {
            render_tab_profiles($pdo);
        } elseif ($tab === 'rules') {
            render_tab_rules($pdo);
        } elseif ($tab === 'segments') {
            render_tab_segments($pdo);
        } elseif ($tab === 'campaigns') {
            render_tab_campaigns($pdo);
        } elseif ($tab === 'settings') {
            render_tab_settings($pdo);
        } else {
            echo '<div class="card"><div class="muted">Unknown tab.</div></div>';
        }
    }
    echo '</main>';
    echo '<footer class="footer"><a class="link" href="SETUP.md" target="_blank" rel="noopener">Docs</a> · <a class="link" href="SECURITY.md" target="_blank" rel="noopener">Security</a></footer>';
    echo '</div>';
    echo app_js();
    echo '</body></html>';
}

function render_tab_dashboard(PDO $pdo): void
{
    $total = (int)$pdo->query('SELECT COUNT(*) FROM policyholders')->fetchColumn();
    $segments = (int)$pdo->query('SELECT COUNT(*) FROM segments')->fetchColumn();
    $avgLtvRaw = $pdo->query('SELECT AVG(ltv_estimate) FROM policyholders')->fetchColumn();
    $highRisk = (int)$pdo->query("SELECT COUNT(*) FROM policyholders WHERE risk_tier = 'High'")->fetchColumn();

    echo '<div class="kpiGrid">';
    echo kpi('Total policyholders', (string)$total);
    echo kpi('Segments', (string)$segments);
    echo kpi('Avg LTV', money_or_blank($avgLtvRaw === null ? null : (float)$avgLtvRaw));
    echo kpi('High-risk', (string)$highRisk);
    echo '</div>';

    echo '<div class="grid2">';
    echo '<div class="card"><div class="h2">Risk tier distribution</div>';
    $dist = $pdo->query("SELECT COALESCE(risk_tier,'Unknown') AS tier, COUNT(*) AS c FROM policyholders GROUP BY tier ORDER BY c DESC")->fetchAll();
    echo '<table class="table"><thead><tr><th>Tier</th><th class="right">Count</th></tr></thead><tbody>';
    foreach ($dist as $r) {
        echo '<tr><td>' . h((string)$r['tier']) . '</td><td class="right">' . h((string)$r['c']) . '</td></tr>';
    }
    if (!$dist) {
        echo '<tr><td colspan="2" class="muted">No data yet.</td></tr>';
    }
    echo '</tbody></table></div>';

    echo '<div class="card"><div class="h2">Top LTV</div>';
    $top = $pdo->query('SELECT policy_number, name, region, ltv_estimate, risk_tier, confidence_score FROM policyholders ORDER BY ltv_estimate DESC LIMIT 8')->fetchAll();
    echo '<table class="table"><thead><tr><th>Policy</th><th>Name</th><th>Region</th><th class="right">LTV</th><th>Tier</th><th class="right">Conf</th></tr></thead><tbody>';
    foreach ($top as $r) {
        echo '<tr><td>' . h((string)$r['policy_number']) . '</td><td>' . h(mask_name((string)$r['name'])) . '</td><td>' . h((string)($r['region'] ?? '')) . '</td><td class="right">' . h(money_or_blank($r['ltv_estimate'] ?? null)) . '</td><td>' . h((string)($r['risk_tier'] ?? 'Unknown')) . '</td><td class="right">' . h((string)($r['confidence_score'] ?? 0)) . '</td></tr>';
    }
    if (!$top) {
        echo '<tr><td colspan="6" class="muted">No data yet.</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '</div>';

    echo '<div class="grid2">';
    echo '<div class="card"><div class="h2">Recent imports</div>';
    $imports = $pdo->query('SELECT filename, rows_total, rows_imported, created_at FROM imports ORDER BY created_at DESC LIMIT 6')->fetchAll();
    echo '<table class="table"><thead><tr><th>When</th><th>File</th><th class="right">Rows</th><th class="right">Imported</th></tr></thead><tbody>';
    foreach ($imports as $r) {
        echo '<tr><td>' . h((string)$r['created_at']) . '</td><td>' . h((string)$r['filename']) . '</td><td class="right">' . h((string)$r['rows_total']) . '</td><td class="right">' . h((string)$r['rows_imported']) . '</td></tr>';
    }
    if (!$imports) {
        echo '<tr><td colspan="4" class="muted">No imports yet.</td></tr>';
    }
    echo '</tbody></table></div>';

    echo '<div class="card"><div class="h2">Audit log</div>';
    $audit = $pdo->query('SELECT a.ts, a.action, u.email AS user_email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.ts DESC LIMIT 10')->fetchAll();
    echo '<table class="table"><thead><tr><th>Time</th><th>Action</th><th>User</th></tr></thead><tbody>';
    foreach ($audit as $r) {
        echo '<tr><td>' . h((string)$r['ts']) . '</td><td>' . h((string)$r['action']) . '</td><td>' . h((string)($r['user_email'] ?? 'system')) . '</td></tr>';
    }
    if (!$audit) {
        echo '<tr><td colspan="3" class="muted">No events yet.</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '</div>';
}

function render_tab_import(PDO $pdo): void
{
    $stage = $_SESSION['import_stage'] ?? null;
    echo '<div class="actionsRow">';
    echo '<a class="btnSecondary" href="' . h(self_url(['action' => 'download_sample_csv'])) . '">Download sample CSV</a>';
    echo '<form method="post" class="inline"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="load_sample"><button class="btnSecondary" type="submit">Load sample rules + data</button></form>';
    echo '</div>';

    echo '<div class="grid2">';
    echo '<div class="card"><div class="h2">Upload CSV</div>';
    echo '<div class="muted">Max upload: ' . h((string)round(MAX_UPLOAD_BYTES / 1024 / 1024, 1)) . ' MB. Staged in <code>' . h(basename(upload_dir())) . '/</code>.</div>';
    echo '<form method="post" enctype="multipart/form-data" class="form">';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="import_upload">';
    echo '<input class="input" type="file" name="csv" accept=".csv,text/csv" required>';
    echo '<button class="btn" type="submit">Upload</button>';
    echo '</form></div>';
    echo '<div class="card"><div class="h2">Missing data strategy</div><ul class="list"><li>Mapped numeric fields: mean imputation</li><li>Mapped categorical fields: mode imputation</li><li>Unmapped CSV columns: preserved in <code>metadata.custom_fields</code></li><li>Scoring requires mapped premium, tenure, and policy type; otherwise LTV stays blank and risk remains Unknown.</li></ul></div>';
    echo '</div>';

    if (is_array($stage) && isset($stage['headers'], $stage['path'])) {
        render_import_mapping($pdo, $stage);
    }

    $imports = $pdo->query('SELECT filename, rows_total, rows_imported, created_at, errors_json FROM imports ORDER BY created_at DESC LIMIT 12')->fetchAll();
    echo '<div class="card"><div class="h2">Import history</div>';
    echo '<table class="table"><thead><tr><th>When</th><th>Filename</th><th class="right">Rows</th><th class="right">Imported</th><th>Error summary</th></tr></thead><tbody>';
    foreach ($imports as $r) {
        $errors = safe_json((string)($r['errors_json'] ?? '[]'));
        $n = is_array($errors) ? count($errors) : 0;
        echo '<tr><td>' . h((string)$r['created_at']) . '</td><td>' . h((string)$r['filename']) . '</td><td class="right">' . h((string)$r['rows_total']) . '</td><td class="right">' . h((string)$r['rows_imported']) . '</td><td class="muted">' . h($n ? ($n . ' issue(s)') : '—') . '</td></tr>';
    }
    if (!$imports) {
        echo '<tr><td colspan="5" class="muted">No imports yet.</td></tr>';
    }
    echo '</tbody></table></div>';
}

function profile_cell_input(array $row, string $field, string $value, string $variant = ''): string
{
    $classes = 'profileCellInput';
    if ($variant === 'num') {
        $classes .= ' right';
    } elseif ($variant !== '') {
        $classes .= ' ' . $variant;
    }
    $id = (int)($row['id'] ?? 0);
    $label = trim((string)($row['policy_number'] ?? 'row')) . ' ' . $field;
    return '<td' . ($variant === 'num' ? ' class="right"' : '') . '><input class="' . h($classes) . '" data-policy-cell data-policy-id="' . h((string)$id) . '" data-field="' . h($field) . '" value="' . h($value) . '" aria-label="' . h($label) . '"></td>';
}

function render_tab_profiles(PDO $pdo): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    $tier = trim((string)($_GET['tier'] ?? ''));
    $region = trim((string)($_GET['region'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 25;
    $offset = ($page - 1) * $limit;

    $where = [];
    $args = [];
    if ($q !== '') {
        $where[] = '(policy_number LIKE ? OR name LIKE ?)';
        $args[] = '%' . $q . '%';
        $args[] = '%' . $q . '%';
    }
    if ($tier !== '') {
        $where[] = 'risk_tier = ?';
        $args[] = $tier;
    }
    if ($region !== '') {
        $where[] = 'region = ?';
        $args[] = $region;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $st = $pdo->prepare('SELECT COUNT(*) FROM policyholders ' . $whereSql);
    $st->execute($args);
    $total = (int)$st->fetchColumn();

    $st = $pdo->prepare('SELECT id, policy_number, name, age, policy_type, region, premium_amount, tenure_months, sum_assured, ltv_estimate, risk_tier, confidence_score, is_imputed, metadata FROM policyholders ' . $whereSql . ' ORDER BY id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset);
    $st->execute($args);
    $rows = $st->fetchAll();
    $customDefs = $pdo->query('SELECT field_key, label FROM custom_field_defs WHERE enabled = 1 ORDER BY field_key ASC LIMIT 4')->fetchAll();

    echo '<div class="card"><div class="h2">Policyholders</div>';
    echo '<form method="get" class="form"><input type="hidden" name="tab" value="profiles">';
    echo '<div class="grid2"><div><label class="label">Search</label><input class="input" name="q" value="' . h($q) . '" placeholder="Policy number or name"></div>';
    echo '<div><label class="label">Risk tier</label><input class="input" name="tier" value="' . h($tier) . '" placeholder="High"></div></div>';
    echo '<div class="grid2"><div><label class="label">Region</label><input class="input" name="region" value="' . h($region) . '" placeholder="West"></div>';
    echo '<div class="actionsRow" style="align-items:flex-end"><button class="btnSecondary" type="submit">Filter</button><a class="link" href="' . h(self_url(['tab' => 'profiles'])) . '">Reset</a></div></div>';
    echo '</form>';

    echo '<div class="muted" id="profileInlineStatus">Showing ' . h((string)count($rows)) . ' of ' . h((string)$total) . '. Edit cells directly; LTV, risk tier, confidence, and segments refresh after save.</div>';
    echo '<input type="hidden" id="profileInlineCsrf" value="' . h(csrf_token()) . '">';
    echo '<div class="scrollX"><table class="table"><thead><tr><th>Record / Policy ID</th><th>Display name</th><th>Age</th><th>Type</th><th>Region</th><th class="right">Premium</th><th class="right">Tenure</th><th class="right">Assured</th><th class="right">LTV</th><th>Tier</th><th class="right">Conf</th><th>Imputed</th>';
    foreach ($customDefs as $def) {
        echo '<th>' . h((string)$def['label']) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $low = (int)$r['confidence_score'] < 60;
        $meta = safe_json((string)($r['metadata'] ?? '{}'));
        $custom = is_array($meta['custom_fields'] ?? null) ? (array)$meta['custom_fields'] : [];
        $policyId = h((string)(int)$r['id']);
        echo '<tr class="' . ($low ? 'rowLow' : '') . '">';
        echo profile_cell_input($r, 'policy_number', (string)$r['policy_number'], 'policy');
        echo profile_cell_input($r, 'name', (string)$r['name'], 'name');
        echo profile_cell_input($r, 'age', (string)($r['age'] ?? ''), 'num');
        echo profile_cell_input($r, 'policy_type', (string)($r['policy_type'] ?? ''));
        echo profile_cell_input($r, 'region', (string)($r['region'] ?? ''));
        echo profile_cell_input($r, 'premium_amount', (string)($r['premium_amount'] ?? ''), 'num');
        echo profile_cell_input($r, 'tenure_months', (string)($r['tenure_months'] ?? ''), 'num');
        echo profile_cell_input($r, 'sum_assured', (string)($r['sum_assured'] ?? ''), 'num');
        echo '<td class="right" data-derived-field="ltv_estimate" data-policy-id="' . $policyId . '">' . h(money_or_blank($r['ltv_estimate'] ?? null)) . '</td>';
        echo '<td data-derived-field="risk_tier" data-policy-id="' . $policyId . '">' . h((string)($r['risk_tier'] ?? 'Unknown')) . '</td>';
        echo '<td class="right" data-derived-field="confidence_score" data-policy-id="' . $policyId . '">' . h((string)($r['confidence_score'] ?? 0)) . '</td>';
        echo '<td>' . ((int)$r['is_imputed'] === 1 ? '<span class="badge">Yes</span>' : '<span class="badge mutedBadge">No</span>') . '</td>';
        foreach ($customDefs as $def) {
            $v = $custom[(string)$def['field_key']] ?? '';
            echo profile_cell_input($r, 'custom.' . (string)$def['field_key'], is_bool($v) ? ($v ? 'true' : 'false') : (string)$v);
        }
        echo '</tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="' . h((string)(12 + count($customDefs))) . '" class="muted">No policyholders found.</td></tr>';
    }
    echo '</tbody></table></div>';

    $pages = (int)ceil(max(1, $total) / $limit);
    if ($pages > 1) {
        echo '<div class="pager">';
        for ($i = 1; $i <= $pages; $i++) {
            $href = self_url(['tab' => 'profiles', 'q' => $q, 'tier' => $tier, 'region' => $region, 'page' => (string)$i]);
            echo '<a class="' . ($i === $page ? 'pagerOn' : 'pagerOff') . '" href="' . h($href) . '">' . h((string)$i) . '</a>';
        }
        echo '</div>';
    }
    echo '</div>';

    echo '<div class="card"><div class="h2">Repair (bulk fill)</div><div class="muted">Bulk-fill missing fields.</div>';
    echo '<form method="post" class="form"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="repair_apply">';
    echo '<div class="grid2"><div><label class="label">Set region where missing</label><input class="input" name="set_region" maxlength="80" placeholder="Unknown"></div>';
    echo '<div><label class="label">Set policy_type where missing</label><input class="input" name="set_policy_type" maxlength="80" placeholder="term"></div></div>';
    echo '<button class="btnSecondary" type="submit">Apply repair</button></form></div>';
}
function render_tab_rules(PDO $pdo): void
{
    $editId = (int)($_GET['edit'] ?? 0);
    $editing = null;
    if ($editId > 0) {
        $st = $pdo->prepare('SELECT * FROM rules WHERE id = ?');
        $st->execute([$editId]);
        $editing = $st->fetch();
    }

    $rules = $pdo->query('SELECT id, name, priority, enabled FROM rules ORDER BY priority ASC, id ASC')->fetchAll();
    echo '<div class="actionsRow">';
    echo '<a class="btnSecondary" href="' . h(self_url(['tab' => 'rules'])) . '">New rule</a>';
    echo '<form method="post" class="inline"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="export_rules"><button class="btnSecondary" type="submit">Export rules JSON</button></form>';
    echo '</div>';

    echo '<div class="grid2">';
    echo '<div class="card"><div class="h2">Rules</div>';
    echo '<table class="table"><thead><tr><th>Name</th><th class="right">Priority</th><th>Enabled</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rules as $r) {
        echo '<tr><td>' . h((string)$r['name']) . '</td><td class="right">' . h((string)$r['priority']) . '</td>';
        echo '<td>' . ((int)$r['enabled'] === 1 ? '<span class="badge">Yes</span>' : '<span class="badge mutedBadge">No</span>') . '</td>';
        echo '<td>';
        echo '<a class="link" href="' . h(self_url(['tab' => 'rules', 'edit' => (string)$r['id']])) . '">Edit</a> · ';
        echo '<form method="post" class="inline"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="rule_toggle"><input type="hidden" name="id" value="' . h((string)$r['id']) . '"><button class="linkBtn" type="submit">' . h(((int)$r['enabled'] === 1) ? 'Disable' : 'Enable') . '</button></form> · ';
        echo '<form method="post" class="inline" onsubmit="return confirm(\'Delete this rule?\')"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="rule_delete"><input type="hidden" name="id" value="' . h((string)$r['id']) . '"><button class="linkBtn" type="submit">Delete</button></form>';
        echo '</td></tr>';
    }
    if (!$rules) {
        echo '<tr><td colspan="4" class="muted">No rules yet.</td></tr>';
    }
    echo '</tbody></table></div>';

    $def = ['logic' => 'AND', 'conditions' => [['field' => 'age', 'op' => '>=', 'value' => 60]], 'actions' => ['set_risk_tier' => 'High', 'score_delta' => 10]];
    $rid = 0;
    $name = '';
    $priority = 100;
    $enabled = 1;
    $json = json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($editing) {
        $rid = (int)$editing['id'];
        $name = (string)$editing['name'];
        $priority = (int)$editing['priority'];
        $enabled = (int)$editing['enabled'];
        $conds = safe_json((string)$editing['conditions_json']);
        $acts = safe_json((string)$editing['actions_json']);
        $json = json_encode(['logic' => (is_array($conds) && isset($conds['logic'])) ? $conds['logic'] : 'AND', 'conditions' => (is_array($conds) && isset($conds['conditions'])) ? $conds['conditions'] : (is_array($conds) ? $conds : []), 'actions' => is_array($acts) ? $acts : []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    $fieldOptions = [
        'age' => 'Age',
        'gender' => 'Gender',
        'policy_type' => 'Policy type',
        'region' => 'Region',
        'premium_amount' => 'Premium amount',
        'tenure_months' => 'Tenure months',
        'sum_assured' => 'Sum assured',
        'ltv_estimate' => 'LTV estimate',
        'risk_tier' => 'Risk tier',
        'confidence_score' => 'Confidence score',
        'is_imputed' => 'Is imputed',
    ];
    $customDefs = $pdo->query('SELECT field_key, label FROM custom_field_defs WHERE enabled = 1 ORDER BY field_key ASC LIMIT 50')->fetchAll();
    foreach ($customDefs as $def) {
        $key = (string)($def['field_key'] ?? '');
        if ($key !== '') {
            $fieldOptions['custom.' . $key] = 'custom.' . $key;
        }
    }
    $fieldOptionHtml = '';
    foreach ($fieldOptions as $value => $label) {
        $fieldOptionHtml .= '<option value="' . h((string)$value) . '">' . h((string)$label) . '</option>';
    }
    $operatorOptionHtml = '';
    foreach (['=', '!=', '>', '<', '>=', '<=', 'in', 'contains', 'regex'] as $op) {
        $operatorOptionHtml .= '<option value="' . h($op) . '">' . h($op) . '</option>';
    }

    echo '<div class="card"><div class="h2">' . h($rid > 0 ? 'Edit rule' : 'New rule') . '</div>';
    echo '<form method="post" class="form">';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="rule_save">';
    if ($rid > 0) {
        echo '<input type="hidden" name="id" value="' . h((string)$rid) . '">';
    }
    echo '<label class="label">Name</label><input class="input" name="name" required maxlength="120" value="' . h($name) . '">';
    echo '<div class="grid2"><div><label class="label">Priority</label><input class="input" type="number" name="priority" min="0" max="1000" value="' . h((string)$priority) . '"></div>';
    echo '<div><label class="label">Enabled</label><select class="select" name="enabled"><option value="1"' . ($enabled === 1 ? ' selected' : '') . '>Yes</option><option value="0"' . ($enabled === 0 ? ' selected' : '') . '>No</option></select></div></div>';
    echo '<select id="ruleFieldOptions" hidden>' . $fieldOptionHtml . '</select><select id="ruleOperatorOptions" hidden>' . $operatorOptionHtml . '</select>';
    echo '<div class="ruleModeTabs" role="tablist" aria-label="Rule editor mode"><button class="ruleModeTab" type="button" data-rule-mode="visual" aria-selected="true">Visual builder</button><button class="ruleModeTab" type="button" data-rule-mode="json" aria-selected="false">Plain JSON</button></div>';
    echo '<div class="ruleModePanel" id="ruleVisualPanel"><div class="grid2"><div><label class="label">Match logic</label><select class="select" id="ruleLogicVisual"><option value="AND">All conditions</option><option value="OR">Any condition</option></select></div><div class="inlineCheck"><input type="checkbox" id="ruleAdvancedJsonNotice" disabled><span>Nested groups stay in Plain JSON</span></div></div><div id="ruleConditionsVisual"></div><div class="actionsRow"><button class="btnSecondary" type="button" id="addRuleCondition">Add condition</button></div><div class="ruleActionGrid"><div><label class="label">Set tier</label><select class="select" id="ruleActionTier"><option value="">No change</option><option>Low</option><option>Medium</option><option>High</option><option>Review</option><option>Unknown</option></select></div><div><label class="label">Score delta</label><input class="input" id="ruleActionScore" type="number" min="-100" max="100" value="0"></div><div><label class="label">Add tag</label><input class="input" id="ruleActionTag" maxlength="80" placeholder="senior_short_tenure"></div></div></div>';
    echo '<div class="ruleModePanel" id="ruleJsonPanel" hidden><label class="label">Rule JSON</label><textarea class="textarea" name="definition_json" rows="12" spellcheck="false">' . h((string)$json) . '</textarea><div class="muted tiny">Ops: =, !=, &gt;, &lt;, &gt;=, &lt;=, in, contains, regex. Use custom CSV fields as custom.agent_code. Group: {"all": [...]} / {"any": [...]} in conditions.</div></div>';
    echo '<button class="btn" type="submit">Save rule</button>';

    echo '<div class="divider"></div><div class="h2">Test a rule</div>';
    $ph = $pdo->query('SELECT id, policy_number, name FROM policyholders ORDER BY id DESC LIMIT 25')->fetchAll();
    echo '<label class="label">Policyholder (recent)</label><select class="select" name="_test_policyholder_id" id="ruleTestPolicy">';
    foreach ($ph as $p) {
        echo '<option value="' . h((string)$p['id']) . '">' . h((string)$p['policy_number'] . ' — ' . mask_name((string)$p['name'])) . '</option>';
    }
    if (!$ph) {
        echo '<option value="0">No policyholders available</option>';
    }
    echo '</select>';
    echo '<button class="btnSecondary" type="button" onclick="LiteInsurance.ruleTest()">Run test</button>';
    echo '<pre class="codeBlock" id="ruleTestOut">Ready.</pre>';

    echo '</form></div>';
    echo '</div>';
}
function render_tab_segments(PDO $pdo): void
{
    $editId = (int)($_GET['edit'] ?? 0);
    $editing = null;
    if ($editId > 0) {
        $st = $pdo->prepare('SELECT * FROM segments WHERE id = ?');
        $st->execute([$editId]);
        $editing = $st->fetch();
    }

    $segments = $pdo->query('SELECT id, name, rule_ids, last_run_at FROM segments ORDER BY id DESC')->fetchAll();
    $rules = $pdo->query('SELECT id, name, priority FROM rules ORDER BY priority ASC, id ASC')->fetchAll();

    echo '<div class="grid2">';
    echo '<div class="card"><div class="h2">Segments</div>';
    echo '<table class="table"><thead><tr><th>Name</th><th>Last run</th><th>Actions</th></tr></thead><tbody>';
    foreach ($segments as $s) {
        echo '<tr><td>' . h((string)$s['name']) . '</td><td class="muted">' . h((string)($s['last_run_at'] ?? '—')) . '</td><td>';
        echo '<a class="link" href="' . h(self_url(['tab' => 'segments', 'edit' => (string)$s['id']])) . '">Edit</a> · ';
        echo '<form method="post" class="inline"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="id" value="' . h((string)$s['id']) . '"><button class="linkBtn" type="submit" name="action" value="export_segment_prepare">Export CSV</button></form> · ';
        echo '<form method="post" class="inline" onsubmit="return confirm(\'Delete this segment?\')"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="id" value="' . h((string)$s['id']) . '"><button class="linkBtn" type="submit" name="action" value="segment_delete">Delete</button></form>';
        echo '</td></tr>';
    }
    if (!$segments) {
        echo '<tr><td colspan="3" class="muted">No segments yet.</td></tr>';
    }
    echo '</tbody></table></div>';

    $sid = 0;
    $name = '';
    $criteria = ['rule_ids' => [], 'where' => "risk_tier = 'High'", 'mode' => 'rules_and_where'];
    if ($editing) {
        $sid = (int)$editing['id'];
        $name = (string)$editing['name'];
        $criteria = safe_json((string)$editing['rule_ids']);
        if (!is_array($criteria)) {
            $criteria = ['rule_ids' => [], 'where' => '', 'mode' => 'rules_and_where'];
        }
        $criteria['rule_ids'] = is_array($criteria['rule_ids'] ?? null) ? (array)$criteria['rule_ids'] : [];
        $criteria['where'] = is_string($criteria['where'] ?? null) ? (string)$criteria['where'] : '';
    }

    echo '<div class="card"><div class="h2">' . h($sid > 0 ? 'Edit segment' : 'New segment') . '</div>';
    echo '<form method="post" class="form">';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
    if ($sid > 0) {
        echo '<input type="hidden" name="id" value="' . h((string)$sid) . '">';
    }
    echo '<label class="label">Name</label><input class="input" name="name" required maxlength="120" value="' . h($name) . '">';
    echo '<label class="label">Select rules (segment requires ALL selected rules)</label><div class="checkGrid">';
    foreach ($rules as $r) {
        $rid = (int)$r['id'];
        $checked = in_array($rid, (array)$criteria['rule_ids'], true) ? ' checked' : '';
        echo '<label class="check"><input type="checkbox" name="rule_ids[]" value="' . h((string)$rid) . '"' . $checked . '> ' . h((string)$r['name']) . '</label>';
    }
    if (!$rules) {
        echo '<div class="muted">No rules available.</div>';
    }
    echo '</div>';
    echo '<label class="label">Optional WHERE-style filter</label><input class="input" name="where" maxlength="400" value="' . h((string)$criteria['where']) . '">';
    echo '<div class="muted tiny">Allowed fields: risk_tier, region, policy_type, premium_amount, tenure_months, age, ltv_estimate, confidence_score, plus custom.field_key such as custom.renewal_probability.</div>';
    echo '<div class="actionsRow"><button class="btn" type="submit" name="action" value="segment_save">Save segment</button><button class="btnSecondary" type="submit" name="action" value="segment_estimate">Estimate size</button></div>';
    echo '</form></div>';
    echo '</div>';
}

function render_tab_campaigns(PDO $pdo): void
{
    $segments = $pdo->query('SELECT id, name FROM segments ORDER BY id DESC')->fetchAll();
    $campaigns = $pdo->query('SELECT c.name, c.projected_revenue, c.created_at, s.name AS segment_name FROM campaigns c LEFT JOIN segments s ON s.id = c.segment_id ORDER BY c.created_at DESC LIMIT 20')->fetchAll();

    echo '<div class="grid2">';
    echo '<div class="card"><div class="h2">Simulator</div>';
    echo '<form method="post" class="form">';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="campaign_save">';
    echo '<label class="label">Campaign name</label><input class="input" name="name" required maxlength="120" value="Cross-sell test">';
    echo '<label class="label">Segment</label><select class="select" name="segment_id" required>';
    foreach ($segments as $s) {
        echo '<option value="' . h((string)$s['id']) . '">' . h((string)$s['name']) . '</option>';
    }
    if (!$segments) {
        echo '<option value="0">No segments available</option>';
    }
    echo '</select>';
    echo '<div class="grid2"><div><label class="label">Cross-sell rate</label><input class="input" name="cross_sell_rate" type="number" step="0.001" min="0" max="1" value="0.05"></div>';
    echo '<div><label class="label">Avg offer value</label><input class="input" name="avg_offer_value" type="number" step="0.01" min="0" value="1200"></div></div>';
    echo '<div class="grid2"><div><label class="label">Cost per contact</label><input class="input" name="campaign_cost_per_contact" type="number" step="0.01" min="0" value="2.50"></div>';
    echo '<div><label class="label">Conversion lift</label><input class="input" name="conversion_lift" type="number" step="0.01" min="0" value="0"></div></div>';
    echo '<button class="btn" type="submit">Run simulation</button>';
    echo '</form></div>';

    echo '<div class="card"><div class="h2">Recent campaigns</div>';
    echo '<table class="table"><thead><tr><th>When</th><th>Name</th><th>Segment</th><th class="right">Projected revenue</th></tr></thead><tbody>';
    foreach ($campaigns as $c) {
        echo '<tr><td>' . h((string)$c['created_at']) . '</td><td>' . h((string)$c['name']) . '</td><td class="muted">' . h((string)($c['segment_name'] ?? '—')) . '</td><td class="right">' . h(CURRENCY_SYMBOL . number_format((float)($c['projected_revenue'] ?? 0), 2)) . '</td></tr>';
    }
    if (!$campaigns) {
        echo '<tr><td colspan="4" class="muted">No campaigns yet.</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '</div>';
}

function render_tab_settings(PDO $pdo): void
{
    $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $rules = (int)$pdo->query('SELECT COUNT(*) FROM rules')->fetchColumn();
    $segs = (int)$pdo->query('SELECT COUNT(*) FROM segments')->fetchColumn();
    $pol = (int)$pdo->query('SELECT COUNT(*) FROM policyholders')->fetchColumn();

    echo '<div class="grid2">';
    echo '<div class="card"><div class="h2">App config (read-only)</div>';
    echo '<table class="table"><tbody>';
    echo '<tr><td>DB path</td><td class="muted"><code>' . h(db_path()) . '</code></td></tr>';
    echo '<tr><td>Upload dir</td><td class="muted"><code>' . h(upload_dir()) . '</code></td></tr>';
    echo '<tr><td>Session timeout</td><td class="muted">' . h((string)SESSION_TIMEOUT_SECONDS) . ' seconds</td></tr>';
    echo '<tr><td>Accent</td><td class="muted"><span class="swatch" style="background:' . h(ACCENT_COLOR) . '"></span> ' . h(ACCENT_COLOR) . '</td></tr>';
    echo '</tbody></table>';
    echo '<div class="divider"></div>';
    echo '<form method="post" class="inline"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><button class="btnSecondary" type="submit" name="action" value="recompute_now">Recompute LTV + risk tiers</button></form>';
    echo '<div class="muted tiny">Use this after changing rules. Cron is recommended for scheduled runs.</div>';
    echo '</div>';

    echo '<div class="card"><div class="h2">Counts</div>';
    echo '<div class="kpiGrid">' . kpi('Users', (string)$users) . kpi('Policyholders', (string)$pol) . kpi('Rules', (string)$rules) . kpi('Segments', (string)$segs) . '</div>';
    echo '<div class="divider"></div>';
    echo '<div class="h2">Cron</div><div class="muted">Recommended cPanel cron:</div>';
    echo '<pre class="codeBlock">php /home/USER/public_html/liteinsurance.php action=cron_jobs cron_token=YOUR_TOKEN</pre>';
    echo '<div class="muted tiny">If <code>CRON_TOKEN</code> is blank, cron runs only via CLI.</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="card"><div class="h2">Setup doctor</div><div class="muted">Checks the self-hosted PHP workflow: PHP, PDO SQLite, DB path, upload path, upload limits, .env, CRON_TOKEN, and cron readiness.</div>';
    echo render_setup_doctor_table(setup_doctor_report());
    echo '</div>';
}

function setup_doctor_report(?Throwable $bootError = null): array
{
    $checks = [];
    $add = static function (string $label, string $status, string $detail, string $fix = '') use (&$checks): void {
        $checks[] = ['label' => $label, 'status' => $status, 'detail' => $detail, 'fix' => $fix];
    };

    if ($bootError) {
        $add('App boot', 'fail', $bootError->getMessage(), 'Fix the failed requirement below, then reload the app.');
    } else {
        $add('App boot', 'ok', 'The PHP app booted and connected to SQLite.');
    }

    $add('PHP version', PHP_VERSION_ID >= 80000 ? 'ok' : 'fail', PHP_VERSION, 'Use PHP 8.0 or newer.');
    $add('PDO SQLite', extension_loaded('pdo_sqlite') ? 'ok' : 'fail', extension_loaded('pdo_sqlite') ? 'Enabled' : 'Missing', 'Enable the pdo_sqlite extension in your hosting control panel.');

    $db = db_path();
    $dbDir = dirname($db);
    $dbWritable = is_file($db) ? is_writable($db) : (is_dir($dbDir) && is_writable($dbDir));
    $add('SQLite path', $dbWritable ? 'ok' : 'fail', $db, 'Make the directory writable, or set DB_PATH in .env.');
    $add('SQLite location', path_inside_app_dir($db) ? 'warn' : 'ok', path_inside_app_dir($db) ? 'Database is inside the app folder.' : 'Database is outside the app folder.', 'Move DB_PATH outside public_html when hosting allows.');

    $up = upload_dir();
    $upWritable = (is_dir($up) && is_writable($up)) || (!is_dir($up) && is_dir(dirname($up)) && is_writable(dirname($up)));
    $add('Upload path', $upWritable ? 'ok' : 'fail', $up, 'Make this directory writable, or set UPLOAD_DIR in .env.');
    $add('Upload location', path_inside_app_dir($up) ? 'warn' : 'ok', path_inside_app_dir($up) ? 'Uploads are inside the app folder.' : 'Uploads are outside the app folder.', 'Move UPLOAD_DIR outside public_html when hosting allows.');

    $uploadMax = ini_bytes((string)ini_get('upload_max_filesize'));
    $postMax = ini_bytes((string)ini_get('post_max_size'));
    $limit = min($uploadMax > 0 ? $uploadMax : MAX_UPLOAD_BYTES, $postMax > 0 ? $postMax : MAX_UPLOAD_BYTES);
    $uploadOk = $limit >= MAX_UPLOAD_BYTES;
    $add('Upload limits', $uploadOk ? 'ok' : 'warn', 'upload_max_filesize=' . (string)ini_get('upload_max_filesize') . ', post_max_size=' . (string)ini_get('post_max_size'), 'Raise both limits above ' . bytes_label(MAX_UPLOAD_BYTES) . ' for large CSV imports.');

    $envPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
    $add('.env file', is_file($envPath) ? 'ok' : 'warn', is_file($envPath) ? 'Found' : 'Not found', 'Copy .env.example to .env when you need DB_PATH, UPLOAD_DIR, or CRON_TOKEN overrides.');
    $cron = cfg('CRON_TOKEN', CRON_TOKEN);
    $cronOk = strlen($cron) >= 24 && $cron !== 'change-me-please-32chars-min';
    $add('CRON_TOKEN', $cronOk ? 'ok' : 'warn', $cronOk ? 'Configured' : 'Blank or placeholder', 'Set a long random CRON_TOKEN before enabling web cron.');
    $add('Cron command', 'ok', 'php /home/USER/public_html/liteinsurance.php action=cron_jobs cron_token=YOUR_TOKEN');

    return $checks;
}

function render_setup_doctor_table(array $checks): string
{
    $out = '<table class="table"><thead><tr><th>Check</th><th>Status</th><th>Detail</th><th>Fix</th></tr></thead><tbody>';
    foreach ($checks as $c) {
        $status = (string)($c['status'] ?? 'warn');
        $badge = $status === 'ok' ? 'badge' : ($status === 'fail' ? 'badge rowLow' : 'badge mutedBadge');
        $out .= '<tr><td>' . h((string)($c['label'] ?? '')) . '</td><td><span class="' . h($badge) . '">' . h(strtoupper($status)) . '</span></td><td class="muted"><code>' . h((string)($c['detail'] ?? '')) . '</code></td><td class="muted">' . h((string)($c['fix'] ?? '')) . '</td></tr>';
    }
    $out .= '</tbody></table>';
    return $out;
}

function render_boot_setup_error(Throwable $e): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "LiteInsurance setup error: " . $e->getMessage() . "\n");
        foreach (setup_doctor_report($e) as $check) {
            fwrite(STDERR, strtoupper((string)$check['status']) . ' ' . (string)$check['label'] . ': ' . (string)$check['detail'] . "\n");
        }
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>LiteInsurance setup doctor</title>';
    echo '<style>body{margin:0;background:#f4f3ee;color:#0a0a0a;font-family:Inter,system-ui,-apple-system,"Segoe UI",Arial,sans-serif}.wrap{max-width:980px;margin:40px auto;padding:0 20px}.card{border:1px solid #cfcdc4;background:#fbfaf6;padding:18px}h1{margin:0 0 10px;font-size:28px}.muted{color:#6b6b66}.table{width:100%;border-collapse:collapse;margin-top:16px;font-size:13px}th,td{padding:10px;border-bottom:1px solid #e3e1d8;text-align:left;vertical-align:top}.badge{display:inline-block;border:1px solid #0a0a0a;padding:3px 7px;font:11px/1 ui-monospace,Menlo,Consolas,monospace}.rowLow{color:#a33a2a;border-color:#a33a2a}.mutedBadge{color:#8a877e;border-color:#cfcdc4}code{font-family:ui-monospace,Menlo,Consolas,monospace}</style></head><body><main class="wrap"><section class="card">';
    echo '<h1>LiteInsurance setup doctor</h1><p class="muted">The app could not finish booting. Fix the failed check below and reload.</p>';
    echo render_setup_doctor_table(setup_doctor_report($e));
    echo '</section></main></body></html>';
}

function path_inside_app_dir(string $path): bool
{
    $base = realpath(__DIR__);
    $real = realpath($path);
    if (!$real) {
        $real = realpath(dirname($path));
    }
    return is_string($base) && is_string($real) && str_starts_with(strtolower($real), strtolower($base));
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $num = (float)$value;
    if ($unit === 'g') {
        return (int)($num * 1024 * 1024 * 1024);
    }
    if ($unit === 'm') {
        return (int)($num * 1024 * 1024);
    }
    if ($unit === 'k') {
        return (int)($num * 1024);
    }
    return (int)$num;
}

function bytes_label(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' bytes';
}

function run_cron_jobs(PDO $pdo): void
{
    $token = (string)($_GET['cron_token'] ?? '');
    if (PHP_SAPI !== 'cli') {
        $expected = (string)cfg('CRON_TOKEN', CRON_TOKEN);
        if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
    }
    $rules = load_rules($pdo);
    $updated = 0;
    $offsetId = 0;
    $pdo->beginTransaction();
    $upd = $pdo->prepare('UPDATE policyholders SET ltv_estimate = ?, risk_tier = ?, confidence_score = ?, metadata = ? WHERE id = ?');
    while (true) {
        $st = $pdo->prepare('SELECT * FROM policyholders WHERE id > ? ORDER BY id ASC LIMIT 300');
        $st->execute([$offsetId]);
        $rows = $st->fetchAll();
        if (!$rows) {
            break;
        }
        foreach ($rows as $ph) {
            $rec = policyholder_record_from_row($ph);
            $meta = safe_json((string)($ph['metadata'] ?? '{}'));
            if (!is_array($meta)) {
                $meta = [];
            }
            $metadata = $meta;
            $imputed = is_array($meta['imputed_fields'] ?? null) ? (array)$meta['imputed_fields'] : [];
            $score = compute_confidence($rec, $imputed);
            $riskOut = evaluate_rules_for_record($rec, $rules);
            $ruleHits = (array)($riskOut['rule_hits'] ?? []);
            $score = min(100, (int)round($score + min(20, count($ruleHits) * 4)));
            $ltv = compute_ltv($rec);
            $newMeta = ['imputed_fields' => $imputed, 'rule_hits' => $ruleHits, 'tags' => (array)($riskOut['tags'] ?? [])];
            if (isset($metadata['custom_fields']) && is_array($metadata['custom_fields'])) {
                $newMeta['custom_fields'] = $metadata['custom_fields'];
            }
            $upd->execute([$ltv, (string)($riskOut['risk_tier'] ?? 'Unknown'), $score, json_encode($newMeta, JSON_UNESCAPED_SLASHES), (int)$ph['id']]);
            $updated++;
            $offsetId = (int)$ph['id'];
        }
        if (($updated % 300) === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
        }
    }
    $pdo->commit();
    $pdo->prepare('UPDATE segments SET last_run_at = ? WHERE last_run_at IS NULL OR last_run_at = ?')->execute([now_iso(), '']);
    echo 'OK. Updated policyholders: ' . $updated;
}

function audit(PDO $pdo, int $userId, string $action, array $payload): void
{
    $st = $pdo->prepare('INSERT INTO audit_log (user_id, action, payload, ts) VALUES (?, ?, ?, ?)');
    $st->execute([$userId > 0 ? $userId : null, $action, json_encode($payload, JSON_UNESCAPED_SLASHES), now_iso()]);
}

function flash(string $type, string $msg): void { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }
function consume_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return is_array($f) ? $f : null; }

function render_flash(array $flash): string
{
    $type = (string)($flash['type'] ?? 'info');
    $msg = (string)($flash['msg'] ?? '');
    $cls = $type === 'error' ? 'flash flashErr' : ($type === 'success' ? 'flash flashOk' : 'flash');
    return '<div class="' . h($cls) . '">' . h($msg) . '</div>';
}

function nav_item(string $label, string $tab, string $current): string
{
    $cls = ($tab === $current) ? 'navItem navOn' : 'navItem';
    return '<a class="' . h($cls) . '" href="' . h(self_url(['tab' => $tab])) . '">' . h($label) . '</a>';
}

function kpi(string $label, string $value): string
{
    return '<div class="kpi"><div class="kpiLabel">' . h($label) . '</div><div class="kpiValue">' . h($value) . '</div></div>';
}

function money_or_blank($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return CURRENCY_SYMBOL . number_format((float)$value, 2);
}

function htmlEscape(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function h(string $s): string { return htmlEscape($s); }
function now_iso(): string { return gmdate('Y-m-d H:i:s'); }
function client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? ''); }

function redirect_self(): void { header('Location: ' . self_url([])); exit; }

function redirect_to(array $params): void { header('Location: ' . self_url($params)); exit; }

function self_url(array $params): string
{
    $base = (string)($_SERVER['PHP_SELF'] ?? 'liteinsurance.php');
    $q = array_merge($_GET, []);
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    $query = http_build_query($q);
    return $query ? ($base . '?' . $query) : $base;
}

function download_text(string $filename, string $contents, string $contentType): void
{
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $contents;
}

function inter_font_css(): string
{
    return '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">';
}

function app_css(): string
{
    $accent = h(ACCENT_COLOR);
    $nonce = h((string)($GLOBALS['CSP_NONCE'] ?? ''));
    return '<style nonce="' . $nonce . '">'
        . ':root{--bg:#f4f3ee;--bgAlt:#ebe9e1;--panel:#fbfaf6;--text:#0a0a0a;--muted:#6b6b66;--muted2:#8a877e;--faint:#a8a59c;--border:#cfcdc4;--borderSoft:#e3e1d8;--accent:' . $accent . ';--dark:#0c0d0e;--darkInk:#f4f3ee;--darkMute:#8e8f88;--darkRule:#232527;--mono:"JetBrains Mono","SF Mono",ui-monospace,Menlo,Consolas,monospace;}'
        . '*{box-sizing:border-box;}html,body{height:100%;}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;-webkit-font-smoothing:antialiased;}'
        . 'a{color:inherit;}code{font-family:var(--mono);font-size:.95em;}'
        . '.appShell{display:grid;grid-template-columns:248px minmax(0,1fr);grid-template-rows:60px 1fr auto;min-height:100vh;}'
        . '.topBar{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;padding:0 20px;border-bottom:1px solid var(--border);background:rgba(244,243,238,.94);backdrop-filter:blur(10px);position:sticky;top:0;z-index:5;}'
        . '.brand{display:inline-flex;align-items:center;gap:12px;font-weight:650;letter-spacing:-0.02em;}'
        . '.brand:before{content:"LI";display:grid;place-items:center;width:34px;height:34px;background:var(--text);color:var(--bg);font:11px/1 var(--mono);letter-spacing:.05em;}'
        . '.topRight{display:flex;align-items:center;gap:12px;min-width:0;}'
        . '.userPill{display:flex;align-items:center;gap:8px;min-width:0;padding:7px 10px;border:1px solid var(--border);background:var(--panel);font-size:12px;}'
        . '.pillRole{padding:2px 7px;border:1px solid var(--border);color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.08em;font-size:10px;}'
        . '.sideNav{border-right:1px solid var(--border);background:var(--panel);padding:12px;display:flex;flex-direction:column;gap:1px;}'
        . '.navItem{padding:11px 10px;text-decoration:none;color:var(--muted);border:1px solid transparent;font:12px/1.2 var(--mono);letter-spacing:.08em;text-transform:uppercase;}'
        . '.navItem:hover{border-color:var(--border);background:var(--bgAlt);color:var(--text);}'
        . '.navOn{border-color:var(--border);background:var(--bg);color:var(--text);box-shadow:inset 3px 0 0 var(--accent);font-weight:700;}'
        . '.main{padding:22px 22px 44px;max-width:1180px;width:100%;}'
        . '.footer{grid-column:1/-1;border-top:1px solid var(--border);padding:14px 20px;color:var(--muted);font-size:12px;display:flex;gap:8px;align-items:center;font-family:var(--mono);}'
        . '.pageHead{margin-bottom:16px;border-bottom:1px solid var(--border);padding-bottom:14px;}'
        . '.h1{margin:0 0 4px;font-size:24px;line-height:30px;letter-spacing:-.03em;}'
        . '.h2{font-size:14px;font-weight:650;margin:0 0 10px;letter-spacing:-.01em;}'
        . '.muted{color:var(--muted);font-size:13px;line-height:18px;}'
        . '.tiny{font-size:12px;}'
        . '.card{border:1px solid var(--border);padding:15px;background:var(--panel);margin-bottom:12px;}'
        . '.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;}'
        . '.kpiGrid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1px;background:var(--border);border:1px solid var(--border);margin:12px 0;}'
        . '.kpi{padding:14px;background:var(--panel);}'
        . '.kpiLabel{color:var(--muted2);font:11px/1.4 var(--mono);letter-spacing:.08em;text-transform:uppercase;}'
        . '.kpiValue{font-size:20px;font-weight:650;margin-top:4px;letter-spacing:-.03em;}'
        . '.form{display:flex;flex-direction:column;gap:10px;margin-top:12px;}'
        . '.label{font:11px/1.4 var(--mono);letter-spacing:.08em;text-transform:uppercase;color:var(--muted2);margin-bottom:-5px;}'
        . '.input,.select,.textarea{width:100%;box-sizing:border-box;border:1px solid var(--border);padding:10px 10px;font-size:14px;outline:none;background:var(--bg);color:var(--text);}'
        . '.input:focus,.select:focus,.textarea:focus{border-color:var(--text);box-shadow:0 0 0 2px var(--accent);}'
        . '.textarea{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;font-size:12px;}'
        . '.btn{border:1px solid var(--text);background:var(--text);color:var(--bg);padding:10px 12px;font-weight:650;cursor:pointer;}'
        . '.btn:hover{background:var(--accent);border-color:var(--accent);color:#fff;}'
        . '.btnSecondary{border:1px solid var(--text);background:transparent;color:var(--text);padding:10px 12px;font-weight:650;cursor:pointer;}'
        . '.btnSecondary:hover{background:var(--text);color:var(--bg);}'
        . '.inline{display:inline;}'
        . '.actionsRow{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:10px 0;}'
        . '.list{margin:10px 0 0;padding-left:18px;color:var(--muted);font-size:13px;}'
        . '.table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13px;background:var(--panel);}'
        . '.table th,.table td{border-bottom:1px solid var(--borderSoft);padding:10px 8px;text-align:left;vertical-align:top;white-space:nowrap;}'
        . '.table th{color:var(--muted2);font:11px/1.4 var(--mono);letter-spacing:.08em;text-transform:uppercase;}'
        . '.right{text-align:right;}'
        . '.profileCellInput{width:100%;min-width:88px;min-height:32px;border:1px solid transparent;background:transparent;padding:0 7px;font-size:12px;color:var(--text);}'
        . '.profileCellInput:hover{border-color:var(--border);background:var(--bg);}'
        . '.profileCellInput:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 1px var(--accent);}'
        . '.profileCellInput.right{text-align:right;}'
        . '.profileCellInput.name{min-width:132px;}'
        . '.profileCellInput.policy{min-width:104px;font-family:var(--mono);}'
        . '.badge{display:inline-block;padding:2px 8px;border:1px solid var(--border);font:11px/1.4 var(--mono);}'
        . '.mutedBadge{color:var(--muted);}'
        . '.scrollX{overflow:auto;border:1px solid var(--border);background:var(--panel);}'
        . '.rowLow{background:var(--bgAlt);}'
        . '.gridMap{display:grid;grid-template-columns:1fr 1fr;gap:10px;}'
        . '.mapRow{display:flex;flex-direction:column;gap:8px;}'
        . '.divider{height:1px;background:var(--border);margin:12px 0;}'
        . '.linkBtn{border:none;background:transparent;color:var(--accent);font-weight:600;cursor:pointer;padding:0;}'
        . '.linkBtn:hover{text-decoration:underline;}'
        . '.checkGrid{display:grid;grid-template-columns:1fr 1fr;gap:8px;border:1px solid var(--border);padding:10px;background:var(--bg);}'
        . '.check{display:flex;gap:8px;align-items:flex-start;font-size:13px;color:var(--text);}'
        . '.codeBlock{border:1px solid var(--darkRule);padding:10px;background:var(--dark);color:var(--darkInk);overflow:auto;font-size:12px;}'
        . '.ruleModeTabs{display:flex;gap:1px;background:var(--border);border:1px solid var(--border);margin:4px 0 10px;}'
        . '.ruleModeTab{flex:1 1 0;border:0;background:var(--panel);color:var(--muted);padding:11px 10px;cursor:pointer;font:11px/1.4 var(--mono);letter-spacing:.1em;text-transform:uppercase;}'
        . '.ruleModeTab[aria-selected="true"]{background:var(--bg);color:var(--text);box-shadow:inset 0 -3px 0 var(--accent);}'
        . '.ruleModePanel{border:1px solid var(--border);background:var(--bg);padding:12px;margin-bottom:10px;}'
        . '.ruleModePanel[hidden]{display:none;}'
        . '.ruleConditionRow{display:grid;grid-template-columns:1fr .62fr 1fr auto;gap:8px;align-items:end;padding:8px 0;border-top:1px solid var(--borderSoft);}'
        . '.ruleConditionRow:first-child{border-top:0;}'
        . '.ruleActionGrid{display:grid;grid-template-columns:1fr .7fr 1fr;gap:10px;margin-top:10px;}'
        . '.inlineCheck{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13px;}'
        . '.inlineCheck input{width:auto;}'
        . '.pager{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;}'
        . '.pagerOn,.pagerOff{padding:6px 10px;border:1px solid var(--border);text-decoration:none;color:var(--text);font-size:12px;font-family:var(--mono);}'
        . '.pagerOn{background:var(--text);color:var(--bg);font-weight:700;}'
        . '.pagerOff{background:var(--panel);}'
        . '.swatch{display:inline-block;width:12px;height:12px;border:1px solid var(--border);vertical-align:-2px;margin-right:6px;}'
        . '.link{color:var(--accent);text-decoration:none;font-weight:600;}'
        . '.link:hover{text-decoration:underline;}'
        . '.flash{border:1px solid var(--border);border-left:4px solid var(--text);background:var(--panel);padding:10px 12px;margin:10px 0;font-size:13px;}'
        . '.flashOk{border-left-color:var(--accent);}'
        . '.flashErr{border-left-color:var(--text);}'
        . '.authWrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:var(--bg);}'
        . '.authCard{width:100%;max-width:430px;border:1px solid var(--border);padding:20px;background:var(--panel);}'
        . '.authBrand{display:flex;align-items:center;gap:12px;font-weight:700;letter-spacing:-0.02em;margin-bottom:6px;}'
        . '.authBrand:before{content:"LI";display:grid;place-items:center;width:34px;height:34px;background:var(--text);color:var(--bg);font:11px/1 var(--mono);}'
        . '.authFooter{margin-top:14px;display:flex;flex-direction:column;gap:8px;}'
        . '@media (max-width:900px){.appShell{grid-template-columns:1fr;grid-template-rows:60px auto 1fr;}.topBar{padding:0 12px;}.userPill{max-width:48vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}.sideNav{flex-direction:row;overflow:auto;white-space:nowrap;border-right:none;border-bottom:1px solid var(--border);} .main{padding:16px 12px 36px;} .grid2{grid-template-columns:1fr;} .kpiGrid{grid-template-columns:repeat(2,minmax(0,1fr));} .gridMap{grid-template-columns:1fr;} .checkGrid{grid-template-columns:1fr;}.ruleConditionRow{grid-template-columns:1fr;}.ruleActionGrid{grid-template-columns:1fr;}}'
        . '</style>';
}

function app_js(): string
{
    $nonce = h((string)($GLOBALS['CSP_NONCE'] ?? ''));
    return '<script nonce="' . $nonce . '">'
        . 'window.LiteInsurance=window.LiteInsurance||{};'
        . 'window.LiteInsurance.ruleTest=async function(){'
        . 'const out=document.getElementById("ruleTestOut");'
        . 'const sel=document.getElementById("ruleTestPolicy");'
        . 'const ta=document.querySelector("textarea[name=definition_json]");'
        . 'const csrf=document.querySelector("input[name=csrf_token]");'
        . 'if(!out||!sel||!ta||!csrf){return;}'
        . 'out.textContent="Running...";'
        . 'const fd=new FormData();'
        . 'fd.append("csrf_token",csrf.value||"");'
        . 'fd.append("action","rule_test");'
        . 'fd.append("policyholder_id",sel.value||"0");'
        . 'fd.append("definition_json",ta.value||"");'
        . 'try{'
        . 'const res=await fetch(window.location.href,{method:"POST",body:fd,headers:{"X-Requested-With":"fetch"}});'
        . 'const txt=await res.text();'
        . 'let j=null;try{j=JSON.parse(txt);}catch(e){}'
        . 'out.textContent=j?JSON.stringify(j,null,2):txt;'
        . '}catch(e){out.textContent=String(e);}'
        . '};'
        . '(function(){'
        . 'function status(msg,err){const el=document.getElementById("profileInlineStatus");if(el){el.textContent=msg;el.style.color=err?"#a33a2a":"";}}'
        . 'function money(v){const n=Number(v);return Number.isFinite(n)?"$"+n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}):"";}'
        . 'function derivedCell(id,field){return document.querySelector("[data-policy-id=\\"" + id + "\\"][data-derived-field=\\"" + field + "\\"]");}'
        . 'function updateDerived(id,data){if(!data||!data.derived){return;}const d=data.derived;const ltv=derivedCell(id,"ltv_estimate");if(ltv){ltv.textContent=money(d.ltv_estimate);}const tier=derivedCell(id,"risk_tier");if(tier){tier.textContent=d.risk_tier||"Unknown";}const conf=derivedCell(id,"confidence_score");if(conf){conf.textContent=d.confidence_score==null?"":String(d.confidence_score);}}'
        . 'async function save(input){const csrf=document.getElementById("profileInlineCsrf")||document.querySelector("input[name=csrf_token]");if(!csrf){return;}const id=input.dataset.policyId||"0";const field=input.dataset.field||"";const sig=id+"|"+field+"|"+(input.value||"");if(input.dataset.inlineSaveKey===sig||input.dataset.inlineSaving==="1"){return;}const fd=new FormData();fd.append("csrf_token",csrf.value||"");fd.append("action","policy_cell_update");fd.append("id",id);fd.append("field",field);fd.append("value",input.value||"");input.dataset.inlineSaving="1";input.disabled=true;status("Saving "+field+"...");try{const res=await fetch(window.location.href,{method:"POST",body:fd,headers:{"X-Requested-With":"fetch","Accept":"application/json"}});const data=await res.json();if(!data.ok){throw new Error(data.error||"Could not save cell.");}input.dataset.inlineSaveKey=sig;updateDerived(id,data);status("Saved "+field+". Computed cells refreshed.");}catch(e){status(e.message||String(e),true);}finally{input.disabled=false;delete input.dataset.inlineSaving;}}'
        . 'document.addEventListener("change",function(e){const input=e.target.closest("[data-policy-cell]");if(input){save(input);}});'
        . 'document.addEventListener("keydown",function(e){const input=e.target.closest("[data-policy-cell]");if(input&&e.key==="Enter"){e.preventDefault();save(input);input.blur();}});'
        . '})();'
        . '(function(){'
        . 'function qs(s,r){return(r||document).querySelector(s);}'
        . 'function qsa(s,r){return Array.from((r||document).querySelectorAll(s));}'
        . 'function ta(){return qs("textarea[name=definition_json]");}'
        . 'function safeJson(t){try{return JSON.parse(t||"{}");}catch(e){return null;}}'
        . 'function opts(id){const e=document.getElementById(id);return e?e.innerHTML:"";}'
        . 'function esc(v){return String(v==null?"":v).replace(/&/g,"&amp;").replace(/"/g,"&quot;").replace(/</g,"&lt;").replace(/>/g,"&gt;");}'
        . 'function iv(v){return Array.isArray(v)?v.join(", "):(v==null?"":String(v));}'
        . 'function pv(op,raw){const text=String(raw||"").trim();if(op==="in"){return text.split(",").map(function(p){return p.trim();}).filter(Boolean).map(function(p){const n=Number(p);return Number.isFinite(n)&&p!==""?n:p;});}if(/^(true|false)$/i.test(text)){return /^true$/i.test(text);}const n=Number(text.replace(/[$,]/g,""));return Number.isFinite(n)&&text!==""?n:text;}'
        . 'function setSel(s,v){if(!s){return;}const str=String(v||"");if(str&&!qsa("option",s).some(function(o){return o.value===str;})){const o=document.createElement("option");o.value=str;o.textContent=str;s.appendChild(o);}s.value=str;}'
        . 'function row(c){const w=document.createElement("div");w.className="ruleConditionRow";w.setAttribute("data-rule-condition-row","");w.innerHTML="<div><label class=\\"label\\">Field</label><select class=\\"select\\" data-rule-field>"+opts("ruleFieldOptions")+"</select></div><div><label class=\\"label\\">Operator</label><select class=\\"select\\" data-rule-op>"+opts("ruleOperatorOptions")+"</select></div><div><label class=\\"label\\">Value</label><input class=\\"input\\" data-rule-value value=\\""+esc(iv(c&&c.value))+"\\"></div><button class=\\"btnSecondary\\" type=\\"button\\" data-rule-remove-condition>Remove</button>";setSel(qs("[data-rule-field]",w),c&&c.field?c.field:"age");setSel(qs("[data-rule-op]",w),c&&c.op?c.op:">=");return w;}'
        . 'function defRule(){return{logic:"AND",conditions:[{field:"age",op:">=",value:60}],actions:{set_risk_tier:"High",score_delta:10}};}'
        . 'function render(){const t=ta();if(!t||!document.getElementById("ruleVisualPanel")){return;}const d=safeJson(t.value)||defRule();const logic=document.getElementById("ruleLogicVisual");if(logic){logic.value=String(d.logic||"AND").toUpperCase()==="OR"?"OR":"AND";}const box=document.getElementById("ruleConditionsVisual");if(box){box.innerHTML="";const cond=Array.isArray(d.conditions)?d.conditions:[];const leaf=cond.filter(function(x){return x&&x.field;});(leaf.length?leaf:defRule().conditions).forEach(function(c){box.appendChild(row(c));});const adv=document.getElementById("ruleAdvancedJsonNotice");if(adv){adv.checked=cond.some(function(x){return x&&!(x.field);});}}const a=d.actions&&typeof d.actions==="object"?d.actions:{};setSel(document.getElementById("ruleActionTier"),a.set_risk_tier||"");const score=document.getElementById("ruleActionScore");if(score){score.value=Number(a.score_delta||0);}const tag=document.getElementById("ruleActionTag");if(tag){tag.value=a.add_tag||"";}}'
        . 'function collect(){const t=ta();if(!t){return null;}const cond=qsa("[data-rule-condition-row]").map(function(r){const f=qs("[data-rule-field]",r).value;const o=qs("[data-rule-op]",r).value;const v=qs("[data-rule-value]",r).value;return{field:f,op:o,value:pv(o,v)};}).filter(function(x){return x.field&&x.op;});if(!cond.length){throw new Error("Add at least one condition.");}const actions={};const tier=document.getElementById("ruleActionTier");const score=document.getElementById("ruleActionScore");const tag=document.getElementById("ruleActionTag");if(tier&&tier.value){actions.set_risk_tier=tier.value;}if(score&&score.value!==""&&Number(score.value)!==0){actions.score_delta=Number(score.value);}if(tag&&tag.value.trim()!==""){actions.add_tag=tag.value.trim();}if(!Object.keys(actions).length){throw new Error("Add at least one action.");}const logic=document.getElementById("ruleLogicVisual");const d={logic:logic&&logic.value==="OR"?"OR":"AND",conditions:cond,actions:actions};t.value=JSON.stringify(d,null,2);return d;}'
        . 'function mode(m){const visual=m!=="json";if(!visual){try{collect();}catch(e){}}qsa("[data-rule-mode]").forEach(function(b){b.setAttribute("aria-selected",b.dataset.ruleMode===(visual?"visual":"json")?"true":"false");});const vp=document.getElementById("ruleVisualPanel");const jp=document.getElementById("ruleJsonPanel");if(vp){vp.hidden=!visual;}if(jp){jp.hidden=visual;}if(visual){render();}}'
        . 'function bind(){if(!document.getElementById("ruleVisualPanel")){return;}render();qsa("[data-rule-mode]").forEach(function(b){b.addEventListener("click",function(){mode(b.dataset.ruleMode);});});const add=document.getElementById("addRuleCondition");if(add){add.addEventListener("click",function(){const box=document.getElementById("ruleConditionsVisual");if(box){box.appendChild(row({field:"age",op:">=",value:60}));}});}const box=document.getElementById("ruleConditionsVisual");if(box){box.addEventListener("click",function(e){const b=e.target.closest("[data-rule-remove-condition]");if(!b){return;}if(qsa("[data-rule-condition-row]").length<=1){return;}b.closest("[data-rule-condition-row]").remove();});}const f=ta()?ta().closest("form"):null;if(f){f.addEventListener("submit",function(e){const vp=document.getElementById("ruleVisualPanel");if(vp&&!vp.hidden){try{collect();}catch(err){e.preventDefault();alert(err.message||String(err));}}});}}'
        . 'window.LiteInsurance.ruleTest=async function(){const out=document.getElementById("ruleTestOut");const sel=document.getElementById("ruleTestPolicy");const t=ta();const csrf=document.querySelector("input[name=csrf_token]");if(!out||!sel||!t||!csrf){return;}const vp=document.getElementById("ruleVisualPanel");if(vp&&!vp.hidden){try{collect();}catch(e){out.textContent=e.message||String(e);return;}}out.textContent="Running...";const fd=new FormData();fd.append("csrf_token",csrf.value||"");fd.append("action","rule_test");fd.append("policyholder_id",sel.value||"0");fd.append("definition_json",t.value||"");try{const res=await fetch(window.location.href,{method:"POST",body:fd,headers:{"X-Requested-With":"fetch"}});const txt=await res.text();let j=null;try{j=JSON.parse(txt);}catch(e){}out.textContent=j?JSON.stringify(j,null,2):txt;}catch(e){out.textContent=String(e);}};'
        . 'document.addEventListener("DOMContentLoaded",bind);'
        . '})();'
        . '</script>';
}

function cli_kv(array $argv): array
{
    $out = [];
    foreach ($argv as $i => $arg) {
        if ($i === 0) {
            continue;
        }
        $parts = explode('=', (string)$arg, 2);
        if (count($parts) === 2) {
            $out[$parts[0]] = $parts[1];
        }
    }
    return $out;
}

function safe_json(string $s)
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    try {
        return json_decode($s, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return null;
    }
}

function li_log(string $event, array $payload): void
{
    $line = json_encode(['ts' => now_iso(), 'event' => $event, 'payload' => $payload], JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        return;
    }
    @file_put_contents(ERROR_LOG_FILE, $line . "\n", FILE_APPEND);
}

function load_env_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $out = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $k = trim($parts[0]);
        $v = trim($parts[1]);
        if ($k === '') {
            continue;
        }
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
        }
        $out[$k] = $v;
    }
    return $out;
}

function cfg(string $key, string $default = ''): string
{
    $env = getenv($key);
    if (is_string($env) && $env !== '') {
        return $env;
    }
    $map = is_array($GLOBALS['LI_CFG'] ?? null) ? (array)$GLOBALS['LI_CFG'] : [];
    $v = $map[$key] ?? null;
    return is_string($v) && $v !== '' ? $v : $default;
}

function db_path(): string
{
    return cfg('DB_PATH', DB_PATH);
}

function upload_dir(): string
{
    return cfg('UPLOAD_DIR', UPLOAD_DIR);
}

function send_security_headers(string $nonce): void
{
    /*
    Customize CSP allowlists here if you add external CDNs.
    - The default policy permits only self + Google Fonts (Inter), and uses a nonce for inline <style>/<script>.
    */
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    $nonce = preg_replace('/[^a-zA-Z0-9+\/=]/', '', $nonce);
    $csp = "default-src 'self'; "
        . "base-uri 'self'; "
        . "connect-src 'self' https://fonts.googleapis.com https://fonts.gstatic.com; "
        . "object-src 'none'; "
        . "frame-ancestors 'none'; "
        . "form-action 'self'; "
        . "img-src 'self' data:; "
        . "font-src 'self' https://fonts.gstatic.com data:; "
        . "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com; "
        . "script-src 'self' 'nonce-{$nonce}';";
    header('Content-Security-Policy: ' . $csp);
}

function csrf_fail(): void
{
    http_response_code(403);
    if (is_fetch_request()) {
        echo 'Forbidden';
        exit;
    }
    flash('error', 'Invalid CSRF token. Refresh and try again.');
    redirect_self();
}

function is_fetch_request(): bool
{
    $xrw = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return strtolower($xrw) === 'fetch' || str_contains(strtolower($accept), 'application/json');
}

function rate_limit_allow(PDO $pdo, string $action, string $key, int $maxHits, int $windowSeconds): bool
{
    $key = trim($key);
    if ($key === '' || $maxHits <= 0 || $windowSeconds <= 0) {
        return true;
    }
    $bucket = $action . '|' . $key;
    $now = time();
    $pdo->prepare('DELETE FROM rate_limits WHERE reset_at < ?')->execute([$now - 3600]);

    $st = $pdo->prepare('SELECT hits, reset_at FROM rate_limits WHERE bucket = ?');
    $st->execute([$bucket]);
    $row = $st->fetch();
    if (!$row) {
        $pdo->prepare('INSERT INTO rate_limits (bucket, hits, reset_at) VALUES (?, 1, ?)')->execute([$bucket, $now + $windowSeconds]);
        return true;
    }
    $hits = (int)($row['hits'] ?? 0);
    $resetAt = (int)($row['reset_at'] ?? 0);
    if ($resetAt <= $now) {
        $pdo->prepare('UPDATE rate_limits SET hits = 1, reset_at = ? WHERE bucket = ?')->execute([$now + $windowSeconds, $bucket]);
        return true;
    }
    if ($hits >= $maxHits) {
        return false;
    }
    $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?')->execute([$bucket]);
    return true;
}

function sanitize_filename(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[^\w.\-]+/u', '_', $name);
    $name = preg_replace('/_+/', '_', (string)$name);
    $name = ltrim((string)$name, '._');
    if ($name === '') {
        return 'upload.csv';
    }
    if (strlen($name) > 120) {
        $name = substr($name, -120);
    }
    return $name;
}

function detect_mime(string $path): string
{
    if ($path === '' || !is_file($path)) {
        return '';
    }
    if (!class_exists('finfo')) {
        return '';
    }
    try {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $m = $fi->file($path);
        return is_string($m) ? $m : '';
    } catch (Throwable $e) {
        return '';
    }
}

function tokenize_filter_expr(string $expr): array
{
    $pattern = "/\\G\\s*(?:(?<lp>\\()|(?<rp>\\))|(?<comma>,)|(?<op>>=|<=|!=|=|>|<)|(?<kw>AND|OR|NOT|LIKE|IN|IS|NULL)\\b|(?<num>-?\\d+(?:\\.\\d+)?)|(?<str>'(?:''|[^'])*')|(?<id>[A-Za-z_][A-Za-z0-9_]*(?:\\.[A-Za-z_][A-Za-z0-9_]*)?))\\s*/Ai";
    $tokens = [];
    $pos = 0;
    $len = strlen($expr);
    while ($pos < $len) {
        if (!preg_match($pattern, $expr, $m, 0, $pos)) {
            throw new RuntimeException('Invalid characters in filter.');
        }
        $pos += strlen($m[0]);
        if (!empty($m['lp'])) {
            $tokens[] = ['type' => 'LPAREN', 'value' => '('];
        } elseif (!empty($m['rp'])) {
            $tokens[] = ['type' => 'RPAREN', 'value' => ')'];
        } elseif (!empty($m['comma'])) {
            $tokens[] = ['type' => 'COMMA', 'value' => ','];
        } elseif (!empty($m['op'])) {
            $tokens[] = ['type' => 'OP', 'value' => $m['op']];
        } elseif (!empty($m['kw'])) {
            $tokens[] = ['type' => 'KW', 'value' => strtoupper($m['kw'])];
        } elseif (!empty($m['num'])) {
            $raw = $m['num'];
            $parsed = str_contains($raw, '.') ? (float)$raw : (int)$raw;
            $tokens[] = ['type' => 'NUMBER', 'value' => $raw, 'parsed' => $parsed];
        } elseif (!empty($m['str'])) {
            $raw = $m['str'];
            $inner = substr($raw, 1, -1);
            $inner = str_replace("''", "'", $inner);
            $tokens[] = ['type' => 'STRING', 'value' => $raw, 'parsed' => $inner];
        } elseif (!empty($m['id'])) {
            $tokens[] = ['type' => 'IDENT', 'value' => strtolower($m['id'])];
        } else {
            throw new RuntimeException('Invalid token.');
        }
    }
    return $tokens;
}

function ensure_upload_dir(): bool
{
    $dir = upload_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        $idx = $dir . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Options -Indexes\nDeny from all\n");
        }
        if (!is_file($idx)) {
            @file_put_contents($idx, '<!doctype html><title>Not found</title>');
        }
    }
    return is_dir($dir) && is_writable($dir);
}

function mask_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $parts = preg_split('/\s+/', $name);
    if (!$parts) {
        return $name;
    }
    $first = $parts[0];
    $last = count($parts) > 1 ? $parts[count($parts) - 1] : '';
    $mask = function (string $s): string {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        $c = mb_substr($s, 0, 1);
        return $c . str_repeat('•', max(1, mb_strlen($s) - 1));
    };
    return $mask($first) . ($last !== '' ? (' ' . $mask($last)) : '');
}

function render_import_mapping(PDO $pdo, array $stage): void
{
    $headers = array_values(array_filter(array_map('trim', (array)$stage['headers']), fn($x) => $x !== ''));
    $filename = (string)($stage['filename'] ?? 'upload.csv');
    $defaults = [
        'record_id' => guess_header($headers, ['policy_number', 'policy_id', 'policy', 'policy no', 'policy#', 'customer_id', 'client_id', 'account_id', 'member_id', 'certificate_number', 'id']),
        'display_name' => guess_header($headers, ['name', 'full name', 'customer', 'customer_name', 'client_name', 'insured_name', 'account_label', 'label']),
        'dob' => guess_header($headers, ['dob', 'date_of_birth', 'birthdate']),
        'age' => guess_header($headers, ['age']),
        'gender' => guess_header($headers, ['gender', 'sex']),
        'policy_type' => guess_header($headers, ['policy_type', 'product', 'type']),
        'region' => guess_header($headers, ['region', 'state', 'area']),
        'premium_amount' => guess_header($headers, ['premium_amount', 'premium', 'monthly_premium']),
        'tenure_months' => guess_header($headers, ['tenure_months', 'tenure', 'months']),
        'sum_assured' => guess_header($headers, ['sum_assured', 'coverage', 'sum']),
    ];
    $fields = [
        'record_id' => 'Record ID (required)',
        'display_name' => 'Display name',
        'dob' => 'DOB (YYYY-MM-DD)',
        'age' => 'Age',
        'gender' => 'Gender',
        'policy_type' => 'Policy type',
        'region' => 'Region',
        'premium_amount' => 'Premium amount',
        'tenure_months' => 'Tenure (months)',
        'sum_assured' => 'Sum assured',
    ];

    echo '<div class="card"><div class="h2">Map columns</div><div class="muted">Staged file: <strong>' . h($filename) . '</strong>. Map the fields you have; unmapped CSV columns are preserved as typed custom fields in <code>metadata.custom_fields</code>.</div>';
    echo '<form method="post" class="form">';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="import_preview">';
    echo '<div class="gridMap">';
    foreach ($fields as $key => $label) {
        echo '<div class="mapRow"><div class="label">' . h($label) . '</div><select class="select" name="map[' . h($key) . ']">';
        echo '<option value="">' . h($key === 'display_name' ? 'Default from Record ID' : 'Not provided') . '</option>';
        if ($key === 'record_id') {
            echo '<option value="__generate__"' . ($defaults[$key] === '' ? ' selected' : '') . '>Generate from row number</option>';
        }
        foreach ($headers as $hname) {
            $sel = ($defaults[$key] === $hname) ? ' selected' : '';
            echo '<option value="' . h($hname) . '"' . $sel . '>' . h($hname) . '</option>';
        }
        echo '</select></div>';
    }
    echo '</div>';
    echo '<div class="grid2"><div><label class="label">Default region</label><input class="input" name="defaults[region]" maxlength="80" value="' . h((string)($stage['defaults']['region'] ?? 'Unknown')) . '"></div>';
    echo '<div><label class="label">Default policy type</label><input class="input" name="defaults[policy_type]" maxlength="80" value="' . h((string)($stage['defaults']['policy_type'] ?? 'term')) . '"></div></div>';
    echo '<button class="btn" type="submit">Preview import</button></form></div>';

    if (isset($stage['preview']) && is_array($stage['preview'])) {
        $stats = (array)($stage['preview']['stats'] ?? []);
        $rows = (array)($stage['preview']['rows'] ?? []);
        echo '<div class="card"><div class="h2">Preview</div><div class="muted">First ' . h((string)IMPORT_PREVIEW_ROWS) . ' rows after imputation.</div>';
        echo '<div class="kpiGrid">' . kpi('Rows detected', (string)($stats['rows_total'] ?? 0)) . kpi('Hard rejects (preview)', (string)($stats['hard_rejects_preview'] ?? 0)) . kpi('Custom fields', (string)($stats['custom_fields'] ?? 0)) . kpi('Mean premium', CURRENCY_SYMBOL . number_format((float)($stats['mean_premium'] ?? 0), 2)) . '</div>';
        $cols = ['policy_number', 'name', 'age', 'gender', 'policy_type', 'region', 'premium_amount', 'tenure_months', 'sum_assured', 'ltv_estimate', 'risk_tier', 'confidence_score', 'is_imputed'];
        $customCols = array_values(array_filter(array_slice(array_map(fn($d) => (string)($d['field_key'] ?? ''), (array)($stage['preview']['analysis']['custom_fields'] ?? [])), 0, 4)));
        echo '<div class="scrollX"><table class="table"><thead><tr>';
        foreach ($cols as $c) {
            echo '<th>' . h($c) . '</th>';
        }
        foreach ($customCols as $c) {
            echo '<th>' . h('custom.' . $c) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $low = (int)($r['confidence_score'] ?? 0) < 60;
            echo '<tr class="' . ($low ? 'rowLow' : '') . '">';
            foreach ($cols as $c) {
                $v = $r[$c] ?? '';
                if (in_array($c, ['premium_amount', 'sum_assured', 'ltv_estimate'], true)) {
                    $v = ($v === '' || $v === null) ? '' : CURRENCY_SYMBOL . number_format((float)$v, 2);
                }
                echo '<td>' . h((string)$v) . '</td>';
            }
            $custom = is_array($r['custom_fields'] ?? null) ? (array)$r['custom_fields'] : [];
            foreach ($customCols as $c) {
                $v = $custom[$c] ?? '';
                echo '<td>' . h(is_bool($v) ? ($v ? 'true' : 'false') : (string)$v) . '</td>';
            }
            echo '</tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="' . h((string)(count($cols) + count($customCols))) . '" class="muted">No rows to preview.</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<form method="post" class="inline"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="import_commit"><button class="btn" type="submit">Confirm & import</button></form>';
        echo '</div>';
    }
}

function guess_header(array $headers, array $needles): string
{
    $norm = [];
    foreach ($headers as $h) {
        $norm[strtolower(preg_replace('/\s+/', ' ', trim((string)$h)))] = (string)$h;
    }
    foreach ($needles as $n) {
        $k = strtolower(preg_replace('/\s+/', ' ', trim((string)$n)));
        if (isset($norm[$k])) {
            return $norm[$k];
        }
    }
    foreach ($needles as $n) {
        $needle = strtolower((string)$n);
        foreach ($norm as $k => $orig) {
            if (strpos($k, $needle) !== false) {
                return $orig;
            }
        }
    }
    return '';
}

function header_index_map(array $headers): array
{
    $map = [];
    foreach ($headers as $i => $h) {
        $k = trim((string)$h);
        if ($k !== '') {
            $map[$k] = (int)$i;
        }
    }
    return $map;
}

function core_import_field_keys(): array
{
    return ['record_id', 'display_name', 'dob', 'age', 'gender', 'policy_type', 'region', 'premium_amount', 'tenure_months', 'sum_assured'];
}

function storage_field_for_import(string $field): string
{
    if ($field === 'record_id') {
        return 'policy_number';
    }
    if ($field === 'display_name') {
        return 'name';
    }
    return $field;
}

function field_was_mapped(array $analysis, string $field): bool
{
    $mapped = is_array($analysis['mapped_fields'] ?? null) ? (array)$analysis['mapped_fields'] : [];
    return !empty($mapped[$field]);
}

function custom_field_key(string $header, array &$used = []): string
{
    $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $header) ?? ''));
    $base = trim($base, '_');
    if ($base === '') {
        $base = 'custom_field';
    }
    $reserved = ['id', 'custom', 'metadata', 'policy_number', 'name', 'record_id', 'display_name', 'dob', 'age', 'gender', 'policy_type', 'region', 'premium_amount', 'tenure_months', 'sum_assured', 'ltv_estimate', 'risk_tier', 'confidence_score', 'is_imputed'];
    if (in_array($base, $reserved, true)) {
        $base = 'csv_' . $base;
    }
    $key = $base;
    $i = 2;
    while (isset($used[$key])) {
        $key = $base . '_' . $i;
        $i++;
    }
    $used[$key] = true;
    return $key;
}

function infer_custom_value_type(string $raw): ?string
{
    $v = trim($raw);
    if ($v === '') {
        return null;
    }
    $lower = strtolower($v);
    if (in_array($lower, ['true', 'false', 'yes', 'no'], true)) {
        return 'bool';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return 'date';
    }
    if (parse_number($v) !== null) {
        return 'number';
    }
    return 'text';
}

function merge_custom_type(?string $current, ?string $next): ?string
{
    if ($next === null) {
        return $current;
    }
    if ($current === null || $current === $next) {
        return $next;
    }
    return 'text';
}

function parse_custom_value(string $raw, string $type)
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if ($type === 'number') {
        return parse_number($raw);
    }
    if ($type === 'bool') {
        $v = strtolower($raw);
        if (in_array($v, ['true', 'yes', '1'], true)) {
            return true;
        }
        if (in_array($v, ['false', 'no', '0'], true)) {
            return false;
        }
    }
    return $raw;
}

function mapped_header_names(array $mapping): array
{
    $out = [];
    foreach ($mapping as $field => $hname) {
        $field = (string)$field;
        $hname = (string)$hname;
        if (!in_array($field, core_import_field_keys(), true) || $hname === '' || $hname === '__generate__') {
            continue;
        }
        $out[$hname] = true;
    }
    return $out;
}

function custom_field_definitions(array $headers, array $mapping, array $types = []): array
{
    $mapped = mapped_header_names($mapping);
    $used = [];
    $defs = [];
    foreach ($headers as $header) {
        $label = trim((string)$header);
        if ($label === '' || isset($mapped[$label])) {
            continue;
        }
        $key = custom_field_key($label, $used);
        $defs[] = [
            'field_key' => $key,
            'label' => $label,
            'source_header' => $label,
            'field_type' => in_array(($types[$label] ?? 'text'), ['text', 'number', 'date', 'bool'], true) ? (string)($types[$label] ?? 'text') : 'text',
        ];
    }
    return $defs;
}

function extract_custom_fields(array $headers, array $row, array $customDefs): array
{
    $idx = header_index_map($headers);
    $out = [];
    foreach ($customDefs as $def) {
        $header = (string)($def['source_header'] ?? '');
        $key = (string)($def['field_key'] ?? '');
        if ($header === '' || $key === '' || !isset($idx[$header])) {
            continue;
        }
        $raw = (string)($row[(int)$idx[$header]] ?? '');
        if (trim($raw) === '') {
            continue;
        }
        $out[$key] = parse_custom_value($raw, (string)($def['field_type'] ?? 'text'));
    }
    return $out;
}

function upsert_custom_field_defs(PDO $pdo, array $defs): void
{
    if (!$defs) {
        return;
    }
    $st = $pdo->prepare('INSERT INTO custom_field_defs (field_key, label, source_header, field_type, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?) ON CONFLICT(field_key) DO UPDATE SET label = excluded.label, source_header = excluded.source_header, field_type = excluded.field_type, enabled = 1, updated_at = excluded.updated_at');
    $now = now_iso();
    foreach ($defs as $def) {
        $st->execute([(string)$def['field_key'], (string)$def['label'], (string)$def['source_header'], (string)$def['field_type'], $now, $now]);
    }
}

function map_row(array $headers, array $row, array $mapping): array
{
    $idx = header_index_map($headers);
    $out = [];
    foreach ($mapping as $field => $hname) {
        $hname = (string)$hname;
        $storeField = storage_field_for_import((string)$field);
        if ($hname === '__generate__') {
            continue;
        }
        if ($hname === '' || !isset($idx[$hname])) {
            continue;
        }
        $pos = (int)$idx[$hname];
        $out[$storeField] = $row[$pos] ?? '';
    }
    return $out;
}

function parse_number(string $s): ?float
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    $s = str_replace([',', CURRENCY_SYMBOL], ['', ''], $s);
    if (!is_numeric($s)) {
        return null;
    }
    return (float)$s;
}

function parse_int(string $s): ?int
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    if (!preg_match('/^-?\d+$/', $s)) {
        return null;
    }
    return (int)$s;
}

function normalize_gender(string $s): string
{
    $s = strtolower(trim($s));
    if ($s === 'm' || $s === 'male') {
        return 'M';
    }
    if ($s === 'f' || $s === 'female') {
        return 'F';
    }
    if ($s === 'other' || $s === 'x') {
        return 'X';
    }
    return '';
}

function age_from_dob(string $dob): ?int
{
    $dob = trim($dob);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        return null;
    }
    try {
        $d = new DateTimeImmutable($dob);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $diff = $now->diff($d);
        if ($diff->invert !== 1) {
            return null;
        }
        return (int)$diff->y;
    } catch (Throwable $e) {
        return null;
    }
}

function mode_from_freq(array $freq): string
{
    $bestK = '';
    $bestV = 0;
    foreach ($freq as $k => $v) {
        if ((int)$v > $bestV) {
            $bestV = (int)$v;
            $bestK = (string)$k;
        }
    }
    return $bestK;
}

function validate_policyholder_hard(array $r): ?string
{
    if ((string)($r['policy_number'] ?? '') === '') {
        return 'Missing Record ID';
    }
    return null;
}

function validate_policyholder_soft_count(array $r): int
{
    $c = 0;
    foreach (['policy_type', 'region', 'premium_amount', 'tenure_months'] as $k) {
        $v = $r[$k] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            $c++;
        }
    }
    if (($r['age'] ?? null) === null && (string)($r['dob'] ?? '') === '') {
        $c++;
    }
    return $c;
}

function compute_confidence(array $r, array $imputedFields): int
{
    $base = 100;
    $base -= min(45, count($imputedFields) * 10);
    $missingOptional = 0;
    foreach (['age', 'gender', 'sum_assured'] as $k) {
        $v = $r[$k] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            $missingOptional++;
        }
    }
    $base -= $missingOptional * 5;
    return max(0, min(100, $base));
}

function compute_ltv(array $r): ?float
{
    if (($r['premium_amount'] ?? null) === null || ($r['tenure_months'] ?? null) === null || trim((string)($r['policy_type'] ?? '')) === '') {
        return null;
    }
    $premium = (float)($r['premium_amount'] ?? 0);
    $tenure = (int)($r['tenure_months'] ?? 0);
    $remaining = max(0, EXPECTED_POLICY_TERM_MONTHS_DEFAULT - $tenure);
    $ptype = strtolower(trim((string)($r['policy_type'] ?? '')));
    $mult = (float)(CROSS_SELL_MULTIPLIER_BY_POLICY_TYPE[$ptype] ?? DEFAULT_CROSS_SELL_MULTIPLIER);
    $ret = (float)RETENTION_ADJUSTMENT_DEFAULT;
    return round($premium * $remaining * $mult * $ret, 2);
}

function analyze_csv(string $path, array $headers, array $mapping): array
{
    $numFields = ['premium_amount', 'tenure_months', 'sum_assured', 'age'];
    $catFields = ['policy_type', 'region', 'gender'];
    $sums = [];
    $counts = [];
    $freq = [];
    foreach ($numFields as $f) {
        $sums[$f] = 0.0;
        $counts[$f] = 0;
    }
    foreach ($catFields as $f) {
        $freq[$f] = [];
    }
    $mappedFields = [];
    foreach ($mapping as $field => $hname) {
        $storeField = storage_field_for_import((string)$field);
        if ((string)$hname !== '') {
            $mappedFields[$storeField] = true;
        }
    }
    $customTypeByHeader = [];
    $customDefsForScan = custom_field_definitions($headers, $mapping);
    $headerIndex = header_index_map($headers);
    $rowsTotal = 0;
    $fh = fopen($path, 'rb');
    if (!$fh) {
        return ['rows_total' => 0, 'mapped_fields' => $mappedFields, 'custom_fields' => []];
    }
    fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        $rowsTotal++;
        $rec = map_row($headers, $row, $mapping);
        foreach ($numFields as $f) {
            if (!isset($mapping[$f]) || (string)$mapping[$f] === '') {
                continue;
            }
            $v = parse_number((string)($rec[$f] ?? ''));
            if ($v !== null) {
                $sums[$f] += (float)$v;
                $counts[$f] += 1;
            }
        }
        foreach ($catFields as $f) {
            if (!isset($mapping[$f]) || (string)$mapping[$f] === '') {
                continue;
            }
            $v = trim((string)($rec[$f] ?? ''));
            if ($v !== '') {
                $k = strtolower($v);
                $freq[$f][$k] = ($freq[$f][$k] ?? 0) + 1;
            }
        }
        foreach ($customDefsForScan as $def) {
            $header = (string)($def['source_header'] ?? '');
            if ($header === '' || !isset($headerIndex[$header])) {
                continue;
            }
            $raw = (string)($row[(int)$headerIndex[$header]] ?? '');
            $customTypeByHeader[$header] = merge_custom_type($customTypeByHeader[$header] ?? null, infer_custom_value_type($raw));
        }
    }
    fclose($fh);
    $means = [];
    foreach ($numFields as $f) {
        $means[$f] = $counts[$f] > 0 ? ($sums[$f] / $counts[$f]) : 0.0;
    }
    $modes = [];
    foreach ($catFields as $f) {
        $modes[$f] = mode_from_freq($freq[$f]);
    }
    $customFields = custom_field_definitions($headers, $mapping, $customTypeByHeader);
    return ['rows_total' => $rowsTotal, 'means' => $means, 'modes' => $modes, 'mean_premium' => $means['premium_amount'] ?? 0, 'mapped_fields' => $mappedFields, 'custom_fields' => $customFields];
}

function normalize_and_impute(array $rec, array $defaults, array $analysis, array &$imputedFields, int $rowNumber = 0, string $filename = ''): array
{
    $out = [];
    $out['policy_number'] = trim((string)($rec['policy_number'] ?? ''));
    if ($out['policy_number'] === '') {
        $seed = substr(sha1($filename !== '' ? $filename : 'import'), 0, 6);
        $out['policy_number'] = 'AUTO-' . strtoupper($seed) . '-' . str_pad((string)max(1, $rowNumber), 6, '0', STR_PAD_LEFT);
        $imputedFields[] = 'record_id';
    }
    $out['name'] = trim((string)($rec['name'] ?? ''));
    if ($out['name'] === '') {
        $out['name'] = $out['policy_number'];
        $imputedFields[] = 'display_name';
    }
    $out['dob'] = trim((string)($rec['dob'] ?? ''));
    $out['gender'] = normalize_gender(trim((string)($rec['gender'] ?? '')));
    $out['age'] = parse_int((string)($rec['age'] ?? ''));
    if ($out['age'] === null && $out['dob'] !== '') {
        $age = age_from_dob($out['dob']);
        if ($age !== null) {
            $out['age'] = $age;
            $imputedFields[] = 'age';
        }
    }
    $out['premium_amount'] = parse_number((string)($rec['premium_amount'] ?? ''));
    $out['tenure_months'] = parse_int((string)($rec['tenure_months'] ?? ''));
    $out['sum_assured'] = parse_number((string)($rec['sum_assured'] ?? ''));
    $out['policy_type'] = trim((string)($rec['policy_type'] ?? ''));
    $out['region'] = trim((string)($rec['region'] ?? ''));

    if ($out['premium_amount'] === null && field_was_mapped($analysis, 'premium_amount')) {
        $out['premium_amount'] = (float)($analysis['means']['premium_amount'] ?? 0);
        $imputedFields[] = 'premium_amount';
    }
    if ($out['tenure_months'] === null && field_was_mapped($analysis, 'tenure_months')) {
        $out['tenure_months'] = (int)round((float)($analysis['means']['tenure_months'] ?? 0));
        $imputedFields[] = 'tenure_months';
    }
    if ($out['sum_assured'] === null && field_was_mapped($analysis, 'sum_assured') && (float)($analysis['means']['sum_assured'] ?? 0) > 0) {
        $out['sum_assured'] = (float)($analysis['means']['sum_assured'] ?? 0);
        $imputedFields[] = 'sum_assured';
    }
    if ($out['policy_type'] === '' && field_was_mapped($analysis, 'policy_type')) {
        $mode = (string)($analysis['modes']['policy_type'] ?? '');
        $out['policy_type'] = $mode !== '' ? $mode : (string)($defaults['policy_type'] ?? 'term');
        $imputedFields[] = 'policy_type';
    }
    if ($out['region'] === '' && field_was_mapped($analysis, 'region')) {
        $mode = (string)($analysis['modes']['region'] ?? '');
        $out['region'] = $mode !== '' ? $mode : (string)($defaults['region'] ?? 'Unknown');
        $imputedFields[] = 'region';
    }
    if ($out['gender'] === '' && field_was_mapped($analysis, 'gender')) {
        $mode = (string)($analysis['modes']['gender'] ?? '');
        if ($mode !== '') {
            $out['gender'] = normalize_gender($mode);
            $imputedFields[] = 'gender';
        }
    }
    return $out;
}

function preview_csv(string $path, array $headers, array $mapping, array $defaults, array $analysis, PDO $pdo): array
{
    $rules = load_rules($pdo);
    $fh = fopen($path, 'rb');
    if (!$fh) {
        return ['analysis' => $analysis, 'stats' => ['rows_total' => 0], 'rows' => []];
    }
    fgetcsv($fh);
    $rows = [];
    $soft = 0;
    $hard = 0;
    $rowNumber = 0;
    $customDefs = is_array($analysis['custom_fields'] ?? null) ? (array)$analysis['custom_fields'] : [];
    while (($row = fgetcsv($fh)) !== false) {
        $rowNumber++;
        $rec = map_row($headers, $row, $mapping);
        $imputed = [];
        $validated = normalize_and_impute($rec, $defaults, $analysis, $imputed, $rowNumber, basename($path));
        $customFields = extract_custom_fields($headers, $row, $customDefs);
        $validated['custom'] = $customFields;
        $hardMsg = validate_policyholder_hard($validated);
        if ($hardMsg !== null) {
            $hard++;
            continue;
        }
        $soft += validate_policyholder_soft_count($validated);
        $score = compute_confidence($validated, $imputed);
        $riskOut = evaluate_rules_for_record($validated, $rules);
        $ruleHits = (array)($riskOut['rule_hits'] ?? []);
        $score = min(100, (int)round($score + min(20, count($ruleHits) * 4)));
        $ltv = compute_ltv($validated);
        $rows[] = [
            'policy_number' => (string)$validated['policy_number'],
            'name' => mask_name((string)$validated['name']),
            'age' => (string)($validated['age'] ?? ''),
            'gender' => (string)($validated['gender'] ?? ''),
            'policy_type' => (string)($validated['policy_type'] ?? ''),
            'region' => (string)($validated['region'] ?? ''),
            'premium_amount' => $validated['premium_amount'],
            'tenure_months' => $validated['tenure_months'],
            'sum_assured' => $validated['sum_assured'],
            'ltv_estimate' => $ltv,
            'risk_tier' => (string)($riskOut['risk_tier'] ?? 'Unknown'),
            'confidence_score' => $score,
            'is_imputed' => count($imputed) > 0 ? 1 : 0,
            'custom_fields' => $customFields,
        ];
        if (count($rows) >= IMPORT_PREVIEW_ROWS) {
            break;
        }
    }
    fclose($fh);
    return ['analysis' => $analysis, 'stats' => ['rows_total' => (int)($analysis['rows_total'] ?? 0), 'hard_rejects_preview' => $hard, 'soft_warnings_preview' => $soft, 'mean_premium' => (float)($analysis['mean_premium'] ?? 0), 'custom_fields' => count($customDefs)], 'rows' => $rows];
}

function policy_number_exists(PDO $pdo, string $policyNumber): bool
{
    $st = $pdo->prepare('SELECT 1 FROM policyholders WHERE policy_number = ? LIMIT 1');
    $st->execute([$policyNumber]);
    return (bool)$st->fetchColumn();
}

function policyholder_record_from_row(array $ph): array
{
    $meta = safe_json((string)($ph['metadata'] ?? '{}'));
    if (!is_array($meta)) {
        $meta = [];
    }
    $customFields = is_array($meta['custom_fields'] ?? null) ? (array)$meta['custom_fields'] : [];
    return [
        'policy_number' => (string)($ph['policy_number'] ?? ''),
        'name' => (string)($ph['name'] ?? ''),
        'dob' => (string)($ph['dob'] ?? ''),
        'age' => ($ph['age'] !== null) ? (int)$ph['age'] : null,
        'gender' => (string)($ph['gender'] ?? ''),
        'policy_type' => (string)($ph['policy_type'] ?? ''),
        'region' => (string)($ph['region'] ?? ''),
        'premium_amount' => ($ph['premium_amount'] !== null) ? (float)$ph['premium_amount'] : null,
        'tenure_months' => ($ph['tenure_months'] !== null) ? (int)$ph['tenure_months'] : null,
        'sum_assured' => ($ph['sum_assured'] !== null) ? (float)$ph['sum_assured'] : null,
        'ltv_estimate' => ($ph['ltv_estimate'] !== null) ? (float)$ph['ltv_estimate'] : null,
        'risk_tier' => (string)($ph['risk_tier'] ?? ''),
        'confidence_score' => (int)($ph['confidence_score'] ?? 0),
        'custom' => $customFields,
    ];
}

function load_rules(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, conditions_json, actions_json, priority FROM rules WHERE enabled = 1 ORDER BY priority ASC, id ASC')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int)$r['id'], 'priority' => (int)$r['priority'], 'conditions' => safe_json((string)$r['conditions_json']), 'actions' => safe_json((string)$r['actions_json'])];
    }
    return $out;
}

function evaluate_rules_for_record(array $record, array $rules): array
{
    $riskTier = 'Unknown';
    $tags = [];
    $hits = [];
    $scoreDelta = 0;
    foreach ($rules as $rule) {
        $conds = $rule['conditions'] ?? null;
        $actions = $rule['actions'] ?? null;
        if (!is_array($conds) || !is_array($actions)) {
            continue;
        }
        if (!eval_conditions($record, $conds)) {
            continue;
        }
        $hits[] = (int)($rule['id'] ?? 0);
        if (isset($actions['set_risk_tier']) && is_string($actions['set_risk_tier'])) {
            $riskTier = (string)$actions['set_risk_tier'];
        }
        if (isset($actions['score_delta'])) {
            $scoreDelta += (int)$actions['score_delta'];
        }
        if (isset($actions['add_tag']) && is_string($actions['add_tag']) && $actions['add_tag'] !== '') {
            $tags[] = (string)$actions['add_tag'];
        }
    }
    $tags = array_values(array_unique($tags));
    return ['risk_tier' => $riskTier, 'score_delta' => $scoreDelta, 'tags' => $tags, 'rule_hits' => $hits];
}

function eval_conditions(array $record, array $conds): bool
{
    $logic = 'AND';
    if (isset($conds['logic']) && is_string($conds['logic'])) {
        $logic = strtoupper($conds['logic']);
    }
    $items = $conds;
    if (isset($conds['conditions']) && is_array($conds['conditions'])) {
        $items = $conds['conditions'];
    }
    if (!is_array($items)) {
        return false;
    }
    $results = [];
    foreach ($items as $c) {
        if (is_array($c) && (isset($c['all']) || isset($c['any']))) {
            if (isset($c['all']) && is_array($c['all'])) {
                $results[] = eval_conditions($record, ['logic' => 'AND', 'conditions' => $c['all']]);
                continue;
            }
            if (isset($c['any']) && is_array($c['any'])) {
                $results[] = eval_conditions($record, ['logic' => 'OR', 'conditions' => $c['any']]);
                continue;
            }
        }
        $results[] = eval_condition_leaf($record, is_array($c) ? $c : []);
    }
    if ($logic === 'OR') {
        foreach ($results as $r) {
            if ($r) {
                return true;
            }
        }
        return false;
    }
    foreach ($results as $r) {
        if (!$r) {
            return false;
        }
    }
    return true;
}

function eval_condition_leaf(array $record, array $c): bool
{
    $field = (string)($c['field'] ?? '');
    $op = strtolower(trim((string)($c['op'] ?? '')));
    $value = $c['value'] ?? null;
    if ($field === '' || $op === '') {
        return false;
    }
    $left = value_for_rule_field($record, $field);
    $leftStr = is_string($left) ? trim($left) : $left;
    if ($op === '=' || $op === '==') {
        return compare_scalar($leftStr, $value) === 0;
    }
    if ($op === '!=') {
        return compare_scalar($leftStr, $value) !== 0;
    }
    if ($op === '>') {
        return (float)$leftStr > (float)$value;
    }
    if ($op === '<') {
        return (float)$leftStr < (float)$value;
    }
    if ($op === '>=') {
        return (float)$leftStr >= (float)$value;
    }
    if ($op === '<=') {
        return (float)$leftStr <= (float)$value;
    }
    if ($op === 'in') {
        if (!is_array($value)) {
            return false;
        }
        $needle = strtolower((string)$leftStr);
        foreach ($value as $v) {
            if (strtolower((string)$v) === $needle) {
                return true;
            }
        }
        return false;
    }
    if ($op === 'contains') {
        return is_string($leftStr) && is_string($value) && stripos($leftStr, $value) !== false;
    }
    if ($op === 'regex') {
        if (!is_string($leftStr) || !is_string($value) || $value === '') {
            return false;
        }
        $pattern = $value;
        if (@preg_match($pattern, '') === false) {
            $pattern = '/' . str_replace('/', '\\/', $pattern) . '/i';
        }
        return @preg_match($pattern, $leftStr) === 1;
    }
    return false;
}

function value_for_rule_field(array $record, string $field)
{
    $field = trim($field);
    if (str_starts_with($field, 'custom.')) {
        $key = substr($field, 7);
        $custom = is_array($record['custom'] ?? null) ? (array)$record['custom'] : [];
        return $custom[$key] ?? null;
    }
    return $record[$field] ?? null;
}

function compare_scalar($a, $b): int
{
    if (is_numeric($a) && is_numeric($b)) {
        return ((float)$a) <=> ((float)$b);
    }
    return strcmp(strtolower((string)$a), strtolower((string)$b));
}

function handle_load_sample(PDO $pdo, array $user): void
{
    $hasRules = (int)$pdo->query('SELECT COUNT(*) FROM rules')->fetchColumn() > 0;
    $rulesSeeded = false;
    if (!$hasRules) {
        foreach (sample_rules_seed() as $r) {
            $def = (array)$r['definition'];
            $condsPayload = ['logic' => (string)($def['logic'] ?? 'AND'), 'conditions' => (array)($def['conditions'] ?? [])];
            $condsJson = json_encode($condsPayload, JSON_UNESCAPED_SLASHES);
            $actsJson = json_encode((array)($def['actions'] ?? []), JSON_UNESCAPED_SLASHES);
            $st = $pdo->prepare('INSERT INTO rules (name, conditions_json, actions_json, priority, enabled, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $st->execute([(string)$r['name'], $condsJson, $actsJson, (int)$r['priority'], (int)$r['enabled'], now_iso()]);
        }
        $rulesSeeded = true;
    }
    $segmentsSeeded = sample_segments_seed($pdo);
    if (!ensure_upload_dir()) {
        flash('error', 'Upload directory is not writable.');
        redirect_to(['tab' => 'import']);
    }
    $path = upload_dir() . DIRECTORY_SEPARATOR . 'sample_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
    $sampleCsv = sample_csv();
    file_put_contents($path, $sampleCsv);
    $lines = preg_split('/\r?\n/', trim($sampleCsv));
    $headers = $lines ? str_getcsv($lines[0]) : [];
    $_SESSION['import_stage'] = ['path' => $path, 'filename' => 'liteinsurance_sample.csv', 'headers' => $headers, 'defaults' => ['region' => 'Unknown', 'policy_type' => 'term']];
    audit($pdo, (int)$user['id'], 'sample.load', ['rules_seeded' => $rulesSeeded, 'segments_seeded' => $segmentsSeeded, 'rows' => SAMPLE_POLICY_COUNT]);
    flash('success', 'Sample staged with ' . SAMPLE_POLICY_COUNT . ' policies. Preview, then confirm to import.');
    redirect_to(['tab' => 'import']);
}

function sample_csv(): string
{
    $firstNames = ['Asha', 'Noah', 'Maria', 'Li', 'Sam', 'Nadia', 'Omar', 'Priya', 'Ethan', 'Mina', 'Jon', 'Sara', 'Imran', 'Elena', 'Grace', 'Kenji'];
    $lastNames = ['Rahman', 'Kim', 'Silva', 'Wei', 'Patel', 'Chowdhury', 'Hassan', 'Mitra', 'Brooks', 'Sato', 'Reed', 'Stone', 'Khan', 'Garcia', 'Miller', 'Tan'];
    $types = ['term', 'term', 'health', 'health', 'auto', 'life', 'life'];
    $regions = ['West', 'West', 'North', 'South', 'South', 'East', 'Central'];
    $genders = ['F', 'M', 'F', 'M', ''];
    $basePremium = ['term' => 118.0, 'health' => 104.0, 'auto' => 82.0, 'life' => 150.0];
    $baseAssured = ['term' => 72000, 'health' => 34000, 'auto' => 21000, 'life' => 98000];

    $seed = SAMPLE_RANDOM_SEED;
    $rand = static function () use (&$seed): float {
        $seed = (int)(($seed * 1103515245 + 12345) % 2147483648);
        return $seed / 2147483648;
    };
    $pick = static function (array $items) use ($rand) {
        return $items[(int)floor($rand() * count($items))];
    };
    $chance = static function (float $p) use ($rand): bool {
        return $rand() < $p;
    };

    $fh = fopen('php://temp', 'r+');
    if (!$fh) {
        return '';
    }
    fputcsv($fh, ['policy_number', 'name', 'dob', 'gender', 'policy_type', 'region', 'premium_amount', 'tenure_months', 'sum_assured']);
    for ($i = 1; $i <= SAMPLE_POLICY_COUNT; $i++) {
        $type = (string)$pick($types);
        $region = (string)$pick($regions);
        $age = 22 + (int)floor(min($rand(), $rand()) * 55);
        $tenure = (int)floor(min($rand(), $rand()) * 96);
        $premium = max(38.0, (float)$basePremium[$type] + (($age - 42) * 0.9) + ($tenure * 0.45) + floor($rand() * 42) - 18);
        $sumAssured = (int)$baseAssured[$type] + (int)floor($rand() * 36000);
        $dob = sprintf('%04d-%02d-%02d', 2026 - $age, ($i % 12) + 1, ($i % 27) + 1);
        fputcsv($fh, [
            'P-' . str_pad((string)(3000 + $i), 4, '0', STR_PAD_LEFT),
            (string)$pick($firstNames) . ' ' . (string)$pick($lastNames),
            $chance(0.08) ? '' : $dob,
            $chance(0.10) ? '' : (string)$pick($genders),
            $chance(0.08) ? '' : $type,
            $chance(0.11) ? '' : $region,
            $chance(0.09) ? '' : number_format($premium, 2, '.', ''),
            $chance(0.08) ? '' : (string)$tenure,
            $chance(0.06) ? '' : (string)$sumAssured,
        ]);
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return is_string($csv) ? str_replace("\r\n", "\n", $csv) : '';
}

function sample_rules_seed(): array
{
    return [
        [
            'name' => 'Senior + short tenure = High',
            'priority' => 120,
            'enabled' => 1,
            'definition' => [
                'logic' => 'AND',
                'conditions' => [
                    ['field' => 'age', 'op' => '>=', 'value' => 58],
                    ['field' => 'tenure_months', 'op' => '<', 'value' => 24],
                    ['field' => 'policy_type', 'op' => 'in', 'value' => ['term', 'health']],
                ],
                'actions' => ['set_risk_tier' => 'High', 'score_delta' => 20, 'add_tag' => 'senior_short_tenure'],
            ],
        ],
        [
            'name' => 'High premium + long tenure = Low',
            'priority' => 50,
            'enabled' => 1,
            'definition' => [
                'logic' => 'AND',
                'conditions' => [
                    ['field' => 'premium_amount', 'op' => '>=', 'value' => 140],
                    ['field' => 'tenure_months', 'op' => '>=', 'value' => 36],
                ],
                'actions' => ['set_risk_tier' => 'Low', 'score_delta' => -10, 'add_tag' => 'high_value_loyal'],
            ],
        ],
        [
            'name' => 'Low premium + short tenure = Review',
            'priority' => 70,
            'enabled' => 1,
            'definition' => [
                'logic' => 'AND',
                'conditions' => [
                    ['field' => 'premium_amount', 'op' => '<', 'value' => 75],
                    ['field' => 'tenure_months', 'op' => '<', 'value' => 8],
                ],
                'actions' => ['set_risk_tier' => 'Review', 'score_delta' => -15, 'add_tag' => 'thin_file_review'],
            ],
        ],
        [
            'name' => 'Large exposure term/life = Medium',
            'priority' => 90,
            'enabled' => 1,
            'definition' => [
                'logic' => 'AND',
                'conditions' => [
                    ['field' => 'sum_assured', 'op' => '>=', 'value' => 90000],
                    ['field' => 'policy_type', 'op' => 'in', 'value' => ['term', 'life']],
                ],
                'actions' => ['set_risk_tier' => 'Medium', 'score_delta' => 5, 'add_tag' => 'large_exposure'],
            ],
        ],
    ];
}

function sample_segments_seed(PDO $pdo): bool
{
    $hasSegments = (int)$pdo->query('SELECT COUNT(*) FROM segments')->fetchColumn() > 0;
    if ($hasSegments) {
        return false;
    }

    $rulesByName = [];
    $rows = $pdo->query('SELECT id, name FROM rules ORDER BY id ASC')->fetchAll();
    foreach ($rows as $r) {
        $rulesByName[(string)$r['name']] = (int)$r['id'];
    }

    $segments = [
        [
            'name' => 'High-risk senior review',
            'rule_ids' => array_filter([(int)($rulesByName['Senior + short tenure = High'] ?? 0)]),
            'where' => "risk_tier = 'High' OR age >= 63",
        ],
        [
            'name' => 'Health cross-sell candidates',
            'rule_ids' => [],
            'where' => "policy_type = 'health' AND ltv_estimate > 6000",
        ],
        [
            'name' => 'Data repair queue',
            'rule_ids' => [],
            'where' => 'confidence_score < 80',
        ],
        [
            'name' => 'High LTV loyalists',
            'rule_ids' => array_filter([(int)($rulesByName['High premium + long tenure = Low'] ?? 0)]),
            'where' => 'ltv_estimate > 9000 AND tenure_months >= 30',
        ],
    ];

    $st = $pdo->prepare('INSERT INTO segments (name, rule_ids, last_run_at) VALUES (?, ?, NULL)');
    foreach ($segments as $seg) {
        $payload = ['rule_ids' => array_values((array)$seg['rule_ids']), 'where' => (string)$seg['where'], 'mode' => 'rules_and_where'];
        $st->execute([(string)$seg['name'], json_encode($payload, JSON_UNESCAPED_SLASHES)]);
    }
    return true;
}

function handle_import_upload(PDO $pdo, array $user): void
{
    if (!rate_limit_allow($pdo, 'import.upload', client_ip(), IMPORT_RATE_LIMIT_MAX, IMPORT_RATE_LIMIT_WINDOW_SECONDS)) {
        http_response_code(429);
        flash('error', 'Too many uploads. Try again later.');
        redirect_to(['tab' => 'import']);
    }
    if (!isset($_FILES['csv']) || !is_array($_FILES['csv'])) {
        flash('error', 'No file uploaded.');
        redirect_to(['tab' => 'import']);
    }
    $f = $_FILES['csv'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('error', 'Upload failed.');
        redirect_to(['tab' => 'import']);
    }
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0 || $size > MAX_UPLOAD_BYTES) {
        flash('error', 'File is too large.');
        redirect_to(['tab' => 'import']);
    }
    if (!ensure_upload_dir()) {
        flash('error', 'Upload directory is not writable.');
        redirect_to(['tab' => 'import']);
    }
    $origRaw = (string)($f['name'] ?? 'upload.csv');
    $ext = strtolower((string)pathinfo($origRaw, PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        flash('error', 'Only .csv files are allowed.');
        redirect_to(['tab' => 'import']);
    }
    $mime = detect_mime((string)($f['tmp_name'] ?? ''));
    if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'], true)) {
        flash('error', 'Unsupported file type.');
        redirect_to(['tab' => 'import']);
    }
    $origName = sanitize_filename($origRaw);
    $dest = upload_dir() . DIRECTORY_SEPARATOR . 'import_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.csv';
    if (!move_uploaded_file((string)$f['tmp_name'], $dest)) {
        flash('error', 'Could not move uploaded file.');
        redirect_to(['tab' => 'import']);
    }
    @chmod($dest, 0640);
    $fh = fopen($dest, 'rb');
    if (!$fh) {
        flash('error', 'Could not read uploaded file.');
        redirect_to(['tab' => 'import']);
    }
    $headers = fgetcsv($fh);
    fclose($fh);
    if (!$headers || !is_array($headers)) {
        flash('error', 'CSV appears empty or invalid.');
        redirect_to(['tab' => 'import']);
    }
    $_SESSION['import_stage'] = ['path' => $dest, 'filename' => $origName, 'headers' => $headers, 'defaults' => ['region' => 'Unknown', 'policy_type' => 'term']];
    audit($pdo, (int)$user['id'], 'import.upload', ['filename' => $origName, 'bytes' => $size]);
    flash('success', 'File uploaded. Map columns to continue.');
    redirect_to(['tab' => 'import']);
}

function handle_import_preview(PDO $pdo, array $user): void
{
    $stage = $_SESSION['import_stage'] ?? null;
    if (!is_array($stage) || !isset($stage['path'], $stage['headers'])) {
        flash('error', 'No staged import found.');
        redirect_to(['tab' => 'import']);
    }
    $mapping = (array)($_POST['map'] ?? []);
    $defaults = (array)($_POST['defaults'] ?? []);
    $stage['mapping'] = $mapping;
    $stage['defaults'] = ['region' => trim((string)($defaults['region'] ?? 'Unknown')), 'policy_type' => trim((string)($defaults['policy_type'] ?? 'term'))];
    $analysis = analyze_csv((string)$stage['path'], (array)$stage['headers'], $mapping);
    $preview = preview_csv((string)$stage['path'], (array)$stage['headers'], $mapping, $stage['defaults'], $analysis, $pdo);
    $stage['preview'] = $preview;
    $_SESSION['import_stage'] = $stage;
    audit($pdo, (int)$user['id'], 'import.preview', ['filename' => (string)($stage['filename'] ?? '')]);
    flash('success', 'Preview generated.');
    redirect_to(['tab' => 'import']);
}

function handle_import_commit(PDO $pdo, array $user): void
{
    $stage = $_SESSION['import_stage'] ?? null;
    if (!is_array($stage) || !isset($stage['path'], $stage['headers'], $stage['mapping'], $stage['defaults'], $stage['preview']['analysis'])) {
        flash('error', 'No import preview found.');
        redirect_to(['tab' => 'import']);
    }
    $path = (string)$stage['path'];
    if (!is_file($path)) {
        flash('error', 'Staged CSV file is missing.');
        redirect_to(['tab' => 'import']);
    }
    $filename = (string)($stage['filename'] ?? 'upload.csv');
    $analysis = (array)$stage['preview']['analysis'];
    $customDefs = is_array($analysis['custom_fields'] ?? null) ? (array)$analysis['custom_fields'] : [];
    upsert_custom_field_defs($pdo, $customDefs);

    $pdo->beginTransaction();
    $insImp = $pdo->prepare('INSERT INTO imports (filename, rows_total, rows_imported, errors_json, created_at) VALUES (?, ?, 0, ?, ?)');
    $insImp->execute([$filename, (int)($analysis['rows_total'] ?? 0), json_encode([], JSON_UNESCAPED_SLASHES), now_iso()]);
    $importId = (int)$pdo->lastInsertId();
    $pdo->commit();

    $rules = load_rules($pdo);
    $fh = fopen($path, 'rb');
    if (!$fh) {
        flash('error', 'Could not read staged file.');
        redirect_to(['tab' => 'import']);
    }
    $headers = fgetcsv($fh);
    if (!$headers || !is_array($headers)) {
        fclose($fh);
        flash('error', 'Invalid CSV header.');
        redirect_to(['tab' => 'import']);
    }

    $rowsTotal = 0;
    $rowsImported = 0;
    $errors = [];
    $seen = [];

    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT INTO policyholders (policy_number, name, dob, age, gender, policy_type, region, premium_amount, tenure_months, sum_assured, ltv_estimate, risk_tier, is_imputed, confidence_score, metadata, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    while (($row = fgetcsv($fh)) !== false) {
        $rowsTotal++;
        $rec = map_row($headers, $row, (array)$stage['mapping']);
        $imputed = [];
        $validated = normalize_and_impute($rec, (array)$stage['defaults'], $analysis, $imputed, $rowsTotal, $filename);
        $customFields = extract_custom_fields($headers, $row, $customDefs);
        $validated['custom'] = $customFields;
        $hard = validate_policyholder_hard($validated);
        if ($hard !== null) {
            if (count($errors) < 50) {
                $errors[] = ['row' => $rowsTotal + 1, 'type' => 'hard', 'msg' => $hard];
            }
            continue;
        }
        $policyNumber = (string)$validated['policy_number'];
        if (isset($seen[$policyNumber])) {
            if (count($errors) < 50) {
                $errors[] = ['row' => $rowsTotal + 1, 'type' => 'hard', 'msg' => 'Duplicate policy_number in CSV: ' . $policyNumber];
            }
            continue;
        }
        $seen[$policyNumber] = true;
        if (policy_number_exists($pdo, $policyNumber)) {
            if (count($errors) < 50) {
                $errors[] = ['row' => $rowsTotal + 1, 'type' => 'hard', 'msg' => 'Policy already exists: ' . $policyNumber];
            }
            continue;
        }
        $score = compute_confidence($validated, $imputed);
        $riskOut = evaluate_rules_for_record($validated, $rules);
        $ruleHits = (array)($riskOut['rule_hits'] ?? []);
        $score = min(100, (int)round($score + min(20, count($ruleHits) * 4)));
        $ltv = compute_ltv($validated);
        $meta = ['imputed_fields' => array_values($imputed), 'rule_hits' => $ruleHits, 'tags' => (array)($riskOut['tags'] ?? []), 'custom_fields' => $customFields];
        $ins->execute([
            $policyNumber,
            (string)$validated['name'],
            $validated['dob'] !== '' ? (string)$validated['dob'] : null,
            $validated['age'] !== null ? (int)$validated['age'] : null,
            $validated['gender'] !== '' ? (string)$validated['gender'] : null,
            $validated['policy_type'] !== '' ? (string)$validated['policy_type'] : null,
            $validated['region'] !== '' ? (string)$validated['region'] : null,
            $validated['premium_amount'] !== null ? (float)$validated['premium_amount'] : null,
            $validated['tenure_months'] !== null ? (int)$validated['tenure_months'] : null,
            $validated['sum_assured'] !== null ? (float)$validated['sum_assured'] : null,
            $ltv,
            (string)($riskOut['risk_tier'] ?? 'Unknown'),
            count($imputed) > 0 ? 1 : 0,
            $score,
            json_encode($meta, JSON_UNESCAPED_SLASHES),
            now_iso(),
        ]);
        $rowsImported++;
        if (($rowsImported % IMPORT_COMMIT_EVERY_ROWS) === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
        }
    }
    $pdo->commit();
    fclose($fh);

    $upd = $pdo->prepare('UPDATE imports SET rows_total = ?, rows_imported = ?, errors_json = ? WHERE id = ?');
    $upd->execute([$rowsTotal, $rowsImported, json_encode($errors, JSON_UNESCAPED_SLASHES), $importId]);
    if ($filename === 'liteinsurance_sample.csv' && $rowsImported > 0) {
        seed_sample_campaigns($pdo);
    }
    audit($pdo, (int)$user['id'], 'import.commit', ['filename' => $filename, 'rows_total' => $rowsTotal, 'rows_imported' => $rowsImported, 'import_id' => $importId]);
    $_SESSION['import_stage'] = null;
    flash('success', 'Import completed: ' . $rowsImported . ' row(s) imported.');
    redirect_to(['tab' => 'import']);
}

function seed_sample_campaigns(PDO $pdo): void
{
    $hasCampaigns = (int)$pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn() > 0;
    if ($hasCampaigns) {
        return;
    }
    $segments = $pdo->query('SELECT * FROM segments ORDER BY id ASC LIMIT 2')->fetchAll();
    if (!$segments) {
        return;
    }
    $ins = $pdo->prepare('INSERT INTO campaigns (name, segment_id, assumptions, projected_revenue, created_at) VALUES (?, ?, ?, ?, ?)');
    foreach ($segments as $idx => $seg) {
        [$ruleIds, $where] = segment_criteria($seg);
        $segmentSize = estimate_segment_size($pdo, $ruleIds, $where);
        if ($segmentSize <= 0) {
            continue;
        }
        $rate = $idx === 0 ? 0.045 : 0.065;
        $avg = $idx === 0 ? 950.0 : 1250.0;
        $cost = 2.5;
        $lift = $idx === 0 ? 0.0 : 0.08;
        $gross = $segmentSize * $rate * $avg * (1 + $lift);
        $estCost = $segmentSize * $cost;
        $assumptions = [
            'segment_size' => $segmentSize,
            'cross_sell_rate' => $rate,
            'avg_offer_value' => $avg,
            'campaign_cost_per_contact' => $cost,
            'conversion_lift' => $lift,
            'contacts' => $segmentSize,
            'expected_conversions' => $segmentSize * $rate,
            'gross_revenue' => $gross,
            'estimated_cost' => $estCost,
            'net_revenue' => $gross - $estCost,
        ];
        $ins->execute(['Sample simulation - ' . (string)$seg['name'], (int)$seg['id'], json_encode($assumptions, JSON_UNESCAPED_SLASHES), $gross, now_iso()]);
    }
}

function export_rules_json(PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'POST required';
        return;
    }
    $rules = $pdo->query('SELECT id, name, conditions_json, actions_json, priority, enabled, created_at FROM rules ORDER BY priority ASC, id ASC')->fetchAll();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="liteinsurance_rules.json"');
    echo json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function json_rule_test(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);
        return;
    }
    $pid = (int)($_POST['_test_policyholder_id'] ?? ($_POST['policyholder_id'] ?? 0));
    $defJson = (string)($_POST['definition_json'] ?? '');
    $def = safe_json($defJson);
    if (!is_array($def) || !isset($def['conditions'], $def['actions'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid rule JSON']);
        return;
    }
    $st = $pdo->prepare('SELECT * FROM policyholders WHERE id = ?');
    $st->execute([$pid]);
    $ph = $st->fetch();
    if (!$ph) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Policyholder not found']);
        return;
    }
    $rec = policyholder_record_from_row($ph);
    $rule = ['id' => 0, 'conditions' => ['logic' => (string)($def['logic'] ?? 'AND'), 'conditions' => (array)$def['conditions']], 'actions' => (array)$def['actions']];
    $out = evaluate_rules_for_record($rec, [$rule]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'record' => ['policy_number' => $rec['policy_number'], 'region' => $rec['region'], 'age' => $rec['age'], 'policy_type' => $rec['policy_type'], 'custom' => $rec['custom']], 'result' => $out], JSON_UNESCAPED_SLASHES);
}

function handle_rule_save(PDO $pdo, array $user): void
{
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $priority = (int)($_POST['priority'] ?? 100);
    $enabled = (int)($_POST['enabled'] ?? 1) === 1 ? 1 : 0;
    $defJson = (string)($_POST['definition_json'] ?? '');
    if ($name === '') {
        flash('error', 'Rule name is required.');
        redirect_to(['tab' => 'rules']);
    }
    $def = safe_json($defJson);
    if (!is_array($def) || !isset($def['conditions'], $def['actions']) || !is_array($def['conditions']) || !is_array($def['actions'])) {
        flash('error', 'Invalid JSON. Expect {logic, conditions[], actions{...}}.');
        redirect_to(['tab' => 'rules', 'edit' => (string)$id]);
    }
    $condsPayload = ['logic' => (string)($def['logic'] ?? 'AND'), 'conditions' => (array)$def['conditions']];
    $condsJson = json_encode($condsPayload, JSON_UNESCAPED_SLASHES);
    $actionsJson = json_encode((array)$def['actions'], JSON_UNESCAPED_SLASHES);
    if ($id > 0) {
        $st = $pdo->prepare('UPDATE rules SET name = ?, conditions_json = ?, actions_json = ?, priority = ?, enabled = ? WHERE id = ?');
        $st->execute([$name, $condsJson, $actionsJson, $priority, $enabled, $id]);
        audit($pdo, (int)$user['id'], 'rules.update', ['id' => $id, 'name' => $name]);
        flash('success', 'Rule updated.');
    } else {
        $st = $pdo->prepare('INSERT INTO rules (name, conditions_json, actions_json, priority, enabled, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([$name, $condsJson, $actionsJson, $priority, $enabled, now_iso()]);
        audit($pdo, (int)$user['id'], 'rules.create', ['id' => (int)$pdo->lastInsertId(), 'name' => $name]);
        flash('success', 'Rule created.');
    }
    redirect_to(['tab' => 'rules']);
}

function handle_rule_delete(PDO $pdo, array $user): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $st = $pdo->prepare('DELETE FROM rules WHERE id = ?');
        $st->execute([$id]);
        audit($pdo, (int)$user['id'], 'rules.delete', ['id' => $id]);
        flash('success', 'Rule deleted.');
    }
    redirect_to(['tab' => 'rules']);
}

function handle_rule_toggle(PDO $pdo, array $user): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $st = $pdo->prepare('UPDATE rules SET enabled = CASE WHEN enabled = 1 THEN 0 ELSE 1 END WHERE id = ?');
        $st->execute([$id]);
        audit($pdo, (int)$user['id'], 'rules.toggle', ['id' => $id]);
        flash('success', 'Rule toggled.');
    }
    redirect_to(['tab' => 'rules']);
}

function compile_filter_expr(string $expr): array
{
    $expr = trim($expr);
    if ($expr === '') {
        return ['1=1', []];
    }

    $allowedFields = [
        'risk_tier' => 'text',
        'region' => 'text',
        'policy_type' => 'text',
        'premium_amount' => 'num',
        'tenure_months' => 'num',
        'age' => 'num',
        'ltv_estimate' => 'num',
        'confidence_score' => 'num',
    ];

    $tokens = tokenize_filter_expr($expr);
    $i = 0;
    $parseExpr = null;

    $expect = static function (string $type, ?string $value = null) use (&$tokens, &$i): array {
        $t = $tokens[$i] ?? null;
        if (!$t || $t['type'] !== $type || ($value !== null && strtoupper((string)$t['value']) !== strtoupper($value))) {
            throw new RuntimeException('Invalid filter syntax.');
        }
        $i++;
        return $t;
    };

    $peek = static function () use (&$tokens, &$i): ?array {
        return $tokens[$i] ?? null;
    };

    $parseValue = static function () use (&$expect, &$peek): array {
        $t = $peek();
        if (!$t) {
            throw new RuntimeException('Missing value.');
        }
        if ($t['type'] === 'NUMBER') {
            return $expect('NUMBER');
        }
        if ($t['type'] === 'STRING') {
            return $expect('STRING');
        }
        throw new RuntimeException('Invalid value.');
    };

    $parseComparison = static function () use (&$expect, &$peek, &$parseValue, $allowedFields): array {
        $fieldTok = $expect('IDENT');
        $field = (string)$fieldTok['value'];
        $fieldLower = strtolower($field);
        if (!isset($allowedFields[$fieldLower])) {
            throw new RuntimeException('Field not allowed.');
        }
        $kw = $peek();
        if ($kw && $kw['type'] === 'KW' && strtoupper((string)$kw['value']) === 'IS') {
            $expect('KW', 'IS');
            $not = false;
            $p = $peek();
            if ($p && $p['type'] === 'KW' && strtoupper((string)$p['value']) === 'NOT') {
                $expect('KW', 'NOT');
                $not = true;
            }
            $expect('KW', 'NULL');
            return ['sql' => $fieldLower . ' IS ' . ($not ? 'NOT ' : '') . 'NULL', 'params' => []];
        }
        if ($kw && $kw['type'] === 'KW' && strtoupper((string)$kw['value']) === 'IN') {
            $expect('KW', 'IN');
            $expect('LPAREN');
            $vals = [];
            $params = [];
            while (true) {
                $vTok = $parseValue();
                $vals[] = '?';
                $params[] = $vTok['parsed'];
                $p = $peek();
                if ($p && $p['type'] === 'COMMA') {
                    $expect('COMMA');
                    continue;
                }
                break;
            }
            $expect('RPAREN');
            if (count($params) === 0 || count($params) > 50) {
                throw new RuntimeException('Invalid IN list.');
            }
            return ['sql' => $fieldLower . ' IN (' . implode(',', $vals) . ')', 'params' => $params];
        }
        if ($kw && $kw['type'] === 'KW' && strtoupper((string)$kw['value']) === 'LIKE') {
            $expect('KW', 'LIKE');
            $vTok = $parseValue();
            return ['sql' => $fieldLower . ' LIKE ?', 'params' => [$vTok['parsed']]];
        }

        $opTok = $expect('OP');
        $op = (string)$opTok['value'];
        if (!in_array($op, ['=', '!=', '>', '<', '>=', '<='], true)) {
            throw new RuntimeException('Operator not allowed.');
        }
        $vTok = $parseValue();
        if ($allowedFields[$fieldLower] === 'num' && !is_numeric($vTok['parsed'])) {
            throw new RuntimeException('Numeric value required.');
        }
        return ['sql' => $fieldLower . ' ' . $op . ' ?', 'params' => [$vTok['parsed']]];
    };

    $parseFactor = static function () use (&$peek, &$expect, &$parseComparison, &$parseExpr): array {
        $t = $peek();
        if (!$t) {
            throw new RuntimeException('Unexpected end.');
        }
        if ($t['type'] === 'KW' && strtoupper((string)$t['value']) === 'NOT') {
            $expect('KW', 'NOT');
            $inner = $parseFactor();
            return ['sql' => '(NOT ' . $inner['sql'] . ')', 'params' => $inner['params']];
        }
        if ($t['type'] === 'LPAREN') {
            $expect('LPAREN');
            $inner = $parseExpr();
            $expect('RPAREN');
            return ['sql' => '(' . $inner['sql'] . ')', 'params' => $inner['params']];
        }
        return $parseComparison();
    };

    $parseTerm = static function () use (&$parseFactor, &$peek, &$expect): array {
        $left = $parseFactor();
        while (true) {
            $t = $peek();
            if (!$t || $t['type'] !== 'KW' || strtoupper((string)$t['value']) !== 'AND') {
                break;
            }
            $expect('KW', 'AND');
            $right = $parseFactor();
            $left = ['sql' => '(' . $left['sql'] . ' AND ' . $right['sql'] . ')', 'params' => array_merge($left['params'], $right['params'])];
        }
        return $left;
    };

    $parseExpr = static function () use (&$parseTerm, &$peek, &$expect): array {
        $left = $parseTerm();
        while (true) {
            $t = $peek();
            if (!$t || $t['type'] !== 'KW' || strtoupper((string)$t['value']) !== 'OR') {
                break;
            }
            $expect('KW', 'OR');
            $right = $parseTerm();
            $left = ['sql' => '(' . $left['sql'] . ' OR ' . $right['sql'] . ')', 'params' => array_merge($left['params'], $right['params'])];
        }
        return $left;
    };

    $out = $parseExpr();
    if (($tokens[$i] ?? null) !== null) {
        throw new RuntimeException('Unexpected token.');
    }
    return [$out['sql'], $out['params']];
}

function filter_field_type(string $field): ?string
{
    $allowedFields = [
        'risk_tier' => 'text',
        'region' => 'text',
        'policy_type' => 'text',
        'premium_amount' => 'num',
        'tenure_months' => 'num',
        'age' => 'num',
        'ltv_estimate' => 'num',
        'confidence_score' => 'num',
    ];
    $field = strtolower(trim($field));
    if (isset($allowedFields[$field])) {
        return $allowedFields[$field];
    }
    if (preg_match('/^custom\.[a-z_][a-z0-9_]*$/', $field)) {
        return 'custom';
    }
    return null;
}

function filter_expr_uses_custom(string $expr): bool
{
    return preg_match('/\bcustom\.[a-z_][a-z0-9_]*\b/i', $expr) === 1;
}

function validate_filter_expr(string $expr): void
{
    parse_filter_expr_ast($expr);
}

function parse_filter_expr_ast(string $expr): array
{
    $expr = trim($expr);
    if ($expr === '') {
        return ['type' => 'true'];
    }
    $tokens = tokenize_filter_expr($expr);
    $i = 0;
    $parseExpr = null;
    $parseTerm = null;
    $parseFactor = null;

    $expect = static function (string $type, ?string $value = null) use (&$tokens, &$i): array {
        $t = $tokens[$i] ?? null;
        if (!$t || $t['type'] !== $type || ($value !== null && strtoupper((string)$t['value']) !== strtoupper($value))) {
            throw new RuntimeException('Invalid filter syntax.');
        }
        $i++;
        return $t;
    };
    $peek = static function () use (&$tokens, &$i): ?array {
        return $tokens[$i] ?? null;
    };
    $parseValue = static function () use (&$expect, &$peek): array {
        $t = $peek();
        if (!$t) {
            throw new RuntimeException('Missing value.');
        }
        if ($t['type'] === 'NUMBER') {
            return $expect('NUMBER');
        }
        if ($t['type'] === 'STRING') {
            return $expect('STRING');
        }
        throw new RuntimeException('Invalid value.');
    };
    $parseComparison = static function () use (&$expect, &$peek, &$parseValue): array {
        $fieldTok = $expect('IDENT');
        $field = strtolower((string)$fieldTok['value']);
        $fieldType = filter_field_type($field);
        if ($fieldType === null) {
            throw new RuntimeException('Field not allowed.');
        }
        $kw = $peek();
        if ($kw && $kw['type'] === 'KW' && strtoupper((string)$kw['value']) === 'IS') {
            $expect('KW', 'IS');
            $not = false;
            $p = $peek();
            if ($p && $p['type'] === 'KW' && strtoupper((string)$p['value']) === 'NOT') {
                $expect('KW', 'NOT');
                $not = true;
            }
            $expect('KW', 'NULL');
            return ['type' => 'cmp', 'field' => $field, 'op' => $not ? 'is_not_null' : 'is_null'];
        }
        if ($kw && $kw['type'] === 'KW' && strtoupper((string)$kw['value']) === 'IN') {
            $expect('KW', 'IN');
            $expect('LPAREN');
            $vals = [];
            while (true) {
                $vals[] = $parseValue()['parsed'];
                $p = $peek();
                if ($p && $p['type'] === 'COMMA') {
                    $expect('COMMA');
                    continue;
                }
                break;
            }
            $expect('RPAREN');
            if (count($vals) === 0 || count($vals) > 50) {
                throw new RuntimeException('Invalid IN list.');
            }
            return ['type' => 'cmp', 'field' => $field, 'op' => 'in', 'value' => $vals];
        }
        if ($kw && $kw['type'] === 'KW' && strtoupper((string)$kw['value']) === 'LIKE') {
            $expect('KW', 'LIKE');
            return ['type' => 'cmp', 'field' => $field, 'op' => 'like', 'value' => $parseValue()['parsed']];
        }
        $op = (string)$expect('OP')['value'];
        if (!in_array($op, ['=', '!=', '>', '<', '>=', '<='], true)) {
            throw new RuntimeException('Operator not allowed.');
        }
        $value = $parseValue()['parsed'];
        if ($fieldType === 'num' && !is_numeric($value)) {
            throw new RuntimeException('Numeric value required.');
        }
        return ['type' => 'cmp', 'field' => $field, 'op' => $op, 'value' => $value];
    };
    $parseFactor = static function () use (&$peek, &$expect, &$parseComparison, &$parseExpr, &$parseFactor): array {
        $t = $peek();
        if (!$t) {
            throw new RuntimeException('Unexpected end.');
        }
        if ($t['type'] === 'KW' && strtoupper((string)$t['value']) === 'NOT') {
            $expect('KW', 'NOT');
            return ['type' => 'not', 'item' => $parseFactor()];
        }
        if ($t['type'] === 'LPAREN') {
            $expect('LPAREN');
            $inner = $parseExpr();
            $expect('RPAREN');
            return $inner;
        }
        return $parseComparison();
    };
    $parseTerm = static function () use (&$peek, &$expect, &$parseFactor): array {
        $left = $parseFactor();
        while (true) {
            $t = $peek();
            if (!$t || $t['type'] !== 'KW' || strtoupper((string)$t['value']) !== 'AND') {
                break;
            }
            $expect('KW', 'AND');
            $left = ['type' => 'and', 'left' => $left, 'right' => $parseFactor()];
        }
        return $left;
    };
    $parseExpr = static function () use (&$peek, &$expect, &$parseTerm): array {
        $left = $parseTerm();
        while (true) {
            $t = $peek();
            if (!$t || $t['type'] !== 'KW' || strtoupper((string)$t['value']) !== 'OR') {
                break;
            }
            $expect('KW', 'OR');
            $left = ['type' => 'or', 'left' => $left, 'right' => $parseTerm()];
        }
        return $left;
    };

    $out = $parseExpr();
    if (($tokens[$i] ?? null) !== null) {
        throw new RuntimeException('Unexpected token.');
    }
    return $out;
}

function eval_filter_expr_for_record(array $record, string $expr): bool
{
    return eval_filter_ast(parse_filter_expr_ast($expr), $record);
}

function eval_filter_ast(array $ast, array $record): bool
{
    $type = (string)($ast['type'] ?? '');
    if ($type === 'true') {
        return true;
    }
    if ($type === 'and') {
        return eval_filter_ast((array)$ast['left'], $record) && eval_filter_ast((array)$ast['right'], $record);
    }
    if ($type === 'or') {
        return eval_filter_ast((array)$ast['left'], $record) || eval_filter_ast((array)$ast['right'], $record);
    }
    if ($type === 'not') {
        return !eval_filter_ast((array)$ast['item'], $record);
    }
    if ($type !== 'cmp') {
        return false;
    }
    $left = value_for_rule_field($record, (string)$ast['field']);
    $op = (string)($ast['op'] ?? '');
    $value = $ast['value'] ?? null;
    if ($op === 'is_null') {
        return $left === null || $left === '';
    }
    if ($op === 'is_not_null') {
        return !($left === null || $left === '');
    }
    if ($op === 'in') {
        foreach ((array)$value as $v) {
            if (compare_scalar($left, $v) === 0) {
                return true;
            }
        }
        return false;
    }
    if ($op === 'like') {
        return sql_like_match((string)$left, (string)$value);
    }
    if ($op === '=' || $op === '==') {
        return compare_scalar($left, $value) === 0;
    }
    if ($op === '!=') {
        return compare_scalar($left, $value) !== 0;
    }
    if ($op === '>') {
        return (float)$left > (float)$value;
    }
    if ($op === '<') {
        return (float)$left < (float)$value;
    }
    if ($op === '>=') {
        return (float)$left >= (float)$value;
    }
    if ($op === '<=') {
        return (float)$left <= (float)$value;
    }
    return false;
}

function sql_like_match(string $left, string $pattern): bool
{
    $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';
    return preg_match($regex, $left) === 1;
}

function segment_criteria(array $segRow): array
{
    $criteria = safe_json((string)($segRow['rule_ids'] ?? ''));
    if (!is_array($criteria)) {
        $criteria = ['rule_ids' => [], 'where' => ''];
    }
    $ruleIds = array_values(array_unique(array_filter(array_map('intval', (array)($criteria['rule_ids'] ?? [])), fn($x) => $x > 0)));
    $where = is_string($criteria['where'] ?? null) ? trim((string)$criteria['where']) : '';
    return [$ruleIds, $where];
}

function estimate_segment_size(PDO $pdo, array $ruleIds, string $where): int
{
    $usesCustom = filter_expr_uses_custom($where);
    if (!$usesCustom) {
        try {
            [$filterSql, $filterParams] = compile_filter_expr($where);
        } catch (Throwable $e) {
            $filterSql = '1=1';
            $filterParams = [];
        }
        $stBase = $pdo->prepare('SELECT COUNT(*) FROM policyholders WHERE ' . $filterSql);
        $stBase->execute($filterParams);
        $baseCount = (int)$stBase->fetchColumn();
        if (!$ruleIds) {
            return $baseCount;
        }
    } else {
        validate_filter_expr($where);
        $filterSql = '1=1';
        $filterParams = [];
    }
    $rules = load_rules($pdo);
    $selected = array_values(array_filter($rules, fn($r) => in_array((int)($r['id'] ?? 0), $ruleIds, true)));
    if ($ruleIds && !$selected) {
        return 0;
    }
    $count = 0;
    $offsetId = 0;
    while (true) {
        $st = $pdo->prepare('SELECT * FROM policyholders WHERE id > ? AND (' . $filterSql . ') ORDER BY id ASC LIMIT 500');
        $st->execute(array_merge([$offsetId], $filterParams));
        $rows = $st->fetchAll();
        if (!$rows) {
            break;
        }
        foreach ($rows as $ph) {
            $rec = policyholder_record_from_row($ph);
            if ($usesCustom && !eval_filter_expr_for_record($rec, $where)) {
                $offsetId = (int)$ph['id'];
                continue;
            }
            $ok = true;
            foreach ($selected as $rule) {
                if (!eval_conditions($rec, (array)($rule['conditions'] ?? []))) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $count++;
            }
            $offsetId = (int)$ph['id'];
        }
    }
    return $count;
}

function handle_segment_save(PDO $pdo, array $user): void
{
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $ruleIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['rule_ids'] ?? [])), fn($x) => $x > 0)));
    $where = trim((string)($_POST['where'] ?? ''));
    if ($name === '') {
        flash('error', 'Segment name is required.');
        redirect_to(['tab' => 'segments']);
    }
    try {
        validate_filter_expr($where);
    } catch (Throwable $e) {
        flash('error', 'Unsafe filter expression.');
        redirect_to(['tab' => 'segments', 'edit' => (string)$id]);
    }
    $payload = ['rule_ids' => $ruleIds, 'where' => $where, 'mode' => 'rules_and_where'];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($id > 0) {
        $st = $pdo->prepare('UPDATE segments SET name = ?, rule_ids = ? WHERE id = ?');
        $st->execute([$name, $json, $id]);
        audit($pdo, (int)$user['id'], 'segments.update', ['id' => $id, 'name' => $name]);
        flash('success', 'Segment updated.');
    } else {
        $st = $pdo->prepare('INSERT INTO segments (name, rule_ids, last_run_at) VALUES (?, ?, NULL)');
        $st->execute([$name, $json]);
        audit($pdo, (int)$user['id'], 'segments.create', ['id' => (int)$pdo->lastInsertId(), 'name' => $name]);
        flash('success', 'Segment created.');
    }
    redirect_to(['tab' => 'segments']);
}

function handle_segment_delete(PDO $pdo, array $user): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $st = $pdo->prepare('DELETE FROM segments WHERE id = ?');
        $st->execute([$id]);
        audit($pdo, (int)$user['id'], 'segments.delete', ['id' => $id]);
        flash('success', 'Segment deleted.');
    }
    redirect_to(['tab' => 'segments']);
}

function handle_segment_estimate(PDO $pdo, array $user): void
{
    $ruleIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['rule_ids'] ?? [])), fn($x) => $x > 0)));
    $where = trim((string)($_POST['where'] ?? ''));
    try {
        validate_filter_expr($where);
    } catch (Throwable $e) {
        flash('error', 'Unsafe filter expression.');
        redirect_to(['tab' => 'segments']);
    }
    $count = estimate_segment_size($pdo, $ruleIds, $where);
    $pdo->prepare('UPDATE segments SET last_run_at = ? WHERE id = ?')->execute([now_iso(), (int)($_POST['id'] ?? 0)]);
    audit($pdo, (int)$user['id'], 'segments.estimate', ['rule_ids' => $ruleIds, 'where' => $where, 'count' => $count]);
    flash('success', 'Estimated segment size: ' . $count);
    redirect_to(['tab' => 'segments']);
}

function handle_export_segment_prepare(): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        redirect_to(['tab' => 'segments']);
    }
    $_SESSION['export_token'] = bin2hex(random_bytes(16));
    redirect_to(['action' => 'export_segment', 'id' => (string)$id, 't' => (string)$_SESSION['export_token']]);
}

function export_segment_csv(PDO $pdo): void
{
    $t = (string)($_GET['t'] ?? '');
    if ($t === '' || !hash_equals((string)($_SESSION['export_token'] ?? ''), $t)) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM segments WHERE id = ?');
    $st->execute([$id]);
    $seg = $st->fetch();
    if (!$seg) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    [$ruleIds, $where] = segment_criteria($seg);
    $usesCustom = filter_expr_uses_custom($where);
    if ($usesCustom) {
        validate_filter_expr($where);
        $filterSql = '1=1';
        $filterParams = [];
    } else {
        try {
            [$filterSql, $filterParams] = compile_filter_expr($where);
        } catch (Throwable $e) {
            $filterSql = '1=1';
            $filterParams = [];
        }
    }
    $rules = load_rules($pdo);
    $selected = array_values(array_filter($rules, fn($r) => in_array((int)($r['id'] ?? 0), $ruleIds, true)));
    $customDefs = $pdo->query('SELECT field_key, label FROM custom_field_defs WHERE enabled = 1 ORDER BY field_key ASC')->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="segment_' . (int)$seg['id'] . '_export.csv"');
    $out = fopen('php://output', 'wb');
    $headers = ['policy_number', 'name', 'age', 'gender', 'policy_type', 'region', 'premium_amount', 'tenure_months', 'sum_assured', 'ltv_estimate', 'risk_tier', 'confidence_score'];
    foreach ($customDefs as $def) {
        $headers[] = 'custom.' . (string)$def['field_key'];
    }
    fputcsv($out, $headers);

    $offsetId = 0;
    while (true) {
        $st = $pdo->prepare('SELECT * FROM policyholders WHERE id > ? AND (' . $filterSql . ') ORDER BY id ASC LIMIT 500');
        $st->execute(array_merge([$offsetId], $filterParams));
        $rows = $st->fetchAll();
        if (!$rows) {
            break;
        }
        foreach ($rows as $ph) {
            $rec = policyholder_record_from_row($ph);
            if ($usesCustom && !eval_filter_expr_for_record($rec, $where)) {
                $offsetId = (int)$ph['id'];
                continue;
            }
            $ok = true;
            foreach ($selected as $rule) {
                if (!eval_conditions($rec, (array)($rule['conditions'] ?? []))) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $line = [
                    $rec['policy_number'], $rec['name'], (string)($rec['age'] ?? ''), $rec['gender'], $rec['policy_type'], $rec['region'],
                    (string)($rec['premium_amount'] ?? ''), (string)($rec['tenure_months'] ?? ''), (string)($rec['sum_assured'] ?? ''),
                    (string)($rec['ltv_estimate'] ?? ''), $rec['risk_tier'], (string)$rec['confidence_score'],
                ];
                $custom = is_array($rec['custom'] ?? null) ? (array)$rec['custom'] : [];
                foreach ($customDefs as $def) {
                    $v = $custom[(string)$def['field_key']] ?? '';
                    $line[] = is_bool($v) ? ($v ? 'true' : 'false') : (string)$v;
                }
                fputcsv($out, $line);
            }
            $offsetId = (int)$ph['id'];
        }
    }
    fclose($out);
}

function handle_campaign_save(PDO $pdo, array $user): void
{
    $name = trim((string)($_POST['name'] ?? ''));
    $segmentId = (int)($_POST['segment_id'] ?? 0);
    $rate = (float)($_POST['cross_sell_rate'] ?? 0);
    $avg = (float)($_POST['avg_offer_value'] ?? 0);
    $cost = (float)($_POST['campaign_cost_per_contact'] ?? 0);
    $lift = (float)($_POST['conversion_lift'] ?? 0);
    if ($name === '' || $segmentId <= 0) {
        flash('error', 'Campaign name and segment are required.');
        redirect_to(['tab' => 'campaigns']);
    }
    $st = $pdo->prepare('SELECT * FROM segments WHERE id = ?');
    $st->execute([$segmentId]);
    $seg = $st->fetch();
    if (!$seg) {
        flash('error', 'Segment not found.');
        redirect_to(['tab' => 'campaigns']);
    }
    [$ruleIds, $where] = segment_criteria($seg);
    $segmentSize = estimate_segment_size($pdo, $ruleIds, $where);
    $contacts = $segmentSize;
    $expectedConversions = $contacts * $rate;
    $gross = $contacts * $rate * $avg * (1 + $lift);
    $estCost = $contacts * $cost;
    $net = $gross - $estCost;
    $assumptions = [
        'segment_size' => $segmentSize,
        'cross_sell_rate' => $rate,
        'avg_offer_value' => $avg,
        'campaign_cost_per_contact' => $cost,
        'conversion_lift' => $lift,
        'contacts' => $contacts,
        'expected_conversions' => $expectedConversions,
        'gross_revenue' => $gross,
        'estimated_cost' => $estCost,
        'net_revenue' => $net,
    ];
    $ins = $pdo->prepare('INSERT INTO campaigns (name, segment_id, assumptions, projected_revenue, created_at) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([$name, $segmentId, json_encode($assumptions, JSON_UNESCAPED_SLASHES), $gross, now_iso()]);
    audit($pdo, (int)$user['id'], 'campaigns.simulate', ['id' => (int)$pdo->lastInsertId(), 'segment_id' => $segmentId, 'projected_revenue' => $gross]);
    flash('success', 'Simulation complete. Net revenue: ' . CURRENCY_SYMBOL . number_format($net, 2));
    redirect_to(['tab' => 'campaigns']);
}

function handle_policy_cell_update(PDO $pdo, array $user): void
{
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);
        return;
    }
    $id = (int)($_POST['id'] ?? 0);
    $field = trim((string)($_POST['field'] ?? ''));
    $value = trim((string)($_POST['value'] ?? ''));
    if ($id <= 0 || $field === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing row or field']);
        return;
    }
    $st = $pdo->prepare('SELECT * FROM policyholders WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Policyholder not found']);
        return;
    }

    $coreFields = ['policy_number', 'name', 'age', 'policy_type', 'region', 'premium_amount', 'tenure_months', 'sum_assured'];
    if (str_starts_with($field, 'custom.')) {
        $key = substr($field, 7);
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $key)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid custom field']);
            return;
        }
        $meta = safe_json((string)($row['metadata'] ?? '{}'));
        if (!is_array($meta)) {
            $meta = [];
        }
        $custom = is_array($meta['custom_fields'] ?? null) ? (array)$meta['custom_fields'] : [];
        $def = $pdo->prepare('SELECT field_type, label, source_header FROM custom_field_defs WHERE field_key = ? LIMIT 1');
        $def->execute([$key]);
        $defRow = $def->fetch();
        $fieldType = is_array($defRow) ? (string)($defRow['field_type'] ?? 'text') : (infer_custom_value_type($value) ?? 'text');
        $custom[$key] = parse_custom_value($value, $fieldType);
        $meta['custom_fields'] = $custom;
        $upd = $pdo->prepare('UPDATE policyholders SET metadata = ? WHERE id = ?');
        $upd->execute([json_encode($meta, JSON_UNESCAPED_SLASHES), $id]);
    } elseif (in_array($field, $coreFields, true)) {
        if (in_array($field, ['policy_number', 'name'], true) && $value === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $field . ' cannot be blank']);
            return;
        }
        if ($field === 'policy_number') {
            $dupe = $pdo->prepare('SELECT COUNT(*) FROM policyholders WHERE policy_number = ? AND id <> ?');
            $dupe->execute([$value, $id]);
            if ((int)$dupe->fetchColumn() > 0) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'Policy ID must be unique']);
                return;
            }
        }
        $parsed = $value;
        if (in_array($field, ['age', 'tenure_months'], true)) {
            $parsed = $value === '' ? null : parse_int($value);
            if ($value !== '' && $parsed === null) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Whole number expected']);
                return;
            }
        } elseif (in_array($field, ['premium_amount', 'sum_assured'], true)) {
            $parsed = $value === '' ? null : parse_number($value);
            if ($value !== '' && $parsed === null) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Number expected']);
                return;
            }
        }
        $upd = $pdo->prepare('UPDATE policyholders SET ' . $field . ' = ? WHERE id = ?');
        $upd->execute([$parsed, $id]);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Field is read-only']);
        return;
    }

    $derived = recompute_policyholder_row($pdo, $id);
    audit($pdo, (int)$user['id'], 'profiles.inline_update', ['id' => $id, 'field' => $field]);
    echo json_encode(['ok' => true, 'id' => $id, 'field' => $field, 'derived' => $derived], JSON_UNESCAPED_SLASHES);
}

function recompute_policyholder_row(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM policyholders WHERE id = ?');
    $st->execute([$id]);
    $ph = $st->fetch();
    if (!$ph) {
        return [];
    }
    $rec = policyholder_record_from_row($ph);
    $meta = safe_json((string)($ph['metadata'] ?? '{}'));
    if (!is_array($meta)) {
        $meta = [];
    }
    $imputed = is_array($meta['imputed_fields'] ?? null) ? (array)$meta['imputed_fields'] : [];
    $riskOut = evaluate_rules_for_record($rec, load_rules($pdo));
    $ruleHits = (array)($riskOut['rule_hits'] ?? []);
    $score = compute_confidence($rec, $imputed);
    $score = min(100, (int)round($score + min(20, count($ruleHits) * 4)));
    $ltv = compute_ltv($rec);
    $meta['rule_hits'] = $ruleHits;
    $meta['tags'] = (array)($riskOut['tags'] ?? []);
    $upd = $pdo->prepare('UPDATE policyholders SET ltv_estimate = ?, risk_tier = ?, confidence_score = ?, metadata = ? WHERE id = ?');
    $upd->execute([$ltv, (string)($riskOut['risk_tier'] ?? 'Unknown'), $score, json_encode($meta, JSON_UNESCAPED_SLASHES), $id]);
    return ['ltv_estimate' => $ltv, 'risk_tier' => (string)($riskOut['risk_tier'] ?? 'Unknown'), 'confidence_score' => $score];
}

function handle_repair_apply(PDO $pdo, array $user): void
{
    $setRegion = trim((string)($_POST['set_region'] ?? ''));
    $setType = trim((string)($_POST['set_policy_type'] ?? ''));
    $count = 0;
    if ($setRegion !== '') {
        $st = $pdo->prepare("UPDATE policyholders SET region = ? WHERE region IS NULL OR TRIM(region) = ''");
        $st->execute([$setRegion]);
        $count += $st->rowCount();
    }
    if ($setType !== '') {
        $st = $pdo->prepare("UPDATE policyholders SET policy_type = ? WHERE policy_type IS NULL OR TRIM(policy_type) = ''");
        $st->execute([$setType]);
        $count += $st->rowCount();
    }
    audit($pdo, (int)$user['id'], 'profiles.repair', ['rows_updated' => $count]);
    flash('success', 'Repair applied. Rows updated: ' . $count);
    redirect_to(['tab' => 'profiles']);
}

function handle_recompute_now(PDO $pdo, array $user): void
{
    $rules = load_rules($pdo);
    $updated = 0;
    $offsetId = 0;
    $pdo->beginTransaction();
    $upd = $pdo->prepare('UPDATE policyholders SET ltv_estimate = ?, risk_tier = ?, confidence_score = ?, metadata = ? WHERE id = ?');
    while (true) {
        $st = $pdo->prepare('SELECT * FROM policyholders WHERE id > ? ORDER BY id ASC LIMIT 300');
        $st->execute([$offsetId]);
        $rows = $st->fetchAll();
        if (!$rows) {
            break;
        }
        foreach ($rows as $ph) {
            $rec = policyholder_record_from_row($ph);
            $meta = safe_json((string)($ph['metadata'] ?? '{}'));
            if (!is_array($meta)) {
                $meta = [];
            }
            $metadata = $meta;
            $imputed = is_array($meta['imputed_fields'] ?? null) ? (array)$meta['imputed_fields'] : [];
            $score = compute_confidence($rec, $imputed);
            $riskOut = evaluate_rules_for_record($rec, $rules);
            $ruleHits = (array)($riskOut['rule_hits'] ?? []);
            $score = min(100, (int)round($score + min(20, count($ruleHits) * 4)));
            $ltv = compute_ltv($rec);
            $newMeta = ['imputed_fields' => $imputed, 'rule_hits' => $ruleHits, 'tags' => (array)($riskOut['tags'] ?? [])];
            if (isset($metadata['custom_fields']) && is_array($metadata['custom_fields'])) {
                $newMeta['custom_fields'] = $metadata['custom_fields'];
            }
            $upd->execute([$ltv, (string)($riskOut['risk_tier'] ?? 'Unknown'), $score, json_encode($newMeta, JSON_UNESCAPED_SLASHES), (int)$ph['id']]);
            $updated++;
            $offsetId = (int)$ph['id'];
        }
        if (($updated % 300) === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
        }
    }
    $pdo->commit();
    audit($pdo, (int)$user['id'], 'cron.recompute', ['updated' => $updated]);
    flash('success', 'Recompute completed. Updated policyholders: ' . $updated);
    redirect_to(['tab' => 'settings']);
}

/*
Summary:
- Risk tiers: rules run in priority order; matching rules can set risk_tier and add tags.
- Missing data: numeric mean + categorical mode imputation; imputed fields tracked in metadata.
- Confidence score: starts at 100, reduced for imputed/missing optional fields, boosted by rule hits.
- LTV: premium * remaining_months * cross_sell_multiplier(policy_type) * retention_adjustment.
- Segments: combine rule IDs (AND) + an optional validated WHERE-style filter.
- Campaign simulator: segment_size * rate * avg_offer_value * (1+lift) minus contact costs.
*/

