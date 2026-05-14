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
const ACCENT_COLOR = '#1A73E8';
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

const SAMPLE_CSV = "policy_number,name,dob,gender,policy_type,region,premium_amount,tenure_months,sum_assured\n"
    . "P-1001,Asha Rahman,1962-03-11,F,term,West,120.50,14,50000\n"
    . "P-1002,Noah Kim,1987-10-05,M,health,North,89.00,8,25000\n"
    . "P-1003,Maria Silva,,F,life,East,145.25,40,75000\n"
    . "P-1004,Li Wei,1959-07-21,M,term,,110.00,6,45000\n"
    . "P-1005,Sam Patel,1994-01-17,,auto,South,,12,18000\n";

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

session_init();
$pdo = db();
migrate($pdo);

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));
if ($action === '' && PHP_SAPI === 'cli') {
    $map = cli_kv($argv ?? []);
    if (isset($map['action'])) {
        $action = (string)$map['action'];
        $_GET = array_merge($_GET, $map);
    }
}

if ($action === 'download_sample_csv') {
    download_text('liteinsurance_sample.csv', SAMPLE_CSV, 'text/csv; charset=utf-8');
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
    $pdo = new PDO('sqlite:' . db_path(), null, null, [
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (\n"
        . "bucket TEXT PRIMARY KEY,\n"
        . "hits INTEGER NOT NULL,\n"
        . "reset_at INTEGER NOT NULL\n"
        . ")");

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_policyholders_policy_number ON policyholders(policy_number)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_policyholders_risk_tier ON policyholders(risk_tier)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_policyholders_region ON policyholders(region)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_ts ON audit_log(ts)');
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
    $avgLtv = (float)$pdo->query('SELECT COALESCE(AVG(ltv_estimate), 0) FROM policyholders')->fetchColumn();
    $highRisk = (int)$pdo->query("SELECT COUNT(*) FROM policyholders WHERE risk_tier = 'High'")->fetchColumn();

    echo '<div class="kpiGrid">';
    echo kpi('Total policyholders', (string)$total);
    echo kpi('Segments', (string)$segments);
    echo kpi('Avg LTV', CURRENCY_SYMBOL . number_format($avgLtv, 2));
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
        echo '<tr><td>' . h((string)$r['policy_number']) . '</td><td>' . h(mask_name((string)$r['name'])) . '</td><td>' . h((string)($r['region'] ?? '')) . '</td><td class="right">' . h(CURRENCY_SYMBOL . number_format((float)($r['ltv_estimate'] ?? 0), 2)) . '</td><td>' . h((string)($r['risk_tier'] ?? 'Unknown')) . '</td><td class="right">' . h((string)($r['confidence_score'] ?? 0)) . '</td></tr>';
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
    echo '<div class="card"><div class="h2">Missing data strategy</div><ul class="list"><li>Numeric: mean</li><li>Categorical: mode</li><li>Imputed tracked in metadata + <code>is_imputed</code></li></ul></div>';
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

    $st = $pdo->prepare('SELECT policy_number, name, age, policy_type, region, ltv_estimate, risk_tier, confidence_score, is_imputed FROM policyholders ' . $whereSql . ' ORDER BY id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset);
    $st->execute($args);
    $rows = $st->fetchAll();

    echo '<div class="card"><div class="h2">Policyholders</div>';
    echo '<form method="get" class="form"><input type="hidden" name="tab" value="profiles">';
    echo '<div class="grid2"><div><label class="label">Search</label><input class="input" name="q" value="' . h($q) . '" placeholder="Policy number or name"></div>';
    echo '<div><label class="label">Risk tier</label><input class="input" name="tier" value="' . h($tier) . '" placeholder="High"></div></div>';
    echo '<div class="grid2"><div><label class="label">Region</label><input class="input" name="region" value="' . h($region) . '" placeholder="West"></div>';
    echo '<div class="actionsRow" style="align-items:flex-end"><button class="btnSecondary" type="submit">Filter</button><a class="link" href="' . h(self_url(['tab' => 'profiles'])) . '">Reset</a></div></div>';
    echo '</form>';

    echo '<div class="muted">Showing ' . h((string)count($rows)) . ' of ' . h((string)$total) . '.</div>';
    echo '<div class="scrollX"><table class="table"><thead><tr><th>Policy</th><th>Name</th><th>Age</th><th>Type</th><th>Region</th><th class="right">LTV</th><th>Tier</th><th class="right">Conf</th><th>Imputed</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $low = (int)$r['confidence_score'] < 60;
        echo '<tr class="' . ($low ? 'rowLow' : '') . '">';
        echo '<td>' . h((string)$r['policy_number']) . '</td>';
        echo '<td>' . h(mask_name((string)$r['name'])) . '</td>';
        echo '<td>' . h((string)($r['age'] ?? '')) . '</td>';
        echo '<td>' . h((string)($r['policy_type'] ?? '')) . '</td>';
        echo '<td>' . h((string)($r['region'] ?? '')) . '</td>';
        echo '<td class="right">' . h(CURRENCY_SYMBOL . number_format((float)($r['ltv_estimate'] ?? 0), 2)) . '</td>';
        echo '<td>' . h((string)($r['risk_tier'] ?? 'Unknown')) . '</td>';
        echo '<td class="right">' . h((string)($r['confidence_score'] ?? 0)) . '</td>';
        echo '<td>' . ((int)$r['is_imputed'] === 1 ? '<span class="badge">Yes</span>' : '<span class="badge mutedBadge">No</span>') . '</td>';
        echo '</tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="9" class="muted">No policyholders found.</td></tr>';
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

    echo '<div class="card"><div class="h2">' . h($rid > 0 ? 'Edit rule' : 'New rule') . '</div>';
    echo '<form method="post" class="form">';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="rule_save">';
    if ($rid > 0) {
        echo '<input type="hidden" name="id" value="' . h((string)$rid) . '">';
    }
    echo '<label class="label">Name</label><input class="input" name="name" required maxlength="120" value="' . h($name) . '">';
    echo '<div class="grid2"><div><label class="label">Priority</label><input class="input" type="number" name="priority" min="0" max="1000" value="' . h((string)$priority) . '"></div>';
    echo '<div><label class="label">Enabled</label><select class="select" name="enabled"><option value="1"' . ($enabled === 1 ? ' selected' : '') . '>Yes</option><option value="0"' . ($enabled === 0 ? ' selected' : '') . '>No</option></select></div></div>';
    echo '<label class="label">Rule JSON</label><textarea class="textarea" name="definition_json" rows="12" spellcheck="false">' . h((string)$json) . '</textarea>';
    echo '<div class="muted tiny">Ops: =, !=, &gt;, &lt;, &gt;=, &lt;=, in, contains, regex. Group: {"all": [...]} / {"any": [...]} in conditions.</div>';
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
    echo '<div class="muted tiny">Allowed fields: risk_tier, region, policy_type, premium_amount, tenure_months, age, ltv_estimate, confidence_score.</div>';
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
            $imputed = is_array($meta['imputed_fields'] ?? null) ? (array)$meta['imputed_fields'] : [];
            $score = compute_confidence($rec, $imputed);
            $riskOut = evaluate_rules_for_record($rec, $rules);
            $ruleHits = (array)($riskOut['rule_hits'] ?? []);
            $score = min(100, (int)round($score + min(20, count($ruleHits) * 4)));
            $ltv = compute_ltv($rec);
            $newMeta = ['imputed_fields' => $imputed, 'rule_hits' => $ruleHits, 'tags' => (array)($riskOut['tags'] ?? [])];
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
        . ':root{--bg:#fff;--text:#111;--muted:#555;--border:#e6e6e6;--accent:' . $accent . ';}'
        . 'html,body{height:100%;}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;}'
        . '.appShell{display:grid;grid-template-columns:240px 1fr;grid-template-rows:56px 1fr;min-height:100vh;}'
        . '.topBar{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;padding:0 16px;border-bottom:1px solid var(--border);background:#fff;position:sticky;top:0;z-index:5;}'
        . '.brand{font-weight:600;letter-spacing:-0.02em;}'
        . '.topRight{display:flex;align-items:center;gap:12px;}'
        . '.userPill{display:flex;align-items:center;gap:8px;padding:6px 10px;border:1px solid var(--border);border-radius:999px;font-size:12px;}'
        . '.pillRole{padding:2px 8px;border:1px solid var(--border);border-radius:999px;color:var(--muted);}'
        . '.sideNav{border-right:1px solid var(--border);padding:12px;display:flex;flex-direction:column;gap:6px;}'
        . '.navItem{padding:10px 10px;border-radius:10px;text-decoration:none;color:var(--text);border:1px solid transparent;}'
        . '.navItem:hover{border-color:var(--border);background:#fafafa;}'
        . '.navOn{border-color:var(--border);background:#fff;box-shadow:0 1px 0 rgba(0,0,0,.03);font-weight:600;}'
        . '.main{padding:16px 16px 40px;max-width:1120px;}'
        . '.footer{grid-column:1/-1;border-top:1px solid var(--border);padding:14px 16px;color:var(--muted);font-size:12px;display:flex;gap:8px;align-items:center;}'
        . '.pageHead{margin-bottom:12px;}'
        . '.h1{margin:0 0 4px;font-size:20px;line-height:28px;}'
        . '.h2{font-size:14px;font-weight:600;margin:0 0 10px;}'
        . '.muted{color:var(--muted);font-size:13px;line-height:18px;}'
        . '.tiny{font-size:12px;}'
        . '.card{border:1px solid var(--border);border-radius:12px;padding:14px;background:#fff;}'
        . '.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}'
        . '.kpiGrid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:12px 0;}'
        . '.kpi{border:1px solid var(--border);border-radius:12px;padding:12px;background:#fff;}'
        . '.kpiLabel{color:var(--muted);font-size:12px;}'
        . '.kpiValue{font-size:18px;font-weight:600;margin-top:4px;}'
        . '.form{display:flex;flex-direction:column;gap:10px;margin-top:12px;}'
        . '.label{font-size:12px;color:var(--muted);margin-bottom:-6px;}'
        . '.input,.select,.textarea{width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:10px;padding:10px 10px;font-size:14px;outline:none;background:#fff;}'
        . '.input:focus,.select:focus,.textarea:focus{border-color:#111;box-shadow:0 0 0 2px rgba(0,0,0,.06);}'
        . '.textarea{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;font-size:12px;}'
        . '.btn{border:1px solid var(--accent);background:var(--accent);color:#fff;border-radius:10px;padding:10px 12px;font-weight:600;cursor:pointer;}'
        . '.btn:hover{filter:brightness(.95);}'
        . '.btnSecondary{border:1px solid var(--border);background:#fff;color:var(--text);border-radius:10px;padding:10px 12px;font-weight:600;cursor:pointer;}'
        . '.btnSecondary:hover{background:#fafafa;}'
        . '.inline{display:inline;}'
        . '.actionsRow{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:10px 0;}'
        . '.list{margin:10px 0 0;padding-left:18px;color:var(--muted);font-size:13px;}'
        . '.table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13px;}'
        . '.table th,.table td{border-bottom:1px solid var(--border);padding:10px 8px;text-align:left;vertical-align:top;white-space:nowrap;}'
        . '.table th{color:var(--muted);font-weight:600;font-size:12px;}'
        . '.right{text-align:right;}'
        . '.badge{display:inline-block;padding:2px 8px;border:1px solid var(--border);border-radius:999px;font-size:12px;}'
        . '.mutedBadge{color:var(--muted);}'
        . '.scrollX{overflow:auto;border:1px solid var(--border);border-radius:12px;}'
        . '.rowLow{background:#fafafa;}'
        . '.gridMap{display:grid;grid-template-columns:1fr 1fr;gap:10px;}'
        . '.mapRow{display:flex;flex-direction:column;gap:8px;}'
        . '.divider{height:1px;background:var(--border);margin:12px 0;}'
        . '.linkBtn{border:none;background:transparent;color:var(--accent);font-weight:600;cursor:pointer;padding:0;}'
        . '.linkBtn:hover{text-decoration:underline;}'
        . '.checkGrid{display:grid;grid-template-columns:1fr 1fr;gap:8px;border:1px solid var(--border);border-radius:12px;padding:10px;}'
        . '.check{display:flex;gap:8px;align-items:flex-start;font-size:13px;color:var(--text);}'
        . '.codeBlock{border:1px solid var(--border);border-radius:12px;padding:10px;background:#fff;overflow:auto;font-size:12px;}'
        . '.pager{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;}'
        . '.pagerOn,.pagerOff{padding:6px 10px;border-radius:10px;border:1px solid var(--border);text-decoration:none;color:var(--text);font-size:12px;}'
        . '.pagerOn{background:#fff;font-weight:700;}'
        . '.pagerOff{background:#fafafa;}'
        . '.swatch{display:inline-block;width:12px;height:12px;border-radius:3px;border:1px solid var(--border);vertical-align:-2px;margin-right:6px;}'
        . '.link{color:var(--accent);text-decoration:none;font-weight:600;}'
        . '.link:hover{text-decoration:underline;}'
        . '.flash{border:1px solid var(--border);border-left:4px solid #111;background:#fff;border-radius:12px;padding:10px 12px;margin:10px 0;font-size:13px;}'
        . '.flashOk{border-left-color:var(--accent);}'
        . '.flashErr{border-left-color:#111;}'
        . '.authWrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}'
        . '.authCard{width:100%;max-width:420px;border:1px solid var(--border);border-radius:16px;padding:18px;background:#fff;}'
        . '.authBrand{font-weight:700;letter-spacing:-0.02em;margin-bottom:6px;}'
        . '.authFooter{margin-top:14px;display:flex;flex-direction:column;gap:8px;}'
        . '@media (max-width:900px){.appShell{grid-template-columns:1fr;grid-template-rows:56px auto 1fr;}.sideNav{flex-direction:row;overflow:auto;white-space:nowrap;border-right:none;border-bottom:1px solid var(--border);} .grid2{grid-template-columns:1fr;} .kpiGrid{grid-template-columns:repeat(2,minmax(0,1fr));} .gridMap{grid-template-columns:1fr;} .checkGrid{grid-template-columns:1fr;}}'
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
        . 'out.textContent="Running…";'
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
    $pattern = "/\\G\\s*(?:(?<lp>\\()|(?<rp>\\))|(?<comma>,)|(?<op>>=|<=|!=|=|>|<)|(?<kw>AND|OR|NOT|LIKE|IN|IS|NULL)\\b|(?<num>-?\\d+(?:\\.\\d+)?)|(?<str>'(?:''|[^'])*')|(?<id>[A-Za-z_][A-Za-z0-9_]*))\\s*/Ai";
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
        'policy_number' => guess_header($headers, ['policy_number', 'policy', 'policy no', 'policy#']),
        'name' => guess_header($headers, ['name', 'full name']),
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
        'policy_number' => 'Policy number (required)',
        'name' => 'Name (required)',
        'dob' => 'DOB (YYYY-MM-DD)',
        'age' => 'Age',
        'gender' => 'Gender',
        'policy_type' => 'Policy type',
        'region' => 'Region',
        'premium_amount' => 'Premium amount',
        'tenure_months' => 'Tenure (months)',
        'sum_assured' => 'Sum assured',
    ];

    echo '<div class="card"><div class="h2">Map columns</div><div class="muted">Staged file: <strong>' . h($filename) . '</strong></div>';
    echo '<form method="post" class="form">';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="import_preview">';
    echo '<div class="gridMap">';
    foreach ($fields as $key => $label) {
        echo '<div class="mapRow"><div class="label">' . h($label) . '</div><select class="select" name="map[' . h($key) . ']">';
        echo '<option value="">— not provided —</option>';
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
        echo '<div class="kpiGrid">' . kpi('Rows detected', (string)($stats['rows_total'] ?? 0)) . kpi('Hard rejects (preview)', (string)($stats['hard_rejects_preview'] ?? 0)) . kpi('Soft warnings (preview)', (string)($stats['soft_warnings_preview'] ?? 0)) . kpi('Mean premium', CURRENCY_SYMBOL . number_format((float)($stats['mean_premium'] ?? 0), 2)) . '</div>';
        $cols = ['policy_number', 'name', 'age', 'gender', 'policy_type', 'region', 'premium_amount', 'tenure_months', 'sum_assured', 'ltv_estimate', 'risk_tier', 'confidence_score', 'is_imputed'];
        echo '<div class="scrollX"><table class="table"><thead><tr>';
        foreach ($cols as $c) {
            echo '<th>' . h($c) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $low = (int)($r['confidence_score'] ?? 0) < 60;
            echo '<tr class="' . ($low ? 'rowLow' : '') . '">';
            foreach ($cols as $c) {
                $v = $r[$c] ?? '';
                if (in_array($c, ['premium_amount', 'sum_assured', 'ltv_estimate'], true)) {
                    $v = CURRENCY_SYMBOL . number_format((float)$v, 2);
                }
                echo '<td>' . h((string)$v) . '</td>';
            }
            echo '</tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="' . h((string)count($cols)) . '" class="muted">No rows to preview.</td></tr>';
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

function map_row(array $headers, array $row, array $mapping): array
{
    $idx = header_index_map($headers);
    $out = [];
    foreach ($mapping as $field => $hname) {
        $hname = (string)$hname;
        if ($hname === '' || !isset($idx[$hname])) {
            continue;
        }
        $pos = (int)$idx[$hname];
        $out[$field] = $row[$pos] ?? '';
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
        return 'Missing policy_number';
    }
    if ((string)($r['name'] ?? '') === '') {
        return 'Missing name';
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

function compute_ltv(array $r): float
{
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
    $rowsTotal = 0;
    $fh = fopen($path, 'rb');
    if (!$fh) {
        return ['rows_total' => 0];
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
    return ['rows_total' => $rowsTotal, 'means' => $means, 'modes' => $modes, 'mean_premium' => $means['premium_amount'] ?? 0];
}

function normalize_and_impute(array $rec, array $defaults, array $analysis, array &$imputedFields): array
{
    $out = [];
    $out['policy_number'] = trim((string)($rec['policy_number'] ?? ''));
    $out['name'] = trim((string)($rec['name'] ?? ''));
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

    if ($out['premium_amount'] === null) {
        $out['premium_amount'] = (float)($analysis['means']['premium_amount'] ?? 0);
        $imputedFields[] = 'premium_amount';
    }
    if ($out['tenure_months'] === null) {
        $out['tenure_months'] = (int)round((float)($analysis['means']['tenure_months'] ?? 0));
        $imputedFields[] = 'tenure_months';
    }
    if ($out['sum_assured'] === null && (float)($analysis['means']['sum_assured'] ?? 0) > 0) {
        $out['sum_assured'] = (float)($analysis['means']['sum_assured'] ?? 0);
        $imputedFields[] = 'sum_assured';
    }
    if ($out['policy_type'] === '') {
        $mode = (string)($analysis['modes']['policy_type'] ?? '');
        $out['policy_type'] = $mode !== '' ? $mode : (string)($defaults['policy_type'] ?? 'term');
        $imputedFields[] = 'policy_type';
    }
    if ($out['region'] === '') {
        $mode = (string)($analysis['modes']['region'] ?? '');
        $out['region'] = $mode !== '' ? $mode : (string)($defaults['region'] ?? 'Unknown');
        $imputedFields[] = 'region';
    }
    if ($out['gender'] === '') {
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
    while (($row = fgetcsv($fh)) !== false) {
        $rec = map_row($headers, $row, $mapping);
        $imputed = [];
        $validated = normalize_and_impute($rec, $defaults, $analysis, $imputed);
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
            'premium_amount' => (float)($validated['premium_amount'] ?? 0),
            'tenure_months' => (int)($validated['tenure_months'] ?? 0),
            'sum_assured' => (float)($validated['sum_assured'] ?? 0),
            'ltv_estimate' => (float)$ltv,
            'risk_tier' => (string)($riskOut['risk_tier'] ?? 'Unknown'),
            'confidence_score' => $score,
            'is_imputed' => count($imputed) > 0 ? 1 : 0,
        ];
        if (count($rows) >= IMPORT_PREVIEW_ROWS) {
            break;
        }
    }
    fclose($fh);
    return ['analysis' => $analysis, 'stats' => ['rows_total' => (int)($analysis['rows_total'] ?? 0), 'hard_rejects_preview' => $hard, 'soft_warnings_preview' => $soft, 'mean_premium' => (float)($analysis['mean_premium'] ?? 0)], 'rows' => $rows];
}

function policy_number_exists(PDO $pdo, string $policyNumber): bool
{
    $st = $pdo->prepare('SELECT 1 FROM policyholders WHERE policy_number = ? LIMIT 1');
    $st->execute([$policyNumber]);
    return (bool)$st->fetchColumn();
}

function policyholder_record_from_row(array $ph): array
{
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
    $left = $record[$field] ?? null;
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
    if (!$hasRules) {
        foreach (sample_rules_seed() as $r) {
            $def = (array)$r['definition'];
            $condsPayload = ['logic' => (string)($def['logic'] ?? 'AND'), 'conditions' => (array)($def['conditions'] ?? [])];
            $condsJson = json_encode($condsPayload, JSON_UNESCAPED_SLASHES);
            $actsJson = json_encode((array)($def['actions'] ?? []), JSON_UNESCAPED_SLASHES);
            $st = $pdo->prepare('INSERT INTO rules (name, conditions_json, actions_json, priority, enabled, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $st->execute([(string)$r['name'], $condsJson, $actsJson, (int)$r['priority'], (int)$r['enabled'], now_iso()]);
        }
    }
    if (!ensure_upload_dir()) {
        flash('error', 'Upload directory is not writable.');
        redirect_to(['tab' => 'import']);
    }
    $path = upload_dir() . DIRECTORY_SEPARATOR . 'sample_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
    file_put_contents($path, SAMPLE_CSV);
    $lines = preg_split('/\r?\n/', trim(SAMPLE_CSV));
    $headers = $lines ? str_getcsv($lines[0]) : [];
    $_SESSION['import_stage'] = ['path' => $path, 'filename' => 'liteinsurance_sample.csv', 'headers' => $headers, 'defaults' => ['region' => 'Unknown', 'policy_type' => 'term']];
    audit($pdo, (int)$user['id'], 'sample.load', ['rules_seeded' => !$hasRules]);
    flash('success', 'Sample staged. Go to Preview → Confirm to import.');
    redirect_to(['tab' => 'import']);
}

function sample_rules_seed(): array
{
    return [
        [
            'name' => 'Senior + short tenure = High',
            'priority' => 10,
            'enabled' => 1,
            'definition' => [
                'logic' => 'AND',
                'conditions' => [
                    ['field' => 'age', 'op' => '>=', 'value' => 60],
                    ['field' => 'tenure_months', 'op' => '<', 'value' => 12],
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
    ];
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
        $validated = normalize_and_impute($rec, (array)$stage['defaults'], $analysis, $imputed);
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
        $meta = ['imputed_fields' => array_values($imputed), 'rule_hits' => $ruleHits, 'tags' => (array)($riskOut['tags'] ?? [])];
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
    audit($pdo, (int)$user['id'], 'import.commit', ['filename' => $filename, 'rows_total' => $rowsTotal, 'rows_imported' => $rowsImported, 'import_id' => $importId]);
    $_SESSION['import_stage'] = null;
    flash('success', 'Import completed: ' . $rowsImported . ' row(s) imported.');
    redirect_to(['tab' => 'import']);
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
    echo json_encode(['ok' => true, 'record' => ['policy_number' => $rec['policy_number'], 'region' => $rec['region'], 'age' => $rec['age'], 'policy_type' => $rec['policy_type']], 'result' => $out], JSON_UNESCAPED_SLASHES);
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
    $rules = load_rules($pdo);
    $selected = array_values(array_filter($rules, fn($r) => in_array((int)($r['id'] ?? 0), $ruleIds, true)));
    if (!$selected) {
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
        compile_filter_expr($where);
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
        compile_filter_expr($where);
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
    try {
        [$filterSql, $filterParams] = compile_filter_expr($where);
    } catch (Throwable $e) {
        $filterSql = '1=1';
        $filterParams = [];
    }
    $rules = load_rules($pdo);
    $selected = array_values(array_filter($rules, fn($r) => in_array((int)($r['id'] ?? 0), $ruleIds, true)));

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="segment_' . (int)$seg['id'] . '_export.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['policy_number', 'name', 'age', 'gender', 'policy_type', 'region', 'premium_amount', 'tenure_months', 'sum_assured', 'ltv_estimate', 'risk_tier', 'confidence_score']);

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
            $ok = true;
            foreach ($selected as $rule) {
                if (!eval_conditions($rec, (array)($rule['conditions'] ?? []))) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                fputcsv($out, [
                    $rec['policy_number'], $rec['name'], (string)($rec['age'] ?? ''), $rec['gender'], $rec['policy_type'], $rec['region'],
                    (string)($rec['premium_amount'] ?? ''), (string)($rec['tenure_months'] ?? ''), (string)($rec['sum_assured'] ?? ''),
                    (string)($rec['ltv_estimate'] ?? ''), $rec['risk_tier'], (string)$rec['confidence_score'],
                ]);
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
            $imputed = is_array($meta['imputed_fields'] ?? null) ? (array)$meta['imputed_fields'] : [];
            $score = compute_confidence($rec, $imputed);
            $riskOut = evaluate_rules_for_record($rec, $rules);
            $ruleHits = (array)($riskOut['rule_hits'] ?? []);
            $score = min(100, (int)round($score + min(20, count($ruleHits) * 4)));
            $ltv = compute_ltv($rec);
            $newMeta = ['imputed_fields' => $imputed, 'rule_hits' => $ruleHits, 'tags' => (array)($riskOut['tags'] ?? [])];
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

