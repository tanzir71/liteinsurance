<?php
declare(strict_types=1);

/*
LiteInsurance — single-file PHP + SQLite (PDO) app for shared hosting
Copyright (c) 2026 Tanzir Rahman. MIT License — see LICENSE (https://github.com/tanzir71/liteinsurance).

Deploy:
1) Upload `liteinsurance.php` into `public_html/`.
2) Ensure PHP 8.1+ (8.3 recommended) with PDO + SQLite enabled.
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
const APP_VERSION = '1.2';
const DB_PATH = __DIR__ . DIRECTORY_SEPARATOR . 'liteinsurance.db';
const UPLOAD_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'liteinsurance_uploads';
const ACCENT_COLOR = '#2540ff';
const LI_CURRENCY_SYMBOL = '$'; // default only: set it in Settings or CURRENCY_SYMBOL= in .env (the bare constant name collides with a PHP built-in)
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

$action = request_action();
if ($action === '' && PHP_SAPI === 'cli') {
    $map = cli_kv($argv ?? []);
    if (isset($map['action'])) {
        $action = (string)$map['action'];
        $_GET = array_merge($_GET, $map);
    }
}

if ($action === 'download_sample_csv') {
    if (PHP_SAPI === 'cli' && (string)($_GET['today'] ?? '') !== '') {
        $GLOBALS['LI_SAMPLE_TODAY'] = (string)$_GET['today'];
    }
    download_text('liteinsurance_sample.csv', sample_csv(), 'text/csv; charset=utf-8');
    exit;
}
if ($action === 'parity_vectors' && PHP_SAPI === 'cli') {
    echo json_encode(parity_vectors((string)($_GET['today'] ?? '')), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

session_init();
try {
    $pdo = db();
    migrate($pdo);
    $GLOBALS['LI_PDO'] = $pdo;
    load_settings($pdo);
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
    migrate_v11($pdo);
    migrate_v12($pdo);
}

function route_web(PDO $pdo): void
{
    $action = request_action();
    $tab = normalize_tab((string)($_GET['tab'] ?? 'dashboard'));
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
        $regOpen = registration_open($pdo);
        if ($action === 'register') {
            if (!$regOpen) {
                flash('error', 'Registration is closed. Ask an admin to open it in Settings.');
                redirect_to(['action' => 'login_form']);
            }
            handle_register($pdo);
            return;
        }
        if ($action === 'login') {
            handle_login($pdo);
            return;
        }
        if ($action === 'totp_verify') {
            handle_totp_verify($pdo);
            return;
        }
        if ($action === 'totp_form' && is_array($_SESSION['pending_2fa'] ?? null)) {
            render_auth_page('totp', $hasUsers);
            return;
        }
        $mode = ($action === 'register_form' && $regOpen) ? 'register' : 'login';
        render_auth_page($mode, $hasUsers);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
    }

    $accountActions = ['totp_begin', 'totp_enable', 'totp_cancel', 'totp_disable', 'totp_recovery_regen', 'password_change'];
    if (in_array($action, $accountActions, true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect_to(['action' => null, 'tab' => 'account']);
        }
        handle_account_action($pdo, $user, $action);
        return;
    }
    if (totp_required_for($user)) {
        if ($action !== '') {
            redirect_to(['action' => null, 'tab' => 'account']);
        }
        render_app($pdo, $user, 'account');
        return;
    }
    if (in_array($action, ['user_update', 'retention_run', 'privacy_erase', 'data_wipe'], true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect_to(['action' => null]);
        }
        require_admin($user);
        if ($action === 'user_update') {
            handle_user_update($pdo, $user);
        } else {
            handle_retention_action($pdo, $user, $action);
        }
        return;
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

    $adminActions = [
        'segment_count' => 'handle_segment_count',
        'campaign_delete' => 'handle_campaign_delete',
        'settings_save' => 'handle_settings_save',
        'suppression_apply' => 'handle_suppression_apply',
        'audit_export' => 'handle_audit_export',
        'checklist_dismiss' => 'handle_checklist_dismiss',
        'book_export' => 'handle_book_export',
        'bulk_update' => 'handle_bulk_update',
    ];
    $userActions = [
        'renewal_update' => 'handle_renewal_update',
        'renewal_export_calls' => 'handle_renewal_export_calls',
        'renewal_export_ics' => 'handle_renewal_export_ics',
        'contact_log' => 'handle_contact_log',
        'consent_update' => 'handle_consent_update',
        'reveal_pii' => 'handle_reveal_pii',
    ];
    if (isset($adminActions[$action]) || isset($userActions[$action])) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect_to(['action' => null]);
        }
        if (isset($adminActions[$action])) {
            require_admin($user);
            if ($action === 'segment_count') {
                handle_segment_count($pdo);
                return;
            }
            $adminActions[$action]($pdo, $user);
            return;
        }
        $userActions[$action]($pdo, $user);
        return;
    }

    render_app($pdo, $user, $tab);
}

function render_auth_page(string $mode, bool $hasUsers): void
{
    $isRegister = $mode === 'register';
    $isTotp = $mode === 'totp';
    $title = $isRegister ? 'Create account' : ($isTotp ? 'Two-step verification' : 'Sign in');
    $subtitle = $isRegister ? ($hasUsers ? 'Create a new user.' : 'Create the first user (Admin).') : ($isTotp ? 'Enter the 6-digit code from your authenticator app, or a recovery code.' : 'Sign in to access the dashboard.');
    $pdoForAuth = $GLOBALS['LI_PDO'] ?? null;
    $regOpen = $pdoForAuth instanceof PDO ? registration_open($pdoForAuth) : !$hasUsers;
    $flash = consume_flash();
    $csrf = csrf_token();

    echo '<!doctype html><html lang="en"><head>';
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h(APP_NAME . ' — ' . $title) . '</title>';
    echo '<link rel="icon" href="data:image/svg+xml,' . rawurlencode(logo_svg(false)) . '">';
    echo inter_font_css() . app_css();
    echo '</head><body>';
    echo '<main class="authWrap"><div class="authCard">';
    echo '<div class="authBrand">' . logo_svg(false) . '<span>' . h(APP_NAME) . '</span></div>';
    echo '<h1 class="h1">' . h($title) . '</h1>';
    echo '<div class="muted">' . h($subtitle) . '</div>';
    if ($flash) {
        echo render_flash($flash);
    }
    echo '<form method="post" class="form" autocomplete="on" action="' . h(self_url(['action' => null])) . '">';
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">';
    if ($isTotp) {
        echo '<input type="hidden" name="action" value="totp_verify">';
        echo '<label class="label" for="totpCode">Code</label><input class="input inputCode" id="totpCode" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="11" required autofocus>';
        echo '<button class="btn" type="submit">Verify</button>';
        echo '</form><div class="authFooter"><a class="link" href="' . h(self_url(['action' => 'login_form'])) . '">Start over</a><div class="tiny muted">Lost your phone? Use a recovery code, or ask an admin to reset two-step verification.</div></div>';
        echo '</div></main>';
        echo app_js();
        echo '</body></html>';
        return;
    }
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
    } elseif ($regOpen) {
        echo '<a class="link" href="' . h(self_url(['action' => 'register_form'])) . '">Create an account</a>';
    } else {
        echo '<div class="tiny muted">New here? Ask an admin to invite you.</div>';
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
    $full = user_row($pdo, (int)$u['id']) ?? $u;
    if ((int)($full['totp_enabled'] ?? 0) === 1) {
        session_regenerate_id(true);
        $_SESSION['pending_2fa'] = ['uid' => (int)$u['id'], 'ts' => time()];
        redirect_to(['action' => 'totp_form']);
    }
    complete_login($pdo, $full, 'password');
}

function current_user(PDO $pdo): ?array
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT id, email, role, created_at, totp_enabled FROM users WHERE id = ?');
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

function import_stepper(int $current, string $meta, string $reviewLabel = 'Review'): string
{
    $steps = ['Upload', 'Map columns', $reviewLabel, 'Import'];
    $out = '<div class="card stepper" aria-label="Import progress">';
    foreach ($steps as $i => $label) {
        $n = $i + 1;
        $cls = $n < $current ? 'done' : ($n === $current ? 'now' : '');
        $out .= ($i ? '<span class="stpLine"></span>' : '') . '<span class="stp ' . $cls . '"' . ($n === $current ? ' aria-current="step"' : '') . '><i>' . ($n < $current ? '✓' : $n) . '</i>' . h($label) . '</span>';
    }
    return $out . ($meta !== '' ? '<span class="stpMeta">' . h($meta) . '</span>' : '') . '</div>';
}

function stage_scan(array $stage): array
{
    $out = ['rows' => 0, 'samples' => [], 'filled' => []];
    $fh = @fopen((string)($stage['path'] ?? ''), 'rb');
    if (!$fh) {
        return $out;
    }
    $headers = fgetcsv($fh);
    if (!is_array($headers)) {
        fclose($fh);
        return $out;
    }
    $headers = array_map(fn($x) => trim(strip_bom((string)$x)), $headers);
    while (($row = fgetcsv($fh)) !== false && $out['rows'] < 200000) {
        if ($row === [null]) {
            continue;
        }
        $out['rows']++;
        foreach ($headers as $i => $hname) {
            $v = trim((string)($row[$i] ?? ''));
            if ($v === '') {
                continue;
            }
            $out['filled'][$hname] = ($out['filled'][$hname] ?? 0) + 1;
            if (!isset($out['samples'][$hname])) {
                $out['samples'][$hname] = $v;
            }
        }
    }
    fclose($fh);
    return $out;
}

function mask_sample(string $field, string $v): string
{
    if ($field === 'display_name') {
        return mask_name($v);
    }
    if ($field === 'email') {
        return mask_email($v);
    }
    if ($field === 'phone') {
        return mask_phone($v);
    }
    if ($field === 'dob') {
        return substr($v, 0, 4) . '-••-••';
    }
    return mb_strimwidth($v, 0, 28, '…');
}

function render_tab_import(PDO $pdo): void
{
    $stage = $_SESSION['import_stage'] ?? null;
    $hasStage = is_array($stage) && isset($stage['headers'], $stage['path']);
    $hasPreview = $hasStage && isset($stage['preview']) && is_array($stage['preview']) && (string)($_GET['remap'] ?? '') !== '1';
    if ($hasStage) {
        $headers = array_values(array_filter(array_map(fn($x) => trim(strip_bom((string)$x)), (array)$stage['headers']), fn($x) => $x !== ''));
        $scan = stage_scan($stage);
        $meta = (string)($stage['filename'] ?? 'upload.csv') . ' · ' . number_format($scan['rows']) . ' rows · ' . count($headers) . ' columns';
        if ($hasPreview) {
            $issues = (int)($stage['preview']['stats']['hard_rejects_preview'] ?? 0) + (int)($stage['preview']['stats']['missing_expiry'] ?? 0);
            echo import_stepper(3, $meta, $issues > 0 ? 'Review ' . number_format($issues) . ' issues' : 'Review');
            render_import_review($pdo, $stage, $scan);
        } else {
            echo import_stepper(2, $meta);
            render_import_mapping($pdo, $stage);
        }
    } else {
        echo import_stepper(1, '');
        echo '<div class="importGrid"><div class="card"><div class="h2">Upload your export</div><div class="muted">A CSV from your agency management system or any spreadsheet. Max ' . h((string)round(MAX_UPLOAD_BYTES / 1024 / 1024, 1)) . ' MB; staged in <code>' . h(basename(upload_dir())) . '/</code> and deleted once imported.</div>';
        echo '<form method="post" enctype="multipart/form-data" class="uploadBox">' . csrf_field() . '<input type="hidden" name="action" value="import_upload"><input class="input" type="file" name="csv" accept=".csv,text/csv" required aria-label="CSV file"><button class="btn" type="submit">Upload and map columns</button></form>';
        echo '<form method="post" class="actionsRow">' . csrf_field() . '<input type="hidden" name="action" value="load_sample"><button class="btnSecondary" type="submit">Load the sample book (200 policies)</button><span class="muted tiny">Same file as Download sample CSV, with rules and lists.</span></form></div>';
        echo '<div class="card"><div class="eyebrow">What will happen</div><div class="happen happenText">';
        foreach ([['↻', '', 'Re-imports update in place', 'Matched on Record ID; renewal status, owner, contact history and opt-outs are kept'], ['+', 'ok', 'Every extra column is kept', 'Unmapped columns become custom fields you can use in rules and lists'], ['!', 'warn', 'Gaps are filled, then flagged', 'Numbers use the book mean, labels the most common value; scored with lower confidence']] as [$ic, $tone, $t, $s]) {
            echo '<div class="item"><span class="ic ' . $tone . '">' . h($ic) . '</span><div><b>' . h($t) . '</b><span>' . h($s) . '</span></div></div>';
        }
        echo '</div></div></div>';
    }

    render_import_health($pdo);

    $imports = $pdo->query('SELECT filename, rows_total, rows_imported, rows_updated, created_at, errors_json FROM imports ORDER BY created_at DESC, id DESC LIMIT 12')->fetchAll();
    echo '<div class="card cardFlush"><div class="cardHeadPad"><div><div class="h2">Import history</div><div class="muted">Each import is recorded with its counts and first issue.</div></div></div>';
    echo '<div class="scrollX"><table class="t2"><thead><tr><th>When</th><th>File</th><th class="r">Rows</th><th class="r">New</th><th class="r">Updated</th><th>Issues</th></tr></thead><tbody>';
    foreach ($imports as $r) {
        $errors = safe_json((string)($r['errors_json'] ?? '[]'));
        $n = is_array($errors) ? count($errors) : 0;
        $first = ($n && is_array($errors[0] ?? null)) ? ('row ' . (int)($errors[0]['row'] ?? 0) . ': ' . (string)($errors[0]['msg'] ?? '')) : '';
        echo '<tr><td>' . h(date_dmy((string)$r['created_at'])) . ' <span class="muted tiny">' . h(substr((string)$r['created_at'], 11, 5)) . '</span></td><td class="mono">' . h((string)$r['filename']) . '</td><td class="r">' . h(number_format((int)$r['rows_total'])) . '</td><td class="r">' . h(number_format((int)$r['rows_imported'] - (int)($r['rows_updated'] ?? 0))) . '</td><td class="r">' . h(number_format((int)($r['rows_updated'] ?? 0))) . '</td><td class="muted"' . ($first !== '' ? ' title="' . h($first) . '"' : '') . '>' . h($n ? ($n . ' issue(s) · ' . mb_strimwidth($first, 0, 60, '…')) : '—') . '</td></tr>';
    }
    if (!$imports) {
        echo '<tr><td colspan="6" class="empty">No imports yet.</td></tr>';
    }
    echo '</tbody></table></div></div>';
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

    $rules = $pdo->query('SELECT id, name, priority, enabled, conditions_json, actions_json FROM rules ORDER BY priority DESC, id DESC')->fetchAll();
    $hitCounts = [];
    try {
        foreach ($pdo->query("SELECT CAST(je.value AS INTEGER) AS rid, COUNT(*) AS c FROM policyholders, json_each(policyholders.metadata, '$.rule_hits') AS je GROUP BY rid")->fetchAll() as $hc) {
            $hitCounts[(int)$hc['rid']] = (int)$hc['c'];
        }
    } catch (Throwable $e) {
        $hitCounts = [];
    }

    echo '<div class="grid2 gridWide">';
    echo '<div class="card"><div class="h2">Rules, in the order they run</div><div class="muted">Highest priority runs first. The first matching rule that sets a tier wins; tags and score deltas add up; a rule marked <em>stop</em> ends evaluation. Recompute after editing.</div>';
    echo '<div class="scrollX"><table class="table wrapCells"><thead><tr><th>#</th><th>Rule</th><th class="right">Priority</th><th class="right">Matches</th><th>On</th><th></th></tr></thead><tbody>';
    $order = 0;
    foreach ($rules as $r) {
        $order++;
        $acts = safe_json((string)($r['actions_json'] ?? '{}'));
        echo '<tr><td class="mono muted">' . h((string)$order) . '</td><td><div>' . h((string)$r['name']) . '</div><div class="muted tiny">' . h(mb_strimwidth('if ' . rule_conditions_summary(safe_json((string)($r['conditions_json'] ?? '{}'))) . ' → ' . rule_actions_summary(is_array($acts) ? $acts : []), 0, 110, '…')) . '</div></td><td class="right">' . h((string)$r['priority']) . '</td><td class="right">' . h(number_format($hitCounts[(int)$r['id']] ?? 0)) . '</td>';
        echo '<td>' . ((int)$r['enabled'] === 1 ? '<span class="badge">Yes</span>' : '<span class="badge mutedBadge">No</span>') . '</td>';
        echo '<td>';
        echo '<a class="link" href="' . h(self_url(['tab' => 'rules', 'edit' => (string)$r['id']])) . '">Edit</a> · ';
        echo '<form method="post" class="inline"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="rule_toggle"><input type="hidden" name="id" value="' . h((string)$r['id']) . '"><button class="linkBtn" type="submit">' . h(((int)$r['enabled'] === 1) ? 'Disable' : 'Enable') . '</button></form> · ';
        echo '<form method="post" class="inline" data-confirm="Delete this rule?"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="rule_delete"><input type="hidden" name="id" value="' . h((string)$r['id']) . '"><button class="linkBtn" type="submit">Delete</button></form>';
        echo '</td></tr>';
    }
    if (!$rules) {
        echo '<tr><td colspan="6" class="muted">No rules yet.</td></tr>';
    }
    echo '</tbody></table></div></div>';

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
        'days_to_expiry' => 'Days to expiry',
        'status' => 'Policy status',
        'line_of_business' => 'Line of business',
        'carrier' => 'Carrier',
        'producer' => 'Producer',
        'policies_in_household' => 'Policies in household',
        'prior_premium' => 'Prior premium',
        'consent_email' => 'Email consent (1/0)',
        'consent_sms' => 'SMS consent (1/0)',
        'consent_phone' => 'Call consent (1/0)',
        'opted_out' => 'Opted out (1/0)',
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

    echo '<div class="card" id="ruleEditor"><div class="h2">' . h($rid > 0 ? 'Edit rule' : 'New rule') . '</div>';
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
    echo '<div class="ruleModePanel" id="ruleVisualPanel"><div class="grid2"><div><label class="label">Match logic</label><select class="select" id="ruleLogicVisual"><option value="AND">All conditions</option><option value="OR">Any condition</option></select></div><div class="inlineCheck"><input type="checkbox" id="ruleAdvancedJsonNotice" disabled><span>Nested groups stay in Plain JSON</span></div></div><div id="ruleConditionsVisual"></div><div class="actionsRow"><button class="btnSecondary" type="button" id="addRuleCondition">Add condition</button></div><div class="ruleActionGrid"><div><label class="label">Set tier</label><select class="select" id="ruleActionTier"><option value="">No change</option><option>Low</option><option>Medium</option><option>High</option><option>Review</option><option>Unknown</option></select></div><div><label class="label">Score delta</label><input class="input" id="ruleActionScore" type="number" min="-100" max="100" value="0"></div><div><label class="label">Add tag</label><input class="input" id="ruleActionTag" maxlength="80" placeholder="senior_short_tenure"></div><label class="inlineCheck"><input type="checkbox" id="ruleActionStop"> Stop after this rule</label></div></div>';
    echo '<div class="ruleModePanel" id="ruleJsonPanel" hidden><label class="label">Rule JSON</label><textarea class="textarea" name="definition_json" rows="12" spellcheck="false">' . h((string)$json) . '</textarea><div class="muted tiny">Ops: =, !=, &gt;, &lt;, &gt;=, &lt;=, in, contains, regex. Use custom CSV fields as custom.agent_code. Group: {"all": [...]} / {"any": [...]} in conditions. Actions: set_risk_tier, score_delta, add_tag, stop (true ends evaluation).</div></div>';
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
    echo '<div><button class="btnSecondary" type="button" data-rule-test>Run test</button></div>';
    echo '<pre class="codeBlock" id="ruleTestOut">Ready.</pre>';

    echo '</form></div>';
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

    $add('PHP version', PHP_VERSION_ID >= 80100 ? 'ok' : (PHP_VERSION_ID >= 80000 ? 'warn' : 'fail'), PHP_VERSION, 'Use PHP 8.1 or newer (8.3 recommended). PHP 8.0 is end-of-life.');
    $add('PDO SQLite', extension_loaded('pdo_sqlite') ? 'ok' : 'fail', extension_loaded('pdo_sqlite') ? 'Enabled' : 'Missing', 'Enable the pdo_sqlite extension in your hosting control panel.');
    $fontsOk = is_file(__DIR__ . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'inter-400.woff');
    $add('Fonts', $fontsOk ? 'ok' : 'warn', $fontsOk ? 'Self-hosted Inter found in fonts/.' : 'fonts/ folder not found; the system font stack is used.', 'Optional: upload the fonts/ folder next to liteinsurance.php for the designed typeface. No third-party font requests are made either way.');

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
    $updated = recompute_all_policyholders($pdo);
    $lists = 0;
    foreach ($pdo->query('SELECT id, rule_ids FROM segments')->fetchAll() as $seg) {
        try {
            [$ruleIds, $where] = segment_criteria($seg);
            $stats = segment_stats($pdo, $ruleIds, $where);
            $c = safe_json((string)$seg['rule_ids']);
            $c = is_array($c) ? $c : [];
            $c['stats'] = ['count' => $stats['count'], 'email' => $stats['email'], 'sms' => $stats['sms'], 'phone' => $stats['phone'], 'mail' => $stats['mail']];
            $pdo->prepare('UPDATE segments SET rule_ids = ?, last_run_at = ? WHERE id = ?')->execute([json_encode($c, JSON_UNESCAPED_SLASHES), now_iso(), (int)$seg['id']]);
            $lists++;
        } catch (Throwable $e) {
            li_log('cron.segment_error', ['id' => (int)$seg['id'], 'message' => $e->getMessage()]);
        }
    }
    $ret = run_retention($pdo, true);
    echo 'OK. Updated policyholders: ' . $updated . '. Lists refreshed: ' . $lists . '. Retention removed: ' . $ret['uploads'] . ' uploads, ' . $ret['audit'] . ' audit events, ' . $ret['contacts'] . ' contact entries, ' . $ret['policies'] . ' policies.';
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

function money_or_blank($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return currency_symbol() . number_format((float)$value, 2);
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
    // Self-hosted fonts: no third-party requests. If the optional fonts/ folder (Inter + JetBrains Mono, SIL OFL)
    // sits next to this file it is used; otherwise the system font stack takes over.
    return '';
}

function app_font_faces(): string
{
    $base = 'fonts/';
    $faces = [['Inter', 400, 'inter-400.woff', 'Inter Regular'], ['Inter', 500, 'inter-500.woff', 'Inter Medium'], ['Inter', 600, 'inter-600.woff', 'Inter SemiBold'], ['JetBrains Mono', 400, 'jetbrains-mono-400.woff', 'JetBrains Mono Regular']];
    $css = '';
    foreach ($faces as [$family, $weight, $file, $local]) {
        $css .= '@font-face{font-family:"' . $family . '";font-style:normal;font-weight:' . $weight . ';font-display:swap;src:local("' . $local . '"),url("' . $base . $file . '") format("woff");}';
    }
    return $css;
}

function app_css(): string
{
    $accent = h(ACCENT_COLOR);
    $nonce = h((string)($GLOBALS['CSP_NONCE'] ?? ''));
    $css = <<<'CSS'
:root{--ink:#0B0D12;--ink2:#1F2430;--slate:#4B5262;--muted:#6B7280;--faint:#9AA0AB;--line:#E4E2DA;--line2:#EEECE6;--paper:#F6F5F0;--white:#FFFFFF;--acc:__ACCENT__;--accInk:#1A2FD6;--acc50:#EEF1FF;--acc100:#DCE2FF;--nav:#0A0E1A;--nav2:#111729;--navLine:#232B40;--navInk:#F3F4F7;--navMute:#8E95A8;--green:#157F3B;--green50:#E7F4EC;--amber:#B45309;--amber50:#FDF2E4;--red:#B42318;--red50:#FCEBE9;--r:4px;--mono:"JetBrains Mono",ui-monospace,Menlo,Consolas,monospace;--sans:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;--bg:var(--paper);--panel:var(--white);--text:var(--ink);--border:var(--line);--borderSoft:var(--line2);--accent:var(--acc);}
*{box-sizing:border-box;}html,body{height:100%;}
body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.45 var(--sans);-webkit-font-smoothing:antialiased;}
a{color:inherit;}code{font-family:var(--mono);font-size:.92em;background:var(--line2);padding:1px 4px;border-radius:var(--r);}
.mono{font-family:var(--mono);font-size:12.5px;}.nowrap{white-space:nowrap;}
.appShell{display:grid;grid-template-columns:232px minmax(0,1fr);grid-template-rows:auto 1fr auto;min-height:100vh;background:linear-gradient(90deg,var(--nav) 232px,var(--paper) 232px);}
.sideNav{grid-row:1/-1;grid-column:1;background:var(--nav);color:var(--navInk);display:flex;flex-direction:column;gap:2px;padding:16px 12px;position:sticky;top:0;height:100vh;overflow:auto;}
.brand{display:flex;align-items:center;gap:10px;padding:4px 8px 18px;text-decoration:none;font-weight:600;letter-spacing:-.02em;font-size:15px;color:var(--navInk);}
.logo{flex:none;display:block;}
.navGroup{font:10.5px/1 var(--mono);letter-spacing:.12em;text-transform:uppercase;color:var(--navMute);padding:16px 10px 8px;}
.navItem{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 10px;border-radius:var(--r);text-decoration:none;color:#C9CDD8;font-size:13.5px;font-weight:500;}
.navItem:hover{background:var(--nav2);color:var(--navInk);}
.navOn{background:var(--nav2);color:#fff;box-shadow:inset 3px 0 0 var(--acc);}
.navCount{min-width:22px;padding:1px 7px;border-radius:var(--r);background:var(--acc);color:#fff;font:11px/18px var(--mono);text-align:center;}
.sideFoot{margin-top:auto;border-top:1px solid var(--navLine);padding:12px 4px 0;display:flex;flex-direction:column;gap:8px;}
.userPill{display:flex;align-items:center;gap:8px;min-width:0;font-size:12px;color:var(--navMute);}
.userEmail{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.pillRole{padding:1px 6px;border:1px solid var(--navLine);border-radius:var(--r);font:10px/1.5 var(--mono);text-transform:uppercase;letter-spacing:.08em;color:var(--navInk);}
.navLogout{background:transparent;border:1px solid var(--navLine);color:var(--navInk);border-radius:var(--r);padding:7px 10px;font:inherit;font-size:12.5px;cursor:pointer;width:100%;text-align:left;}
.navLogout:hover{border-color:var(--navMute);}
.topBar{grid-column:2;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:16px 28px;background:var(--white);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:5;}
.topTitle{min-width:0;flex:1;}.topTitle .muted{margin-top:2px;}
.topSearch{flex:0 1 340px;}.topSearch .input{background:var(--paper);}
.main{grid-column:2;padding:22px 28px 44px;max-width:1360px;width:100%;}
.footer{grid-column:2;border-top:1px solid var(--line);padding:14px 28px;color:var(--muted);font-size:12px;display:flex;gap:14px;align-items:center;}
.h1{margin:0;font-size:22px;line-height:28px;letter-spacing:-.02em;font-weight:600;}
.h2{font-size:15px;font-weight:600;margin:0 0 4px;letter-spacing:-.01em;}
.h3{font-size:13.5px;font-weight:600;margin:0 0 6px;}
.muted{color:var(--muted);font-size:13px;line-height:18px;}
.tiny{font-size:12px;}
.card{border:1px solid var(--line);border-radius:var(--r);padding:18px;background:var(--white);margin-bottom:16px;min-width:0;}
.cardHead{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:10px;}
.subHead{display:flex;justify-content:space-between;align-items:baseline;gap:8px;margin:14px 0 2px;font-size:13px;font-weight:600;}
.grid2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;}
.grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
.gridWide{grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);}
.kpiGrid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 16px;}
.kpiGrid.kpi5{grid-template-columns:repeat(5,minmax(0,1fr));}
.card .kpiGrid{margin:12px 0;}
.kpi{padding:14px 16px;background:var(--white);border:1px solid var(--line);border-radius:var(--r);min-width:0;}
.kpi-acc{box-shadow:inset 0 3px 0 var(--acc);}.kpi-warn{box-shadow:inset 0 3px 0 var(--amber);}
.kpiLabel{color:var(--muted);font-size:12px;font-weight:500;}
.kpiValue{font-size:24px;font-weight:600;margin-top:4px;letter-spacing:-.02em;font-variant-numeric:tabular-nums;}
.kpiSub{color:var(--muted);font-size:12px;margin-top:2px;}
.form{display:flex;flex-direction:column;gap:10px;margin-top:12px;}
.formTight{gap:8px;margin-top:8px;}
.label{display:block;font-size:12px;font-weight:600;color:var(--slate);margin:0 0 6px;}
.form>.label{margin-bottom:-4px;}
.input,.select,.textarea{width:100%;border:1px solid var(--line);border-radius:var(--r);padding:9px 10px;font:inherit;font-size:14px;outline:none;background:var(--white);color:var(--ink);}
.input:focus,.select:focus,.textarea:focus{border-color:var(--acc);box-shadow:0 0 0 3px var(--acc100);}
.textarea{font-family:var(--mono);font-size:12.5px;}
.selectSm,.inputSm{padding:5px 8px;font-size:12.5px;width:auto;}
.btn,.btnSecondary,.btnGhost{display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:var(--r);padding:9px 14px;font:inherit;font-size:13.5px;font-weight:600;cursor:pointer;text-decoration:none;line-height:1.2;}
.btn{border:1px solid var(--acc);background:var(--acc);color:#fff;}
.btn:hover{background:var(--accInk);border-color:var(--accInk);}
.btnSecondary{border:1px solid var(--line);background:var(--white);color:var(--ink);}
.btnSecondary:hover{border-color:var(--ink);}
.btnGhost{border:1px solid transparent;background:transparent;color:var(--slate);}
.btnGhost:hover{background:var(--line2);}
.btnSm{padding:5px 10px;font-size:12.5px;}
.btn:focus-visible,.btnSecondary:focus-visible,.btnGhost:focus-visible,.chip:focus-visible,.navItem:focus-visible,.link:focus-visible,.linkBtn:focus-visible{outline:2px solid var(--acc);outline-offset:2px;}
.inline{display:inline;}
.actionsRow{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0 0;}
.filterBar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0;}
.filterBar .input{flex:1 1 240px;width:auto;}.filterBar .select{width:auto;}
.filterBar .label{margin:0;}
.exportBar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
.list{margin:10px 0 0;padding-left:18px;color:var(--slate);font-size:13px;}
.table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13px;background:var(--white);}
.table th,.table td{border-bottom:1px solid var(--line2);padding:9px 10px;text-align:left;vertical-align:middle;white-space:nowrap;}
.table th{color:var(--muted);font-size:12px;font-weight:600;background:var(--paper);border-bottom-color:var(--line);}
.table tbody tr:hover td{background:#FAFAF7;}
.table.compact th,.table.compact td{padding:7px 6px;}
.table.compact{margin-top:6px;}
.card>.table td,.whyBox .table td,.table.wrapCells td{white-space:normal;}
.main>.actionsRow{margin:0 0 16px;}
.form>.btn,.form>.btnSecondary{align-self:flex-start;}
.rowTotal td{font-weight:600;border-top:1px solid var(--line);}
.right,.table .right{text-align:right;}
.neg{color:var(--red);}
.profileCellInput{width:100%;min-width:84px;min-height:30px;border:1px solid transparent;border-radius:var(--r);background:transparent;padding:0 7px;font:inherit;font-size:12.5px;color:var(--ink);}
.profileCellInput:hover{border-color:var(--line);background:var(--white);}
.profileCellInput:focus{border-color:var(--acc);background:#fff;box-shadow:0 0 0 2px var(--acc100);outline:none;}
.profileCellInput.right{text-align:right;}
.profileCellInput.name{min-width:140px;}
.profileCellInput.policy{min-width:104px;font-family:var(--mono);}
.profileCellInput.date{min-width:104px;font-family:var(--mono);}
.badge{display:inline-block;padding:1px 7px;border:1px solid var(--line);border-radius:var(--r);font:11px/1.6 var(--mono);background:var(--white);}
.mutedBadge{color:var(--muted);}
.tagRow{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.tier{display:inline-block;padding:1px 8px;border-radius:var(--r);font-size:11.5px;font-weight:600;line-height:1.6;background:var(--line2);color:var(--slate);}
.tier-high{background:var(--red50);color:var(--red);}.tier-review{background:var(--amber50);color:var(--amber);}.tier-medium{background:var(--acc50);color:var(--accInk);}.tier-low{background:var(--green50);color:var(--green);}
.pill{display:inline-block;padding:1px 8px;border-radius:var(--r);font-size:11.5px;font-weight:600;line-height:1.6;background:var(--line2);color:var(--slate);white-space:nowrap;}
.st-contacted{background:var(--acc50);color:var(--accInk);}.st-quoted{background:#F1ECFE;color:#5B34C7;}.st-remarketing{background:var(--amber50);color:var(--amber);}.st-renewed{background:var(--green50);color:var(--green);}.st-lost{background:var(--red50);color:var(--red);}
.due{color:var(--red);font-weight:600;}
.note{display:inline-block;padding:2px 8px;border-radius:var(--r);background:var(--acc50);color:var(--accInk);font-size:12px;font-weight:500;}
.scrollX{overflow:auto;border:1px solid var(--line);border-radius:var(--r);background:var(--white);margin-top:10px;}
.scrollX .table{margin-top:0;}
.rowLow td{background:#FCFBF7;}
.rowOpen td{background:var(--acc50)!important;}
.gridMap{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;}
.mapSection{border-top:1px solid var(--line2);padding-top:12px;}
.mapSection>.label{margin-bottom:8px;color:var(--ink);}
.mapRow{display:flex;flex-direction:column;gap:4px;}
.mapLabel{font-size:12px;color:var(--slate);}
.divider{height:1px;background:var(--line);margin:16px 0;}
.linkBtn{border:none;background:transparent;color:var(--acc);font:inherit;font-size:13px;font-weight:600;cursor:pointer;padding:0;}
.linkBtn:hover{text-decoration:underline;}
.checkGrid{display:grid;grid-template-columns:1fr 1fr;gap:8px;border:1px solid var(--line);border-radius:var(--r);padding:10px;background:var(--paper);}
.check{display:flex;gap:8px;align-items:center;font-size:13px;color:var(--ink);}
.checkRow{display:flex;flex-wrap:wrap;gap:6px 14px;margin:6px 0 8px;}
.codeBlock{border-radius:var(--r);padding:12px;background:var(--nav);color:var(--navInk);overflow:auto;font:12px/1.5 var(--mono);}
.ruleModeTabs{display:flex;gap:4px;padding:4px;background:var(--paper);border:1px solid var(--line);border-radius:var(--r);margin:4px 0 10px;}
.ruleModeTab{flex:1 1 0;border:0;border-radius:var(--r);background:transparent;color:var(--muted);padding:8px 10px;cursor:pointer;font:inherit;font-size:13px;font-weight:600;}
.ruleModeTab[aria-selected="true"]{background:var(--white);color:var(--ink);box-shadow:0 1px 2px rgba(11,13,18,.08);}
.ruleModePanel{border:1px solid var(--line);border-radius:var(--r);background:var(--paper);padding:12px;margin-bottom:10px;}
.ruleModePanel[hidden]{display:none;}
.ruleConditionRow{display:grid;grid-template-columns:1fr .62fr 1fr auto;gap:8px;align-items:end;padding:8px 0;border-top:1px solid var(--line2);}
.ruleConditionRow:first-child{border-top:0;}
.ruleActionGrid{display:grid;grid-template-columns:1fr .7fr 1fr .6fr;gap:10px;margin-top:10px;align-items:end;}
.inlineCheck{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13px;}
.inlineCheck input,.check input{width:auto;accent-color:var(--acc);}
.pager{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:12px;}
.pagerOn,.pagerOff{padding:5px 10px;border:1px solid var(--line);border-radius:var(--r);text-decoration:none;color:var(--ink);font:12px var(--mono);}
.pagerOn{background:var(--ink);border-color:var(--ink);color:#fff;}
.pagerOff{background:var(--white);}
.swatch{display:inline-block;width:12px;height:12px;border-radius:2px;background:var(--acc);vertical-align:-2px;margin-right:6px;}
.link{color:var(--acc);text-decoration:none;font-weight:600;}
.link:hover{text-decoration:underline;}
.flash{border:1px solid var(--line);border-left:4px solid var(--slate);border-radius:var(--r);background:var(--white);padding:10px 12px;margin:0 0 16px;font-size:13.5px;}
.flashOk{border-left-color:var(--green);background:var(--green50);}
.flashErr{border-left-color:var(--red);background:var(--red50);}
.chips{display:flex;gap:6px;flex-wrap:wrap;}
.chipsWrap{margin:4px 0 4px;}
.chip{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border:1px solid var(--line);border-radius:var(--r);background:var(--white);color:var(--slate);font:inherit;font-size:12.5px;font-weight:500;text-decoration:none;cursor:pointer;}
.chip:hover{border-color:var(--slate);color:var(--ink);}
.chipOn{background:var(--ink);border-color:var(--ink);color:#fff;}
.chipOn:hover{color:#fff;}
.chipCount{font:11px var(--mono);opacity:.75;}
.empty{border:1px dashed var(--line);border-radius:var(--r);padding:14px;color:var(--muted);font-size:13px;margin-top:8px;}
.meterCell{width:40%;}
.meter{display:block;height:6px;background:var(--line2);border-radius:var(--r);overflow:hidden;}
.meterFill{display:block;height:100%;width:0;background:var(--acc);}
.meterWarn{background:var(--amber);}
.statRow{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-top:8px;}
.stat{border:1px solid var(--line2);border-radius:var(--r);padding:8px 10px;}
.statValue{font-size:17px;font-weight:600;font-variant-numeric:tabular-nums;}
.statLabel{font-size:11.5px;color:var(--muted);}
.checklist{border-color:var(--acc100);background:linear-gradient(0deg,var(--white),var(--acc50));}
.steps{list-style:none;margin:6px 0 0;padding:0;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;}
.step{display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--line);border-radius:var(--r);background:var(--white);font-size:13px;}
.stepNum{flex:none;width:22px;height:22px;border-radius:var(--r);display:grid;place-items:center;background:var(--line2);font:11px var(--mono);}
.stepLabel{flex:1;}
.stepDone{color:var(--muted);}.stepDone .stepNum{background:var(--green);color:#fff;}
.topActions{display:flex;gap:10px;align-items:center;flex:none;}.topActions form{margin:0;}
.btnLg{padding:10px 16px;font-size:14px;}
.boardFilters{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 16px;}
.pillSelect{appearance:none;-webkit-appearance:none;height:34px;padding:0 30px 0 12px;border:1px solid var(--line);border-radius:var(--r);background:var(--white) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%236B7280' stroke-width='1.4'/%3E%3C/svg%3E") no-repeat right 11px center;color:var(--slate);font:inherit;font-size:13px;cursor:pointer;max-width:240px;}
.pillSelect:hover{border-color:var(--slate);}
.pillToggle{display:inline-flex;align-items:center;height:34px;padding:0 12px;border:1px solid var(--line);border-radius:var(--r);background:var(--white);color:var(--slate);font-size:13px;text-decoration:none;}
.pillToggle:hover{border-color:var(--slate);color:var(--ink);}
.pillOn,.pillOn:hover{background:var(--acc50);border-color:var(--acc100);color:var(--accInk);}
.switch{position:relative;display:inline-flex;align-items:center;gap:8px;margin-left:10px;font-size:13px;color:var(--slate);cursor:pointer;}
.switch input{position:absolute;opacity:0;width:1px;height:1px;}
.switch i{position:relative;width:30px;height:17px;border-radius:9px;background:#D7D5CE;transition:background .15s;}
.switch i::after{content:"";position:absolute;top:2px;left:2px;width:13px;height:13px;border-radius:50%;background:#fff;transition:transform .15s;}
.switch input:checked+i{background:var(--acc);}.switch input:checked+i::after{transform:translateX(13px);}
.switch input:focus-visible+i{outline:2px solid var(--acc);outline-offset:2px;}
.boardHint{margin-left:auto;color:var(--muted);font-size:12.5px;}
.board{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(178px,1fr);gap:12px;overflow-x:auto;align-items:stretch;min-height:calc(100vh - 230px);padding-bottom:6px;}
.boardCol{display:flex;flex-direction:column;gap:10px;min-width:0;padding:12px 10px;border:1px solid var(--line);border-radius:var(--r);background:#F0EEE8;}
.boardHot{background:#FBE9E6;border-color:#F3D2CD;}
.boardHead h3{display:flex;justify-content:space-between;align-items:center;gap:6px;margin:0;font-size:13.5px;font-weight:600;letter-spacing:-.01em;}
.boardHead h3 b{min-width:20px;padding:0 6px;border-radius:9px;background:var(--ink);color:#fff;font:600 10.5px/17px var(--mono);text-align:center;}
.boardHot .boardHead h3 b{background:var(--red);}
.boardWin{margin-top:2px;font:11.5px/1.3 var(--mono);color:var(--muted);}
.boardHot .boardWin{color:var(--red);}
.boardHead p{margin:3px 0 0;font-size:11.5px;line-height:1.35;color:var(--muted);}
.boardEmpty{padding:4px 2px;color:var(--muted);font-size:12px;}
.boardMore{display:block;padding:8px;border:1px dashed #CFCDC5;border-radius:var(--r);color:var(--muted);font-size:12.5px;font-weight:600;text-align:center;text-decoration:none;}
.boardMore:hover{color:var(--ink);border-color:var(--slate);}
.boardHot .boardMore{border-color:#EDBAB2;}
.boardNote{margin:10px 0 0;}
.rcard{display:flex;flex-direction:column;gap:3px;padding:11px 11px 12px;border:1px solid var(--line);border-radius:var(--r);background:var(--white);font-size:12.5px;}
.rcardTop{display:flex;justify-content:space-between;align-items:center;gap:6px;}
.rcardName{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--ink);font-size:13px;font-weight:600;letter-spacing:.02em;text-decoration:none;}
.rcardName:hover{color:var(--acc);}
.rcardPol{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font:11.5px var(--mono);color:var(--muted);}
.rcardDate{margin-top:2px;color:var(--ink);}.rcardDate.due{color:var(--red);font-weight:600;}
.rcardPrem{font-variant-numeric:tabular-nums;color:var(--ink);}
.chg{margin-left:3px;font-weight:600;}.chgUp{color:var(--red);}.chgMid{color:var(--amber);}.chgDown{color:var(--green);}
.rcardPills{margin-top:5px;}
.rcard .tier{border-radius:10px;padding:1px 9px 1px 8px;}
.rcard .tier::before{content:"";display:inline-block;width:6px;height:6px;margin-right:6px;border-radius:50%;background:currentColor;vertical-align:1px;}
.rcardForm{margin-top:4px;}
.stPick{position:relative;display:inline-flex;max-width:100%;}
.stPick::before{content:"";position:absolute;left:9px;top:50%;width:7px;height:7px;margin-top:-3.5px;border-radius:1px;background:#8A8F99;pointer-events:none;}
.stp-contacted::before{background:var(--acc);}.stp-quoted::before{background:#5B34C7;}.stp-remarketing::before{background:var(--amber);}.stp-renewed::before{background:var(--green);}.stp-lost::before{background:var(--red);}
.stPick select{appearance:none;-webkit-appearance:none;max-width:100%;padding:3px 24px 3px 22px;border:1px solid var(--line);border-radius:var(--r);background:var(--white) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='5' viewBox='0 0 8 5'%3E%3Cpath d='M1 1l3 3 3-3' fill='none' stroke='%238A8F99' stroke-width='1.3'/%3E%3C/svg%3E") no-repeat right 8px center;color:var(--slate);font:inherit;font-size:12px;cursor:pointer;}
.stPick select:hover{border-color:var(--slate);}
.avatar{display:inline-grid;place-items:center;flex:none;width:24px;height:24px;border-radius:50%;font:700 9.5px/1 var(--sans);letter-spacing:.02em;}
.avatar-a{background:#DCE2FF;color:#1A2FD6;}.avatar-b{background:#E7F4EC;color:#157F3B;}.avatar-c{background:#F1ECFE;color:#5B34C7;}.avatar-d{background:#FDF2E4;color:#B45309;}
.avatarSoft{opacity:.7;}.avatarNone{background:var(--line2);color:var(--muted);}
.panel{border-color:var(--acc100);box-shadow:0 1px 0 var(--acc100);}
.panelHead{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;}
.panelHead .actionsRow{margin:0;}
.panelTitle{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:15px;font-weight:600;}
.panelName{font-size:20px;font-weight:600;letter-spacing:-.02em;margin-top:4px;}
.panelGrid{margin-top:14px;}
.panelGrid>div{border:1px solid var(--line2);border-radius:var(--r);padding:12px;}
.kv{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;margin:8px 0 0;font-size:13px;}
.kv dt{color:var(--muted);}.kv dd{margin:0;min-width:0;overflow-wrap:anywhere;}
.consentForm{margin-top:10px;border-top:1px solid var(--line2);padding-top:10px;}
.whyGrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:12px;}
.whyBox{border:1px solid var(--line2);border-radius:var(--r);padding:12px;background:var(--paper);min-width:0;}
.whyTitle{font-weight:600;margin-bottom:8px;}
.whyList{margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px;font-size:13px;}
.whySet{list-style-type:disc;}
.whyList .muted{white-space:normal;}
.formula{display:flex;flex-wrap:wrap;align-items:flex-start;gap:6px;font-variant-numeric:tabular-nums;}
.formula span{display:flex;flex-direction:column;font-weight:600;font-size:15px;background:var(--white);border:1px solid var(--line);border-radius:var(--r);padding:6px 8px;}
.formula em{font-style:normal;font-weight:400;font-size:11px;color:var(--muted);}
.formula b{padding-top:8px;color:var(--muted);}
.panelLog{margin-top:16px;}.panelLog .label{margin-bottom:6px;}
.timeline{list-style:none;margin:8px 0 0;padding:0;display:flex;flex-direction:column;gap:8px;font-size:13px;}
.timeline li{border-left:2px solid var(--line);padding-left:10px;}
.lb{border:1px solid var(--line);border-radius:var(--r);padding:12px;background:var(--paper);}
.lbHead{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.lbHead .label{margin:0;}
.lbRow{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,.9fr) minmax(0,1fr) auto;gap:6px;align-items:center;margin-top:8px;}
.lbVals{display:flex;gap:6px;align-items:center;}
.lbVals .input{min-width:0;}
.lbCount{border:1px solid var(--acc100);background:var(--acc50);border-radius:var(--r);padding:10px 12px;font-size:13.5px;font-weight:500;color:var(--accInk);}
.lbCount.lbErr{border-color:#F3C4BE;background:var(--red50);color:var(--red);}
.advanced{border:1px solid var(--line);border-radius:var(--r);padding:10px 12px;}
.advanced summary{cursor:pointer;font-size:13px;font-weight:600;color:var(--slate);}
.advanced[open] summary{margin-bottom:10px;}
.advanced .label{margin:10px 0 6px;}
.funnel{border:1px solid var(--line);border-radius:var(--r);padding:12px;background:var(--paper);display:flex;flex-direction:column;gap:8px;margin-top:4px;}
.fnRow{display:grid;grid-template-columns:160px minmax(0,1fr) 90px;gap:10px;align-items:center;font-size:13px;}
.fnBar{height:10px;background:var(--line2);border-radius:var(--r);overflow:hidden;}
.fnFill{display:block;height:100%;width:0;background:var(--acc);transition:width .2s;}
.fnValue{text-align:right;font-variant-numeric:tabular-nums;font-weight:600;}
.fnTotals{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;border-top:1px solid var(--line);padding-top:10px;}
.authWrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:var(--paper);}
.authCard{width:100%;max-width:420px;border:1px solid var(--line);border-radius:var(--r);padding:28px;background:var(--white);}
.authBrand{display:flex;align-items:center;gap:10px;font-weight:600;letter-spacing:-.02em;margin-bottom:18px;font-size:15px;}
.authCard .h1{margin-bottom:4px;}
.authFooter{margin-top:16px;display:flex;flex-direction:column;gap:8px;}
.userPill{text-decoration:none;border-radius:var(--r);padding:6px 4px;}
a.userPill:hover,.userPillOn{background:var(--nav2);color:var(--navInk);}
.secretKey{font-size:16px;letter-spacing:.08em;background:var(--paper);border:1px dashed var(--line);border-radius:var(--r);padding:10px 12px;margin:8px 0;word-break:break-all;user-select:all;}
.inputCode{max-width:180px;font-family:var(--mono);font-size:18px;letter-spacing:.2em;}
.setupSteps{margin:10px 0;padding-left:18px;display:flex;flex-direction:column;gap:12px;font-size:13.5px;}
.recoveryCodes{font-size:14px;letter-spacing:.06em;columns:2;}
.dangerBtn{color:var(--red);border-color:#F3C4BE;}
.dangerBtn:hover{background:var(--red50);}
.btnSecondary:disabled{opacity:.5;cursor:not-allowed;}
@media (max-width:1200px){.kpiGrid.kpi5{grid-template-columns:repeat(3,minmax(0,1fr));}.gridMap{grid-template-columns:repeat(2,minmax(0,1fr));}.whyGrid{grid-template-columns:1fr;}.steps{grid-template-columns:1fr 1fr;}}
@media (max-width:900px){.appShell{grid-template-columns:minmax(0,1fr);grid-template-rows:auto auto 1fr auto;background:var(--paper);}.sideNav{grid-row:auto;grid-column:1;position:static;height:auto;flex-direction:row;align-items:center;overflow-x:auto;padding:8px;gap:4px;}.brand{padding:4px 8px;}.brand span{display:none;}.navGroup{display:none;}.navItem{white-space:nowrap;}.sideFoot{margin:0 0 0 auto;border:0;padding:0;flex-direction:row;}.userPill{display:none;}.topBar,.main,.footer{grid-column:1;}.topBar{position:static;flex-wrap:wrap;padding:12px 16px;}.topSearch{flex:1 1 100%;}.topTitle{flex:1 1 100%;}.topActions{flex:1 1 100%;flex-wrap:wrap;}.boardHint{margin-left:0;flex:1 1 100%;}.switch{margin-left:0;}.main{padding:16px 12px 36px;}.grid2,.gridWide,.grid3,.panelGrid{grid-template-columns:1fr;}.kpiGrid,.kpiGrid.kpi5{grid-template-columns:1fr 1fr;}.gridMap{grid-template-columns:1fr;}.checkGrid{grid-template-columns:1fr;}.ruleConditionRow{grid-template-columns:1fr;}.ruleActionGrid{grid-template-columns:1fr 1fr;}.lbRow{grid-template-columns:1fr 1fr;}.steps{grid-template-columns:1fr;}.statRow{grid-template-columns:repeat(3,minmax(0,1fr));}.fnRow{grid-template-columns:110px minmax(0,1fr) 70px;}.fnTotals{grid-template-columns:repeat(2,minmax(0,1fr));}}
/* ===== App UI v2 (matches the Figma "LiteInsurance — App UI v2" frames) ===== */
.sideNav{padding:18px 12px 14px;gap:2px;}
.brand{font-size:17px;padding:2px 10px 18px;}
.wsBox{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 6px;padding:10px 11px;border:1px solid var(--navLine);border-radius:var(--r);background:#0D1222;text-decoration:none;color:var(--navMute);}
a.wsBox:hover{border-color:#36405C;}
.wsName{display:block;color:#fff;font-size:13.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.wsSub{display:block;font-size:11.5px;color:var(--navMute);}
.wsBox svg{flex:none;width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:1.5;}
.navGroup{padding:16px 10px 7px;color:#6E7690;}
.navItem{justify-content:flex-start;gap:11px;padding:0 10px;min-height:34px;font-size:14px;}
.navItem svg{width:16px;height:16px;flex:none;fill:none;stroke:currentColor;stroke-width:1.4;stroke-linecap:round;stroke-linejoin:round;opacity:.85;}
.navLabel{flex:1;}
.navOn{box-shadow:none;font-weight:600;}
.navCount{min-width:26px;padding:2px 7px;border-radius:9px;background:#2A3146;color:#E6E8EF;font:600 11px/14px var(--mono);}
.navOn .navCount{background:var(--acc);color:#fff;}
.navCount.navWarn{background:var(--amber);color:#fff;}
.sideFoot{border-top:0;padding:0;gap:10px;}
.userRow{display:flex;align-items:stretch;gap:6px;}
.userCard{flex:1;min-width:0;display:flex;align-items:center;gap:10px;padding:10px 11px;border:1px solid var(--navLine);border-radius:var(--r);text-decoration:none;}
.userCard:hover,.userCardOn{border-color:#36405C;}
.userAvatar{display:inline-grid;place-items:center;flex:none;width:28px;height:28px;border-radius:50%;background:var(--acc100);color:var(--accInk);font:700 10.5px/1 var(--sans);}
.userMeta{min-width:0;flex:1;}
.userName{display:block;color:#fff;font-size:13.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.userRole{display:block;font-size:11.5px;color:var(--navMute);}
.userRow form{display:flex;}
.navLogout{width:auto;display:grid;place-items:center;padding:0 9px;color:var(--navMute);}
.navLogout svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round;}
.navLogout:hover{color:#fff;}
.sideVer{padding:2px 10px 0;font:10.5px/1.4 var(--mono);color:#5B6380;letter-spacing:.04em;}
.topBar{padding:12px 28px;min-height:68px;}
.h1{font-size:21px;line-height:26px;}
.topTitle .muted{font-size:12.5px;line-height:17px;}
.topSearch{flex:0 1 320px;}.topSearch .input{height:36px;padding:0 12px;font-size:13.5px;}
.btnDark{display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:var(--r);padding:9px 14px;font:inherit;font-size:13.5px;font-weight:600;cursor:pointer;text-decoration:none;line-height:1.2;border:1px solid var(--ink);background:var(--ink);color:#fff;}
.btnDark:hover{background:#262B36;}
.btnDark:focus-visible{outline:2px solid var(--acc);outline-offset:2px;}
.btnBlock{width:100%;padding:14px 16px;font-size:15px;}
.linkStrong{color:var(--acc);font-weight:600;text-decoration:none;font-size:13.5px;}
.linkStrong:hover{text-decoration:underline;}
.eyebrow{font:500 10.5px/1.3 var(--mono);letter-spacing:.12em;text-transform:uppercase;color:var(--muted);}
.eyebrowAcc{color:var(--acc);}
.cardFlush{padding:0;overflow:hidden;}
.cardHeadPad{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:18px 20px 14px;border-bottom:1px solid var(--line);}
.cardHeadPad>div:first-child{min-width:0;}
.cardHeadPad>.btnSecondary,.cardHeadPad>.preset{flex:none;}
.cardHeadPad .h2{margin:0;font-size:16px;}
.cardHeadPad .muted{margin-top:3px;font-size:12.5px;}
.cardFoot{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 20px;border-top:1px solid var(--line);color:var(--muted);font-size:12.5px;}
.stack{display:flex;flex-direction:column;gap:20px;min-width:0;}
.stack>.card,.todayGrid>.card,.listsGrid .card,.campGrid .card,.importGrid .card{margin-bottom:0;}
/* KPI cards */
.kpiRow{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:20px;}
.kpiCard{padding:16px 16px 18px;background:var(--white);border:1px solid var(--line);border-radius:var(--r);min-width:0;}
.kpiCard .l{color:var(--muted);font-size:12.5px;}
.kpiCard .n{font-size:26px;font-weight:600;letter-spacing:-.02em;margin:6px 0 4px;font-variant-numeric:tabular-nums;}
.kpiCard .n.bad{color:var(--red);}
.kpiCard .s{color:var(--muted);font-size:12.5px;line-height:1.35;}
.kpiCard .s.warn{color:var(--amber);}
.todayGrid{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:20px;align-items:start;}
/* segmented links */
.seg{display:inline-flex;gap:2px;flex-wrap:wrap;}
.seg a{display:inline-flex;align-items:center;gap:7px;min-height:32px;padding:0 11px;border-radius:var(--r);color:var(--slate);font-size:13px;font-weight:600;text-decoration:none;}
.seg a b{font:600 10.5px/16px var(--mono);padding:0 6px;border-radius:8px;background:var(--line2);color:var(--muted);}
.seg a:hover{background:var(--line2);}
.seg a.on{background:var(--ink);color:#fff;}
.seg a.on b{background:#2A3146;color:#fff;}
/* v2 tables */
.t2{width:100%;border-collapse:collapse;font-size:13.5px;}
.t2 th{padding:9px 12px;text-align:left;font:500 10.5px/1.3 var(--mono);letter-spacing:.1em;text-transform:uppercase;color:var(--muted);background:#FAFAF7;border-bottom:1px solid var(--line);white-space:nowrap;}
.t2 td{padding:10px 12px;border-bottom:1px solid var(--line2);vertical-align:middle;white-space:nowrap;}
.t2 tbody tr:last-child td{border-bottom:0;}
.t2 th:first-child,.t2 td:first-child{padding-left:18px;}
.t2 th:last-child,.t2 td:last-child{padding-right:18px;}
.t2.dense th,.t2.dense td{padding-left:8px;padding-right:8px;}
.t2.dense th:first-child,.t2.dense td:first-child{padding-left:16px;}
.t2.dense th:last-child,.t2.dense td:last-child{padding-right:16px;}
.t2 .r{text-align:right;}
.t2 .mono{color:var(--slate);}
.t2 .ins{font-weight:600;letter-spacing:.02em;color:var(--ink);text-decoration:none;}
.t2 .sub{display:block;color:var(--muted);font-size:11.5px;margin-top:1px;}
.t2 .sub.due{color:var(--red);}
.t2 tr.rowLink{cursor:pointer;}
.t2 tr.rowLink:hover td{background:#FAFAF7;}
.t2 tr.rowOpen td{background:var(--acc50);}
.t2 tr.rowOpen td:first-child{box-shadow:inset 3px 0 0 var(--acc);}
.t2 td.due{color:var(--red);font-weight:600;}
.t2 .ck{width:40px;padding-right:0;}
.t2 .ck input{accent-color:var(--acc);width:15px;height:15px;margin:0;vertical-align:middle;}
.t2 .empty{padding:18px 20px;color:var(--muted);white-space:normal;}
.tierDot{border-radius:10px;padding:1px 9px 1px 8px;}
.tierDot::before{content:"";display:inline-block;width:6px;height:6px;margin-right:6px;border-radius:50%;background:currentColor;vertical-align:1px;}
.stPill{position:relative;display:inline-flex;align-items:center;padding:2px 9px 2px 22px;border:1px solid var(--line);border-radius:var(--r);background:var(--white);font-size:12px;color:var(--slate);white-space:nowrap;}
.stPill::before{content:"";position:absolute;left:9px;top:50%;width:7px;height:7px;margin-top:-3.5px;border-radius:1px;background:#8A8F99;}
.chg0{color:var(--ink);font-weight:600;}
/* opportunities, health, consent */
.oppRow{display:grid;grid-template-columns:32px minmax(0,1fr) auto;gap:12px;align-items:center;padding:14px 20px;border-top:1px solid var(--line2);text-decoration:none;color:inherit;}
.oppRow:first-child{border-top:0;}
.oppRow:hover{background:#FAFAF7;}
.oppIc{display:grid;place-items:center;width:32px;height:32px;border-radius:var(--r);background:var(--acc50);color:var(--acc);}
.oppIc svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.5;stroke-linejoin:round;}
.oppRow strong{display:block;font-size:13.5px;font-weight:600;}
.oppRow .muted{display:block;font-size:12px;line-height:16px;}
.oppNum{text-align:right;}
.oppNum b{display:block;font-size:17px;font-weight:600;}
.oppNum em{font-style:normal;color:var(--acc);font-size:12.5px;font-weight:600;}
.hbars{padding:14px 20px 18px;display:grid;gap:9px;}
.hbar{display:grid;grid-template-columns:120px minmax(0,1fr) 40px;gap:10px;align-items:center;font:12px var(--mono);color:var(--slate);}
.hbar i{display:block;height:5px;border-radius:3px;background:var(--line2);overflow:hidden;}
.hbar i b{display:block;height:100%;width:0;background:var(--amber);border-radius:3px;}
.hbar strong{text-align:right;color:var(--ink);font:600 13px var(--sans);}
.dots{list-style:none;margin:0;padding:14px 20px 18px;display:grid;gap:10px;font-size:13.5px;}
.dots li{display:grid;grid-template-columns:14px 1fr;gap:8px;}
.dots li::before{content:"";width:7px;height:7px;border-radius:50%;background:var(--green);margin-top:7px;}
.dots li.warn::before{background:var(--amber);}
.pad{padding:14px 20px 18px;}
/* Book */
.bookWrap{display:grid;grid-template-columns:minmax(0,1fr) 360px;margin:-22px -28px -44px;min-height:calc(100vh - 68px);}
.bookMain{padding:14px 24px 30px 28px;min-width:0;}
.bookPanel{border-left:1px solid var(--line);background:var(--white);min-width:0;}
.vtabs{display:flex;flex-wrap:wrap;align-items:center;gap:22px;border-bottom:1px solid var(--line);margin-bottom:14px;}
.vtabs a{display:inline-flex;gap:8px;align-items:center;padding:10px 0 11px;margin-bottom:-1px;border-bottom:2px solid transparent;color:var(--slate);font-size:14px;text-decoration:none;}
.vtabs a b{font:500 10.5px/16px var(--mono);padding:0 6px;border-radius:8px;background:var(--line2);color:var(--muted);}
.vtabs a.on{border-bottom-color:var(--ink);color:var(--ink);font-weight:600;}
.vtabs details{position:relative;}
.vtabs summary{list-style:none;cursor:pointer;color:var(--acc);font-size:13.5px;font-weight:600;padding:10px 0;}
.vtabs summary::-webkit-details-marker{display:none;}
.pop{position:absolute;z-index:6;top:100%;left:0;min-width:220px;padding:6px;background:var(--white);border:1px solid var(--line);border-radius:var(--r);box-shadow:0 8px 24px rgba(11,13,18,.12);}
.pop a{display:flex;justify-content:space-between;gap:12px;padding:7px 10px;margin:0;border:0;border-radius:var(--r);font-size:13px;color:var(--ink);}
.pop a:hover{background:var(--paper);}
.fchips{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px;}
.fchip{display:inline-flex;align-items:center;gap:8px;height:32px;padding:0 10px 0 12px;border:1px solid var(--acc100);border-radius:var(--r);background:var(--acc50);color:var(--accInk);font-size:13px;text-decoration:none;}
.fchip span{font-weight:700;}
.fchip:hover{border-color:var(--acc);}
.addFilter{position:relative;}
.addFilter summary{list-style:none;display:inline-flex;align-items:center;height:32px;padding:0 12px;border:1px solid var(--line);border-radius:var(--r);background:var(--white);color:var(--slate);font-size:13px;cursor:pointer;}
.addFilter summary::-webkit-details-marker{display:none;}
.addFilter .pop{padding:12px;min-width:280px;display:grid;gap:8px;}
.fcount{margin-left:auto;color:var(--muted);font-size:12.5px;}
.bulkBar{display:flex;flex-wrap:wrap;align-items:center;gap:18px;min-height:44px;padding:6px 16px;margin-bottom:12px;border-radius:var(--r);background:var(--ink);color:#fff;font-size:13.5px;}
.bulkBar[hidden]{display:none;}
.bulkBar strong{font-weight:600;}
.bulkBar select,.bulkBar input,.bulkBar button{border:0;background:transparent;color:#D7DAE3;font:inherit;font-size:13.5px;cursor:pointer;padding:4px 0;}
.bulkBar input{border-bottom:1px solid #3A4152;width:130px;cursor:text;color:#fff;}
.bulkBar input::placeholder{color:#8E95A8;}
.bulkBar select option{color:var(--ink);}
.bulkBar button:hover,.bulkBar select:hover{color:#fff;}
.bulkBar .bulkClear{margin-left:auto;color:#A9B6FF;font-weight:600;}
.conf{display:inline-flex;align-items:center;gap:9px;}
.conf .trk{display:block;width:34px;height:4px;border-radius:2px;background:var(--line2);overflow:hidden;}
.conf .trk i{display:block;height:100%;width:0;background:var(--green);}
.conf.mid .trk i{background:var(--amber);}
.conf.mid b{color:var(--amber);}
.conf b{font-weight:500;font-variant-numeric:tabular-nums;}
.pager2{display:flex;gap:16px;}
.pager2 .off{color:var(--faint);pointer-events:none;}
.pSec{padding:18px 20px;border-bottom:1px solid var(--line);}
.pName{display:flex;justify-content:space-between;align-items:center;gap:8px;}
.pName h2{margin:0;font-size:18px;font-weight:600;letter-spacing:.03em;}
.pName form{margin:0;}
.revealBtn{display:inline-flex;align-items:center;gap:5px;border:0;background:transparent;color:var(--acc);font:inherit;font-size:13px;font-weight:600;cursor:pointer;padding:0;}
.revealBtn svg{width:12px;height:12px;fill:none;stroke:currentColor;stroke-width:1.4;}
.pMeta{margin:6px 0 10px;font:11.5px/1.5 var(--mono);color:var(--muted);}
.pChips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;}
.tagChip{display:inline-block;padding:1px 8px;border-radius:var(--r);background:var(--acc50);color:var(--accInk);font:12px/1.6 var(--mono);}
.pSec h3{display:flex;justify-content:space-between;align-items:baseline;gap:8px;margin:0 0 10px;font-size:13.5px;font-weight:600;}
.pSec h3 a{font-size:12.5px;font-weight:600;color:var(--acc);text-decoration:none;}
.why2{padding:12px 12px 10px;border:1px solid var(--line);border-radius:var(--r);background:#FAFAF7;}
.why2Head{display:flex;justify-content:space-between;gap:8px;font-size:13px;font-weight:600;margin-bottom:6px;}
.why2Head span{font:500 11px var(--mono);color:var(--green);white-space:nowrap;}
.why2 ul{list-style:none;margin:0;padding:0;display:grid;gap:3px;font:12px/1.5 var(--mono);color:var(--slate);}
.why2 li::before{content:"✓";color:var(--green);margin-right:8px;font-family:var(--sans);}
.why2 li.no::before{content:"✗";color:var(--red);}
.why2Then{margin-top:6px;font-size:12px;color:var(--slate);}
.pNote{margin:10px 0 0;color:var(--muted);font-size:12px;line-height:1.45;}
.formula2{font:12.5px/1.8 var(--mono);color:var(--ink);}
.formula2 em{color:var(--muted);font-style:normal;}
.chipWarn,.chipOk{display:inline-block;margin:0 6px 6px 0;padding:3px 8px;border-radius:var(--r);font-size:12px;}
.chipWarn{background:var(--amber50);color:var(--amber);}
.chipOk{background:var(--green50);color:var(--green);}
.kv2{display:grid;grid-template-columns:auto 1fr;gap:7px 12px;margin:0;font-size:13px;}
.kv2 dt{color:var(--muted);}
.kv2 dd{margin:0;text-align:right;font-weight:500;min-width:0;overflow-wrap:anywhere;}
.kv2 dd.due{color:var(--red);font-weight:600;}
.kv2 .profileCellInput{width:100%;text-align:right;}
.pActions{display:flex;flex-wrap:wrap;gap:8px;padding:16px 20px 8px;}
.pActions .btnDark,.pActions .btnSecondary,.pActions summary{min-height:40px;}
.pDrawer{padding:0 20px 12px;}
.pDrawer details{border:1px solid var(--line);border-radius:var(--r);margin-top:8px;}
.pDrawer summary{cursor:pointer;padding:10px 12px;font-size:13px;font-weight:600;}
.pDrawer details>div{padding:0 12px 12px;}
.timeline2{list-style:none;margin:0;padding:0;display:grid;gap:8px;font-size:12.5px;}
/* Lists */
.listsGrid{display:grid;grid-template-columns:248px minmax(0,1fr) 300px;gap:20px;align-items:start;}
.savedCard{padding:16px 10px;}
.savedHead{display:flex;justify-content:space-between;align-items:center;padding:0 6px 10px;}
.savedHead .h2{margin:0;font-size:14px;}
.sl{display:grid;grid-template-columns:1fr auto;gap:2px 8px;padding:9px 12px;border-radius:var(--r);text-decoration:none;color:inherit;}
.sl strong{font-size:13.5px;font-weight:600;}
.sl span{grid-column:1;color:var(--muted);font-size:11.5px;}
.sl b{grid-row:1/span 2;grid-column:2;align-self:center;font-weight:500;font-size:13px;color:var(--slate);}
.sl:hover{background:#FAFAF7;}
.sl.on{background:var(--acc50);}
.sl.on strong,.sl.on b{color:var(--accInk);}
.tplHead{padding:16px 12px 4px;}
.tpl{display:block;width:100%;padding:5px 12px;border:0;background:transparent;color:var(--slate);font:inherit;font-size:13.5px;text-align:left;cursor:pointer;}
.tpl:hover{color:var(--acc);}
.builderCard{padding:24px 24px 20px;}
.titleInput{width:100%;border:0;outline:0;padding:0;font:600 21px/1.3 var(--sans);letter-spacing:-.02em;background:transparent;color:var(--ink);}
.descInput{width:100%;border:0;outline:0;padding:0;margin-top:4px;font:13.5px/1.4 var(--sans);color:var(--muted);background:transparent;}
.showRow{display:flex;align-items:center;gap:10px;margin:22px 0 16px;font-size:14px;font-weight:600;}
.showRow .select{width:auto;}
.lb{border:0;padding:0;background:transparent;}
.lbRow{grid-template-columns:44px minmax(0,1.3fr) minmax(0,1fr) minmax(0,1fr) 24px;gap:10px;margin:0 0 10px;}
.lbW{font:500 10.5px var(--mono);letter-spacing:.1em;color:var(--muted);}
.lbRow .select,.lbRow .input{height:38px;padding:0 10px;font-size:13.5px;width:100%;}
.lbX{border:0;background:transparent;color:var(--faint);font-size:16px;cursor:pointer;padding:0;}
.lbX:hover{color:var(--red);}
.lbAddRow{display:flex;gap:18px;margin-top:6px;}
.linkBtn2{border:0;background:transparent;color:var(--acc);font:inherit;font-size:13.5px;font-weight:600;cursor:pointer;padding:0;}
.divider2{height:1px;background:var(--line);margin:18px 0;}
.consentPick strong{display:block;font-size:14px;margin-bottom:10px;}
.consentPick .checkRow{display:flex;flex-wrap:wrap;gap:20px;font-size:13.5px;}
.consentPick input{accent-color:var(--acc);width:15px;height:15px;}
.codeBox{margin-top:16px;background:var(--nav);border-radius:var(--r);padding:12px 18px 14px;color:#E6E8EF;}
.codeHead{display:flex;justify-content:space-between;gap:10px;font-size:12.5px;color:var(--navMute);margin-bottom:8px;}
.codeHead button{border:0;background:transparent;color:#A9B6FF;font:inherit;font-weight:600;cursor:pointer;}
.codeBox .textarea{background:transparent;border:0;color:#F3F4F7;padding:0;box-shadow:none;resize:vertical;}
.codeBox details{margin-top:10px;border-top:1px solid var(--navLine);padding-top:10px;}
.codeBox summary{cursor:pointer;font-size:12.5px;color:var(--navMute);}
.codeBox .check{color:#E6E8EF;}
.big{display:flex;align-items:baseline;gap:10px;margin:6px 0 14px;}
.big b{font-size:38px;font-weight:600;letter-spacing:-.03em;line-height:1;}
.big span{color:var(--slate);font-size:13.5px;}
.res{display:grid;grid-template-columns:1fr auto;font-size:13px;margin:0;}
.res dt,.res dd{margin:0;padding:9px 0;border-top:1px solid var(--line2);}
.res dt{color:var(--muted);}
.res dd{text-align:right;font-weight:600;}
.res dd.warn{color:var(--amber);}
.resBtns{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;}
.resErr{color:var(--red);font-size:13px;}
.pvHead{display:flex;justify-content:space-between;align-items:center;padding:16px 20px 6px;}
.pvHead .h2{margin:0;font-size:14px;}
.pvRow{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:10px;align-items:center;padding:10px 20px;border-top:1px solid var(--line2);font-size:13px;}
.pvRow strong{display:block;font-size:13px;letter-spacing:.02em;}
.pvRow .mono{display:block;font-size:11.5px;color:var(--muted);}
/* Campaigns */
.campGrid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:20px;align-items:start;}
.campForm{padding:22px 20px;}
.campTitle{margin:2px 0 18px;font-size:17px;font-weight:600;}
.fld{display:flex;flex-direction:column;gap:7px;margin-bottom:18px;font-size:13.5px;font-weight:600;}
.fld .help{font-weight:400;color:var(--muted);font-size:12.5px;}
.fld .select{font-weight:400;}
.unit{position:relative;display:block;}
.unit .input{padding-right:50px;font-weight:400;}
.unit span{position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:12.5px;font-weight:400;}
.segctl{display:grid;grid-auto-flow:column;grid-auto-columns:1fr;gap:2px;padding:3px;border-radius:var(--r);background:#ECEAE4;}
.segctl label{position:relative;display:block;}
.segctl input{position:absolute;opacity:0;width:1px;height:1px;}
.segctl span{display:block;text-align:center;padding:7px 0;border-radius:3px;color:var(--muted);font-weight:500;cursor:pointer;}
.segctl input:checked+span{background:var(--white);color:var(--ink);font-weight:600;box-shadow:0 1px 2px rgba(11,13,18,.1);}
.segctl input:focus-visible+span{outline:2px solid var(--acc);outline-offset:1px;}
.fn2{display:grid;grid-template-columns:190px minmax(0,1fr) 60px;gap:16px;align-items:center;margin-bottom:16px;}
.fn2 strong{display:block;font-size:13.5px;font-weight:600;}
.fn2 span{display:block;color:var(--muted);font-size:12px;}
.fn2 i{display:block;height:32px;border-radius:2px;background:#D4D1C8;width:0;min-width:2px;transition:width .2s;}
.fn2.reach i{background:var(--acc100);}
.fn2.sales i{background:var(--acc);}
.fn2 b{text-align:right;font-size:20px;font-weight:600;}
.tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;}
.tile{padding:14px 16px;border-radius:var(--r);background:var(--paper);}
.tile .l{color:var(--muted);font-size:12.5px;}
.tile .n{margin-top:4px;font-size:24px;font-weight:700;letter-spacing:-.02em;}
.tile.neg .n{color:var(--red);}
.tile.good{background:var(--green50);}.tile.good .n{color:var(--green);}
.tile.bad{background:var(--red50);}.tile.bad .n{color:var(--red);}
.callout{display:flex;gap:10px;align-items:flex-start;margin-top:18px;padding:11px 14px;border-radius:var(--r);background:var(--paper);color:var(--slate);font-size:13px;}
.callout svg{flex:none;width:14px;height:14px;margin-top:2px;fill:none;stroke:currentColor;stroke-width:1.4;}
.scns{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;}
.scn{position:relative;padding:14px 16px;border:1px solid var(--line);border-radius:var(--r);background:var(--white);}
.scn:hover{border-color:#B9B6AD;}
.scn.on{border:2px solid var(--acc);padding:13px 15px;background:#FAFBFF;}
.scn .t{display:flex;justify-content:space-between;align-items:center;gap:6px;font-size:13.5px;font-weight:600;}
.scn .t a{color:inherit;text-decoration:none;}
.scn .t a::after{content:"";position:absolute;inset:0;}
.scn .best{padding:1px 7px;border-radius:var(--r);background:var(--green50);color:var(--green);font-size:11.5px;}
.scn .d{margin:6px 0 10px;color:var(--muted);font-size:12.5px;}
.scn .v{font-size:22px;font-weight:700;letter-spacing:-.02em;}
.scn .v small{color:var(--muted);font-size:12.5px;font-weight:400;letter-spacing:0;margin-left:6px;}
.scn .neg{color:var(--red);}
.scn form{position:relative;z-index:1;margin-top:8px;}
.scn form button{border:0;background:transparent;color:var(--muted);font:inherit;font-size:12px;cursor:pointer;padding:0;}
.scn form button:hover{color:var(--red);}
/* Import */
.stepper{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:14px 20px;margin-bottom:20px;}
.stp{display:inline-flex;align-items:center;gap:10px;font-size:14px;color:var(--muted);}
.stp i{display:grid;place-items:center;width:26px;height:26px;border-radius:50%;border:1px solid #CFCDC5;font:600 11.5px var(--mono);font-style:normal;}
.stp.done{color:var(--ink);}
.stp.done i{background:var(--green);border-color:var(--green);color:#fff;}
.stp.now{color:var(--ink);font-weight:600;}
.stp.now i{background:var(--ink);border-color:var(--ink);color:#fff;}
.stpLine{flex:1 1 30px;max-width:70px;height:1px;background:var(--line);}
.stpMeta{margin-left:auto;font:11.5px var(--mono);color:var(--muted);}
.importGrid{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:20px;align-items:start;margin-bottom:20px;}
.preset{display:inline-flex;gap:8px;align-items:baseline;padding:7px 12px;border:1px solid var(--acc100);border-radius:var(--r);background:var(--acc50);color:var(--accInk);font-size:13px;font-weight:600;}
.preset small{font-weight:400;font-size:11.5px;}
.mapTable td:first-child{font-family:var(--mono);font-size:12.5px;}
.mapTable .select{height:34px;padding:0 10px;font-size:13px;min-width:210px;}
.resPill{display:inline-block;padding:2px 8px;border-radius:var(--r);font-size:12px;font-weight:600;background:var(--green50);color:var(--green);white-space:nowrap;}
.resPill.sens{background:var(--amber50);color:var(--amber);}
.resPill.custom{background:var(--acc50);color:var(--accInk);}
.resPill.ign{background:var(--line2);color:var(--muted);}
.mapOpts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:16px 20px;border-top:1px solid var(--line);background:#FAFAF7;}
.happen{padding:2px 0 0;}
.happen .item{display:grid;grid-template-columns:28px 1fr;gap:12px;padding:12px 0;border-bottom:1px solid var(--line2);}
.happen .item:last-child{border-bottom:0;}
.happen .ic{display:grid;place-items:center;width:28px;height:28px;border-radius:var(--r);background:var(--acc50);color:var(--acc);font-weight:700;}
.happen .ic.ok{background:var(--green50);color:var(--green);}
.happen .ic.warn{background:var(--amber50);color:var(--amber);}
.happen b{display:block;font-size:18px;font-weight:600;}
.happen span{color:var(--muted);font-size:12.5px;}
.happenText b{font-size:14.5px;}
.cardFlush .scrollX{border:0;border-radius:0;margin:0;background:transparent;}
.scn .best{white-space:nowrap;}
.pvRow>div{min-width:0;}
.pvRow .mono{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.warnBox{padding:14px 16px;border-radius:var(--r);background:var(--amber50);font-size:13px;color:var(--ink);}
.warnBox strong{display:block;color:var(--amber);margin-bottom:4px;}
.uploadBox{display:flex;flex-direction:column;align-items:flex-start;gap:12px;padding:22px;border:1px dashed #CFCDC5;border-radius:var(--r);background:#FAFAF7;}
@media (max-width:1280px){.kpiRow{grid-template-columns:repeat(3,minmax(0,1fr));}.todayGrid{grid-template-columns:1fr;}.listsGrid{grid-template-columns:220px minmax(0,1fr);}.listsGrid>.stack{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;}.bookWrap{grid-template-columns:minmax(0,1fr) 340px;}.importGrid{grid-template-columns:minmax(0,1fr) 300px;}}
@media (max-width:900px){.kpiRow{grid-template-columns:1fr 1fr;}.bookWrap{grid-template-columns:1fr;margin:-16px -12px -36px;}.bookMain{padding:14px 12px;}.bookPanel{border-left:0;border-top:1px solid var(--line);}.listsGrid,.campGrid,.importGrid,.listsGrid>.stack{grid-template-columns:1fr;}.lbRow{grid-template-columns:1fr 1fr;}.lbW{display:none;}.tiles{grid-template-columns:1fr 1fr;}.fn2{grid-template-columns:1fr 50px;}.fn2 i{grid-column:1/-1;}.mapOpts{grid-template-columns:1fr;}.cardHeadPad{flex-direction:column;}.seg{max-width:100%;overflow-x:auto;flex-wrap:nowrap;}.seg a{white-space:nowrap;}.userCard,.sideVer,.wsBox{display:none;}.navCount{display:none;}}
CSS;
    return '<style nonce="' . $nonce . '">' . app_font_faces() . str_replace('__ACCENT__', $accent, $css) . '</style>';
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
        . 'function money(v){const n=Number(v);return Number.isFinite(n)?(document.body.getAttribute("data-currency")||"")+n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}):"";}'
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
        . 'function render(){const t=ta();if(!t||!document.getElementById("ruleVisualPanel")){return;}const d=safeJson(t.value)||defRule();const logic=document.getElementById("ruleLogicVisual");if(logic){logic.value=String(d.logic||"AND").toUpperCase()==="OR"?"OR":"AND";}const box=document.getElementById("ruleConditionsVisual");if(box){box.innerHTML="";const cond=Array.isArray(d.conditions)?d.conditions:[];const leaf=cond.filter(function(x){return x&&x.field;});(leaf.length?leaf:defRule().conditions).forEach(function(c){box.appendChild(row(c));});const adv=document.getElementById("ruleAdvancedJsonNotice");if(adv){adv.checked=cond.some(function(x){return x&&!(x.field);});}}const a=d.actions&&typeof d.actions==="object"?d.actions:{};setSel(document.getElementById("ruleActionTier"),a.set_risk_tier||"");const score=document.getElementById("ruleActionScore");if(score){score.value=Number(a.score_delta||0);}const tag=document.getElementById("ruleActionTag");if(tag){tag.value=a.add_tag||"";}const stop=document.getElementById("ruleActionStop");if(stop){stop.checked=!!a.stop;}}'
        . 'function collect(){const t=ta();if(!t){return null;}const cond=qsa("[data-rule-condition-row]").map(function(r){const f=qs("[data-rule-field]",r).value;const o=qs("[data-rule-op]",r).value;const v=qs("[data-rule-value]",r).value;return{field:f,op:o,value:pv(o,v)};}).filter(function(x){return x.field&&x.op;});if(!cond.length){throw new Error("Add at least one condition.");}const actions={};const tier=document.getElementById("ruleActionTier");const score=document.getElementById("ruleActionScore");const tag=document.getElementById("ruleActionTag");if(tier&&tier.value){actions.set_risk_tier=tier.value;}if(score&&score.value!==""&&Number(score.value)!==0){actions.score_delta=Number(score.value);}if(tag&&tag.value.trim()!==""){actions.add_tag=tag.value.trim();}const stop=document.getElementById("ruleActionStop");if(stop&&stop.checked){actions.stop=true;}if(!Object.keys(actions).length){throw new Error("Add at least one action.");}const logic=document.getElementById("ruleLogicVisual");const d={logic:logic&&logic.value==="OR"?"OR":"AND",conditions:cond,actions:actions};t.value=JSON.stringify(d,null,2);return d;}'
        . 'function mode(m){const visual=m!=="json";if(!visual){try{collect();}catch(e){}}qsa("[data-rule-mode]").forEach(function(b){b.setAttribute("aria-selected",b.dataset.ruleMode===(visual?"visual":"json")?"true":"false");});const vp=document.getElementById("ruleVisualPanel");const jp=document.getElementById("ruleJsonPanel");if(vp){vp.hidden=!visual;}if(jp){jp.hidden=visual;}if(visual){render();}}'
        . 'function bind(){if(!document.getElementById("ruleVisualPanel")){return;}render();qsa("[data-rule-mode]").forEach(function(b){b.addEventListener("click",function(){mode(b.dataset.ruleMode);});});const add=document.getElementById("addRuleCondition");if(add){add.addEventListener("click",function(){const box=document.getElementById("ruleConditionsVisual");if(box){box.appendChild(row({field:"age",op:">=",value:60}));}});}const box=document.getElementById("ruleConditionsVisual");if(box){box.addEventListener("click",function(e){const b=e.target.closest("[data-rule-remove-condition]");if(!b){return;}if(qsa("[data-rule-condition-row]").length<=1){return;}b.closest("[data-rule-condition-row]").remove();});}const f=ta()?ta().closest("form"):null;if(f){f.addEventListener("submit",function(e){const vp=document.getElementById("ruleVisualPanel");if(vp&&!vp.hidden){try{collect();}catch(err){e.preventDefault();alert(err.message||String(err));}}});}}'
        . 'window.LiteInsurance.ruleTest=async function(){const out=document.getElementById("ruleTestOut");const sel=document.getElementById("ruleTestPolicy");const t=ta();const csrf=document.querySelector("input[name=csrf_token]");if(!out||!sel||!t||!csrf){return;}const vp=document.getElementById("ruleVisualPanel");if(vp&&!vp.hidden){try{collect();}catch(e){out.textContent=e.message||String(e);return;}}out.textContent="Running...";const fd=new FormData();fd.append("csrf_token",csrf.value||"");fd.append("action","rule_test");fd.append("policyholder_id",sel.value||"0");fd.append("definition_json",t.value||"");try{const res=await fetch(window.location.href,{method:"POST",body:fd,headers:{"X-Requested-With":"fetch"}});const txt=await res.text();let j=null;try{j=JSON.parse(txt);}catch(e){}out.textContent=j?JSON.stringify(j,null,2):txt;}catch(e){out.textContent=String(e);}};'
        . 'document.addEventListener("DOMContentLoaded",bind);'
        . '})();'
        . <<<'JS'
(function(){
"use strict";
function qs(s,r){return (r||document).querySelector(s);}
function qsa(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s));}
var CUR=(document.body&&document.body.getAttribute("data-currency"))||"";
function money0(v){var n=Number(v);return Number.isFinite(n)?(n<0?"-":"")+CUR+Math.round(Math.abs(n)).toLocaleString():"—";}
function num(v,d){var n=Number(v);return Number.isFinite(n)?n.toLocaleString(undefined,{maximumFractionDigits:d||0}):"—";}
function csrf(){var i=qs("input[name=csrf_token]");return i?i.value:"";}
function esc(v){return String(v==null?"":v).replace(/&/g,"&amp;").replace(/"/g,"&quot;").replace(/</g,"&lt;");}
document.addEventListener("submit",function(e){var f=e.target;if(f&&f.getAttribute&&f.getAttribute("data-confirm")&&!window.confirm(f.getAttribute("data-confirm"))){e.preventDefault();e.stopImmediatePropagation();}},true);
document.addEventListener("change",function(e){var s=e.target.closest&&e.target.closest("[data-autosubmit]");if(s&&s.form){if(s.form.requestSubmit){s.form.requestSubmit();}else{s.form.submit();}}});
document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest("[data-rule-test]");if(b){e.preventDefault();if(window.LiteInsurance&&window.LiteInsurance.ruleTest){window.LiteInsurance.ruleTest();}}});
function meters(){qsa("[data-w]").forEach(function(el){var w=Math.max(0,Math.min(100,Number(el.getAttribute("data-w"))||0));el.style.width=w+"%";});}
var OPS={text:[["eq","is"],["ne","is not"],["in","is one of"],["like","contains"],["empty","is empty"],["notempty","is not empty"]],num:[["between","between"],["eq","="],["ne","≠"],["gt",">"],["lt","<"],["gte","≥"],["lte","≤"]],bool:[["yes","is yes"],["no","is no"]]};
function lb(){
var root=document.getElementById("listBuilder");if(!root){return;}
var cat=[];try{cat=JSON.parse(root.getAttribute("data-catalog")||"[]");}catch(e){}
var byKey={};cat.forEach(function(c){byKey[c.k]=c;});
var rowsEl=document.getElementById("lbRows"),logic=document.getElementById("lbLogic"),where=document.getElementById("listWhere"),json=document.getElementById("lbJson"),count=document.getElementById("listCount"),form=document.getElementById("listForm");
var timer=null,seq=0;
function typeOf(k){return (byKey[k]&&byKey[k].t)||"text";}
function fieldOptions(sel){var has=false;var html=cat.map(function(c){if(c.k===sel){has=true;}return "<option value=\""+esc(c.k)+"\""+(c.k===sel?" selected":"")+">"+esc(c.l)+"</option>";}).join("");if(sel&&!has){html="<option value=\""+esc(sel)+"\" selected>"+esc(sel)+"</option>"+html;}return html;}
function opOptions(t,sel){return (OPS[t]||OPS.text).map(function(o){return "<option value=\""+o[0]+"\""+(o[0]===sel?" selected":"")+">"+esc(o[1])+"</option>";}).join("");}
function syncVals(w){var op=qs("[data-lb=op]",w).value;var v=qs("[data-lb=v]",w),v2=qs("[data-lb=v2]",w);v.hidden=(op==="empty"||op==="notempty"||op==="yes"||op==="no");v2.hidden=op!=="between";v.placeholder=op==="in"?"a, b, c":(op==="between"?"from":"value");v2.placeholder="to";}
function labels(){qsa(".lbRow",rowsEl).forEach(function(w,i){var l=qs(".lbW",w);if(l){l.textContent=i===0?"WHERE":(logic.value==="OR"?"OR":"AND");}});}
function addRow(r){r=r||{f:cat.length?cat[0].k:"",op:"",v:"",v2:""};var t=typeOf(r.f);var op=r.op||(OPS[t]||OPS.text)[0][0];var w=document.createElement("div");w.className="lbRow";w.innerHTML="<span class=\"lbW\">WHERE</span><select class=\"select\" data-lb=\"f\" aria-label=\"Field\">"+fieldOptions(r.f)+"</select><select class=\"select\" data-lb=\"op\" aria-label=\"Comparison\">"+opOptions(t,op)+"</select><div class=\"lbVals\"><input class=\"input\" data-lb=\"v\" aria-label=\"Value\" value=\""+esc(r.v)+"\"><input class=\"input\" data-lb=\"v2\" aria-label=\"Upper bound\" value=\""+esc(r.v2)+"\"></div><button class=\"lbX\" type=\"button\" data-lb-remove aria-label=\"Remove condition\">×</button>";rowsEl.appendChild(w);syncVals(w);labels();}
function quote(s){return "'"+String(s).replace(/'/g,"''")+"'";}
function isNum(s){return s!==""&&Number.isFinite(Number(s));}
function compile(){var parts=[],rows=[],bad=false;qsa(".lbRow",rowsEl).forEach(function(w){var f=qs("[data-lb=f]",w).value,op=qs("[data-lb=op]",w).value,v=qs("[data-lb=v]",w).value.trim(),v2=qs("[data-lb=v2]",w).value.trim(),t=typeOf(f),p="";rows.push({f:f,op:op,v:v,v2:v2});
if(t==="bool"){p=f+(op==="no"?" = 0":" = 1");}
else if(op==="empty"){p=f+" IS NULL";}
else if(op==="notempty"){p=f+" IS NOT NULL";}
else if(op==="in"){var items=v.split(",").map(function(x){return x.trim();}).filter(Boolean);if(!items.length){bad=true;return;}p=f+" IN ("+items.map(function(x){return t==="num"&&isNum(x)?x:quote(x);}).join(", ")+")";}
else if(op==="like"){if(v===""){bad=true;return;}p=f+" LIKE "+quote("%"+v+"%");}
else if(op==="between"){if(!isNum(v)||!isNum(v2)){bad=true;return;}p="("+f+" >= "+v+" AND "+f+" <= "+v2+")";}
else{var sym={eq:"=",ne:"!=",gt:">",lt:"<",gte:">=",lte:"<="}[op]||"=";if(v===""||(t==="num"&&!isNum(v))){bad=true;return;}p=f+" "+sym+" "+(t==="num"?v:quote(v));}
parts.push(p);});
json.value=JSON.stringify({logic:logic.value==="OR"?"OR":"AND",rows:rows});
return {where:parts.length>1?parts.map(function(x){return x.indexOf(" OR ")>-1?"("+x+")":x;}).join(logic.value==="OR"?" OR ":" AND "):(parts[0]||""),bad:bad};}
function setLr(k,v){qsa("[data-lr="+k+"]").forEach(function(e){e.textContent=v;});}
function live(){var my=++seq;var fd=new FormData();fd.append("csrf_token",csrf());fd.append("action","segment_count");fd.append("where",where.value||"");qsa("input[name=\"rule_ids[]\"]:checked",form).forEach(function(b){fd.append("rule_ids[]",b.value);});qsa("[data-lb-consent]:checked",form).forEach(function(b){fd.append("consent[]",b.value);});count.textContent="Counting…";count.hidden=false;
fetch(window.location.pathname+"?tab=segments",{method:"POST",body:fd,headers:{"X-Requested-With":"fetch","Accept":"application/json"}}).then(function(r){return r.json();}).then(function(d){if(my!==seq){return;}if(!d.ok){count.textContent=d.error||"That filter is not valid.";count.classList.add("lbErr");return;}count.classList.remove("lbErr");count.hidden=true;
setLr("count",num(d.count));setLr("hh",(d.count===1?"policy":"policies")+" · "+num(d.households)+" household"+(d.households===1?"":"s"));setLr("ltv",d.avg_ltv);setLr("prem",d.monthly_premium);setLr("phone",num(d.phone));setLr("left",num(d.left_out));setLr("pvcount",Math.min(5,d.count)+" of "+num(d.count));var chk=qsa("[data-lb-consent]:checked",form).map(function(b){return b.value;});var ch=chk[0]||"mail";var chIn=qs("[data-lr-channel]");if(chIn){chIn.value=ch;}setLr("export","Export CSV ("+num(d[ch])+")");
var pv=qs("[data-lr=pv]");if(pv){pv.innerHTML=(d.preview||[]).map(function(p){var t=String(p.tier||"Unknown");return "<div class=\"pvRow\"><div><strong>"+esc(p.name)+"</strong><span class=\"mono\">"+esc(p.policy)+(p.line?" · "+esc(p.line):"")+"</span></div><span>"+esc(p.ltv)+"</span><span class=\"tier tierDot tier-"+esc(t.toLowerCase().replace(/[^a-z]/g,""))+"\">"+esc(t)+"</span></div>";}).join("")||"<div class=\"pad muted\">No policies match yet.</div>";}
}).catch(function(){if(my===seq){count.textContent="Could not count right now.";}});}
function schedule(bad){clearTimeout(timer);if(bad){count.hidden=false;count.textContent="Finish each condition to see the count.";count.classList.add("lbErr");return;}timer=setTimeout(live,300);}
function sync(){var c=compile();where.value=c.where;schedule(c.bad);}
rowsEl.addEventListener("change",function(e){var w=e.target.closest(".lbRow");if(!w){return;}if(e.target.getAttribute("data-lb")==="f"){qs("[data-lb=op]",w).innerHTML=opOptions(typeOf(e.target.value),"");}syncVals(w);sync();});
rowsEl.addEventListener("input",function(e){if(e.target.closest(".lbRow")&&e.target.tagName==="INPUT"){sync();}});
rowsEl.addEventListener("click",function(e){var b=e.target.closest("[data-lb-remove]");if(b){b.closest(".lbRow").remove();labels();sync();}});
document.getElementById("lbAdd").addEventListener("click",function(){addRow();sync();});
logic.addEventListener("change",function(){labels();sync();});
where.addEventListener("input",function(){json.value="";schedule(false);});
form.addEventListener("change",function(e){if(e.target.name==="rule_ids[]"||e.target.hasAttribute("data-lb-consent")){schedule(false);}});
qsa("[data-preset]").forEach(function(b){b.addEventListener("click",function(){var p={};try{p=JSON.parse(b.getAttribute("data-preset"));}catch(e){}rowsEl.innerHTML="";logic.value=p.logic==="OR"?"OR":"AND";(p.rows||[]).forEach(addRow);var n=document.getElementById("listName");if(n&&!n.value){n.value=b.getAttribute("data-preset-name")||"";}var d=qs("[name=description]",form);if(d&&!d.value){d.value=b.getAttribute("data-preset-desc")||"";}sync();});});
var init=null;try{init=JSON.parse(root.getAttribute("data-initial")||"null");}catch(e){}
if(init&&init.rows){logic.value=init.logic==="OR"?"OR":"AND";init.rows.forEach(addRow);var c=compile();if(!where.value){where.value=c.where;}schedule(c.bad);}
else if(where.value){schedule(false);}
else{addRow();sync();}
}
function camp(){
var f=document.getElementById("campaignForm");if(!f){return;}
var CH={phone:["phone consent","call"],email:["email consent","email"],sms:["text consent","text"],mail:["no opt-out","mail"]};
function val(n){var e=f.querySelector("[name="+n+"]");return e?Number(e.value)||0:0;}
function chan(){var r=f.querySelector("input[name=channel]:checked");return r?r.value:"phone";}
function upd(){var sel=f.querySelector("[name=segment_id]"),o=sel&&sel.options[sel.selectedIndex],ch=chan();var size=o?Number(o.getAttribute("data-size"))||0:0,reach=o?Number(o.getAttribute("data-"+ch))||0:0,name=o?(o.getAttribute("data-name")||""):"";var rate=val("cross_sell_rate_pct")/100,avg=val("avg_offer_value"),cost=val("campaign_cost_per_contact"),lift=val("conversion_lift_pct")/100;var conv=reach*rate*(1+lift),gross=conv*avg,c=reach*cost,net=gross-c,ratio=c>0?gross/c:null,be=avg*(1+lift)>0?cost/(avg*(1+lift)):null;
function set(k,t){qsa("[data-fn="+k+"]").forEach(function(e){e.textContent=t;});}
function bar(k,v){var e=qs("[data-fn-bar="+k+"]");if(e){e.style.width=(size>0?Math.max(v>0?1:0,Math.min(100,v*100/size)):0)+"%";}}
function sub(a,t){var e=qs("["+a+"]");if(e){e.textContent=t;}}
var label={phone:"Phone",email:"Email",sms:"SMS",mail:"Mail"}[ch]||ch;
set("size",num(size));set("reach",num(reach));set("conv",num(conv,1));set("gross",money0(gross));set("cost",(c>0?"−":"")+money0(c));set("net",(net<0?"−":"")+money0(Math.abs(net)));set("roi",ratio===null?"—":(ratio<10?ratio.toFixed(1):Math.round(ratio))+"×");set("be",be===null?"—":(be*100).toFixed(2)+"%");bar("size",size);bar("reach",reach);bar("conv",conv);
var tile=qs("[data-fn-nettile]");if(tile){tile.classList.toggle("good",net>=0);tile.classList.toggle("bad",net<0);}
sub("data-camp-listname",name||"—");sub("data-camp-reachsub",ch==="mail"?"Not opted out":(label+" consent, not opted out"));sub("data-camp-convsub",num(reach)+" × "+(rate*100).toFixed(1)+"% conversion"+(lift>0?" × "+(1+lift).toFixed(2)+" lift":""));
var t=qs("[data-camp-title]");if(t&&!t.getAttribute("data-fixed")){t.textContent=(name||"New scenario")+(name?" by "+label.toLowerCase():"");}
var help=qs("[data-camp-help]");if(help){help.textContent=ch==="mail"?num(reach)+" of "+num(size)+" have not opted out; mail needs no opt-in.":num(reach)+" of "+num(size)+" have "+CH[ch][0]+"; "+num(Math.max(0,size-reach))+" left out automatically.";}
var segIn=qs("[data-camp-seg]"),chIn=qs("[data-camp-ch]"),ex=qs("[data-camp-export]");if(segIn){segIn.value=sel?sel.value:"";}if(chIn){chIn.value=ch;}if(ex){ex.textContent="Export "+CH[ch][1]+" list ("+num(reach)+")";ex.disabled=!sel||!sel.value||sel.value==="0";}}
f.addEventListener("input",upd);f.addEventListener("change",upd);upd();
}
function ui2(){
document.addEventListener("click",function(e){var tr=e.target.closest&&e.target.closest("tr[data-href]");if(tr&&!e.target.closest("a,button,input,select,label,textarea")){window.location.href=tr.getAttribute("data-href");}});
document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("[data-open-drawer]");if(!a){return;}var d=document.getElementById(a.getAttribute("data-open-drawer"));if(d){e.preventDefault();d.open=true;d.scrollIntoView({block:"nearest",behavior:"smooth"});var i=d.querySelector("select,input,button");if(i){i.focus();}}});
document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest("[data-copy]");if(!b){return;}var t=document.getElementById(b.getAttribute("data-copy"));if(t&&navigator.clipboard){navigator.clipboard.writeText(t.value||t.textContent||"").then(function(){b.textContent="Copied";setTimeout(function(){b.textContent="Copy";},1500);});}});
var bar=document.getElementById("bulkForm");
if(bar){var picks=function(){return qsa("[data-bulk-pick]");};var refresh=function(){var n=picks().filter(function(x){return x.checked;}).length;bar.hidden=n===0;var c=qs("[data-bulk-count]",bar);if(c){c.textContent=n+" selected";}var all=qs("[data-bulk-all]");if(all){var p=picks();all.checked=p.length>0&&n===p.length;all.indeterminate=n>0&&n<p.length;}};
document.addEventListener("change",function(e){if(e.target.matches("[data-bulk-pick]")){refresh();}if(e.target.matches("[data-bulk-all]")){picks().forEach(function(x){x.checked=e.target.checked;});refresh();}});
var clr=qs("[data-bulk-clear]",bar);if(clr){clr.addEventListener("click",function(){picks().forEach(function(x){x.checked=false;});refresh();});}refresh();}
var map=document.getElementById("mapForm");
if(map){map.addEventListener("change",function(e){var s=e.target.closest("select[data-col]");if(!s){return;}var cell=s.closest("tr").lastElementChild;var v=s.value,pill="";if(v==="__ignore__"){pill="<span class=\"resPill ign\">Ignored · not imported</span>";}else if(v===""){pill="<span class=\"resPill custom\">Kept as custom</span>";}else if(["display_name","dob","email","phone"].indexOf(v)>-1){pill="<span class=\"resPill sens\">Matched · sensitive</span>";}else{pill="<span class=\"resPill\">Matched</span>";}cell.innerHTML=pill;});}
}
document.addEventListener("DOMContentLoaded",function(){meters();lb();camp();ui2();});
})();
JS
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
    - The default policy permits only self (fonts are self-hosted in fonts/), and uses a nonce for inline <style>/<script>.
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
        . "connect-src 'self'; "
        . "object-src 'none'; "
        . "frame-ancestors 'none'; "
        . "form-action 'self'; "
        . "img-src 'self' data:; "
        . "font-src 'self' data:; "
        . "style-src 'self' 'nonce-{$nonce}'; "
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
        if ((($m['lp'] ?? '') !== '')) {
            $tokens[] = ['type' => 'LPAREN', 'value' => '('];
        } elseif ((($m['rp'] ?? '') !== '')) {
            $tokens[] = ['type' => 'RPAREN', 'value' => ')'];
        } elseif ((($m['comma'] ?? '') !== '')) {
            $tokens[] = ['type' => 'COMMA', 'value' => ','];
        } elseif ((($m['op'] ?? '') !== '')) {
            $tokens[] = ['type' => 'OP', 'value' => $m['op']];
        } elseif ((($m['kw'] ?? '') !== '')) {
            $tokens[] = ['type' => 'KW', 'value' => strtoupper($m['kw'])];
        } elseif ((($m['num'] ?? '') !== '')) {
            $raw = $m['num'];
            $parsed = str_contains($raw, '.') ? (float)$raw : (int)$raw;
            $tokens[] = ['type' => 'NUMBER', 'value' => $raw, 'parsed' => $parsed];
        } elseif ((($m['str'] ?? '') !== '')) {
            $raw = $m['str'];
            $inner = substr($raw, 1, -1);
            $inner = str_replace("''", "'", $inner);
            $tokens[] = ['type' => 'STRING', 'value' => $raw, 'parsed' => $inner];
        } elseif ((($m['id'] ?? '') !== '')) {
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
        $k = trim(strip_bom((string)$h));
        if ($k !== '') {
            $map[$k] = (int)$i;
        }
    }
    return $map;
}

function core_import_field_keys(): array
{
    return ['record_id', 'display_name', 'dob', 'age', 'gender', 'policy_type', 'region', 'premium_amount', 'tenure_months', 'sum_assured',
        'effective_date', 'expiration_date', 'status', 'line_of_business', 'carrier', 'producer', 'household_id', 'email', 'phone', 'consent_email', 'consent_sms', 'consent_phone'];
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
    $reserved = ['id', 'custom', 'metadata', 'policy_number', 'name', 'record_id', 'display_name', 'dob', 'age', 'gender', 'policy_type', 'region', 'premium_amount', 'tenure_months', 'sum_assured', 'ltv_estimate', 'risk_tier', 'confidence_score', 'is_imputed',
        'effective_date', 'expiration_date', 'status', 'line_of_business', 'carrier', 'producer', 'household_id', 'email', 'phone', 'consent_email', 'consent_sms', 'consent_phone',
        'opt_out_at', 'opted_out', 'last_contacted_at', 'renewal_status', 'renewal_owner', 'prior_premium', 'updated_at', 'days_to_expiry', 'policies_in_household'];
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
        if ($hname === '' || $hname === '__generate__') {
            continue;
        }
        if (in_array($field, core_import_field_keys(), true) || str_starts_with($field, 'ignore:')) {
            $out[$hname] = true;
        }
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
        if (!in_array((string)$field, core_import_field_keys(), true)) {
            continue;
        }
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
    $s = str_replace([',', currency_symbol(), '$', '€', '£', ' '], '', $s);
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
        $d = new DateTimeImmutable($dob, new DateTimeZone('UTC'));
        $now = new DateTimeImmutable(li_today() . ' 12:00:00', new DateTimeZone('UTC'));
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
    $householdCounts = csv_household_counts($path, $mapping);
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
        if ((string)($validated['household_id'] ?? '') !== '') {
            $validated['policies_in_household'] = max((int)($householdCounts[(string)$validated['household_id']] ?? 1), household_count((string)$validated['household_id']));
        }
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
            'expiration_date' => (string)($validated['expiration_date'] ?? ''),
            'status' => (string)($validated['status'] ?? ''),
            'household_id' => (string)($validated['household_id'] ?? ''),
            'contact' => implode(' · ', array_filter([
                ((int)($validated['consent_email'] ?? 0) === 1 && (string)$validated['email'] !== '') ? 'email' : '',
                ((int)($validated['consent_sms'] ?? 0) === 1 && (string)$validated['phone'] !== '') ? 'sms' : '',
                ((int)($validated['consent_phone'] ?? 0) === 1 && (string)$validated['phone'] !== '') ? 'call' : '',
            ])) ?: (((string)$validated['email'] !== '' || (string)$validated['phone'] !== '') ? 'no consent' : '—'),
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
    if (in_array($op, ['>', '<', '>=', '<='], true) && ($leftStr === null || $leftStr === '')) {
        return false;
    }
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
    $today = is_string($GLOBALS['LI_SAMPLE_TODAY'] ?? null) ? (string)$GLOBALS['LI_SAMPLE_TODAY'] : null; // CLI parity checks pin the date
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
    fputcsv($fh, ['policy_number', 'name', 'dob', 'gender', 'policy_type', 'region', 'premium_amount', 'tenure_months', 'sum_assured', 'effective_date', 'expiration_date', 'status', 'line_of_business', 'carrier', 'producer', 'household_id', 'email', 'phone', 'email_opt_in', 'sms_opt_in', 'phone_opt_in', 'agent_code', 'renewal_probability', 'source_channel']);
    $lobByType = ['term' => 'Term Life', 'life' => 'Whole Life', 'health' => 'Health', 'auto' => 'Personal Auto'];
    $carriersByType = ['term' => ['Harbor Life', 'Northwind Mutual'], 'life' => ['Harbor Life', 'Northwind Mutual'], 'health' => ['Bluefield Health', 'Bluefield Health'], 'auto' => ['Summit Casualty', 'Northwind Mutual']];
    $producers = ['A. Rahman', 'J. Reed', 'M. Silva'];
    $today = (int)strtotime(($today !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) ? $today : gmdate('Y-m-d')) . ' 00:00:00 UTC');
    $channels = ['broker', 'web', 'referral', 'branch'];
    for ($i = 1; $i <= SAMPLE_POLICY_COUNT; $i++) {
        $type = (string)$pick($types);
        $region = (string)$pick($regions);
        $age = 22 + (int)floor(min($rand(), $rand()) * 55);
        $tenure = (int)floor(min($rand(), $rand()) * 96);
        $premium = max(38.0, (float)$basePremium[$type] + (($age - 42) * 0.9) + ($tenure * 0.45) + floor($rand() * 42) - 18);
        $sumAssured = (int)$baseAssured[$type] + (int)floor($rand() * 36000);
        $dob = sprintf('%04d-%02d-%02d', 2026 - $age, ($i % 12) + 1, ($i % 27) + 1);
        $fullName = (string)$pick($firstNames) . ' ' . (string)$pick($lastNames);
        $u = static function (string $k) use ($i): float {
            return (crc32('li-sample-' . $k . '-' . $i) % 1000003) / 1000003;
        };
        $termMonths = $type === 'auto' ? 6 : 12;
        $expTs = $today + (int)(floor($u('exp') * ($termMonths === 6 ? 183 : 365)) - 20) * 86400;
        $expiration = gmdate('Y-m-d', $expTs);
        $effective = add_months_iso($expiration, -$termMonths);
        $parts = explode(' ', $fullName);
        $hasEmail = $u('em') < 0.88;
        $hasPhone = $u('ph') < 0.80;
        $extra = [
            $effective,
            $u('noexp') < 0.05 ? '' : $expiration,
            $u('st') < 0.03 ? 'Cancelled' : 'Active',
            $lobByType[$type] ?? ucfirst($type),
            $carriersByType[$type][(int)floor($u('car') * 2)] ?? 'Northwind Mutual',
            $producers[(int)floor($u('pr') * 3)],
            'H-' . (1000 + (int)floor($u('hh') * 150)),
            $hasEmail ? strtolower($parts[0] . '.' . ($parts[1] ?? 'client') . $i) . '@example.com' : '',
            $hasPhone ? sprintf('(%03d) 555-01%02d', 201 + (int)floor($u('ac') * 700), (int)floor($u('ln') * 100)) : '',
            $hasEmail ? ($u('ce') < 0.65 ? 'Yes' : 'No') : '',
            $hasPhone ? ($u('cs') < 0.35 ? 'Yes' : 'No') : '',
            $hasPhone ? ($u('cp') < 0.55 ? 'Yes' : 'No') : '',
            'AG-' . str_pad((string)(10 + ($i % 18)), 2, '0', STR_PAD_LEFT),
            number_format(0.42 + floor($u('rp') * 49) / 100, 2, '.', ''),
            $channels[(int)floor($u('sc') * 4)],
        ];
        fputcsv($fh, array_merge([
            'P-' . str_pad((string)(3000 + $i), 4, '0', STR_PAD_LEFT),
            $fullName,
            $chance(0.08) ? '' : $dob,
            $chance(0.10) ? '' : (string)$pick($genders),
            $chance(0.08) ? '' : $type,
            $chance(0.11) ? '' : $region,
            $chance(0.09) ? '' : number_format($premium, 2, '.', ''),
            $chance(0.08) ? '' : (string)$tenure,
            $chance(0.06) ? '' : (string)$sumAssured,
        ], $extra));
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
        [
            'name' => 'Inactive policy = skip scoring',
            'priority' => 200,
            'enabled' => 1,
            'definition' => [
                'logic' => 'AND',
                'conditions' => [
                    ['field' => 'status', 'op' => 'in', 'value' => ['cancelled', 'expired', 'lapsed', 'non-renewed']],
                ],
                'actions' => ['add_tag' => 'inactive', 'stop' => true],
            ],
        ],
        [
            'name' => 'Renewing within 45 days + high premium',
            'priority' => 40,
            'enabled' => 1,
            'definition' => [
                'logic' => 'AND',
                'conditions' => [
                    ['field' => 'days_to_expiry', 'op' => '>=', 'value' => 0],
                    ['field' => 'days_to_expiry', 'op' => '<=', 'value' => 45],
                    ['field' => 'premium_amount', 'op' => '>=', 'value' => 130],
                ],
                'actions' => ['score_delta' => 5, 'add_tag' => 'priority_renewal'],
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
        [
            'name' => 'Renewing in 90 days',
            'rule_ids' => [],
            'where' => "(days_to_expiry >= 0 AND days_to_expiry <= 90) AND renewal_status IN ('not_started', 'contacted')",
            'builder' => ['logic' => 'AND', 'rows' => [['f' => 'days_to_expiry', 'op' => 'between', 'v' => '0', 'v2' => '90'], ['f' => 'renewal_status', 'op' => 'in', 'v' => 'not_started, contacted']]],
        ],
        [
            'name' => 'Single-line households, email OK',
            'rule_ids' => [],
            'where' => 'policies_in_household = 1 AND consent_email = 1 AND opted_out = 0',
            'builder' => ['logic' => 'AND', 'rows' => [['f' => 'policies_in_household', 'op' => 'eq', 'v' => '1'], ['f' => 'consent_email', 'op' => 'yes', 'v' => ''], ['f' => 'opted_out', 'op' => 'no', 'v' => '']]],
        ],
    ];

    $st = $pdo->prepare('INSERT INTO segments (name, rule_ids, last_run_at) VALUES (?, ?, NULL)');
    foreach ($segments as $seg) {
        $payload = ['rule_ids' => array_values((array)$seg['rule_ids']), 'where' => (string)$seg['where'], 'mode' => 'rules_and_where'];
        if (isset($seg['builder'])) {
            $payload['builder'] = $seg['builder'];
        }
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
    $headers = array_map(fn($x) => trim(strip_bom((string)$x)), $headers);
    $_SESSION['import_stage'] = ['path' => $dest, 'filename' => $origName, 'headers' => $headers, 'defaults' => ['region' => 'Unknown', 'policy_type' => 'term']];
    audit($pdo, (int)$user['id'], 'import.upload', ['filename' => $origName, 'bytes' => $size]);
    flash('success', 'File uploaded. Map columns to continue.');
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

    $allowedFields = filter_allowed_fields();

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
            return ['sql' => filter_field_sql($fieldLower) . ' IS ' . ($not ? 'NOT ' : '') . 'NULL', 'params' => []];
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
            return ['sql' => filter_field_sql($fieldLower) . ' IN (' . implode(',', $vals) . ')', 'params' => $params];
        }
        if ($kw && $kw['type'] === 'KW' && strtoupper((string)$kw['value']) === 'LIKE') {
            $expect('KW', 'LIKE');
            $vTok = $parseValue();
            return ['sql' => filter_field_sql($fieldLower) . ' LIKE ?', 'params' => [$vTok['parsed']]];
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
        return ['sql' => filter_field_sql($fieldLower) . ' ' . $op . ' ?', 'params' => [$vTok['parsed']]];
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
    $allowedFields = filter_allowed_fields();
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
    if (in_array($op, ['>', '<', '>=', '<='], true) && ($left === null || $left === '')) {
        return false;
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
    return [$ruleIds, where_with_consent($where, clean_consent($criteria['consent'] ?? []))];
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

    $coreFields = ['policy_number', 'name', 'age', 'policy_type', 'region', 'premium_amount', 'tenure_months', 'sum_assured',
        'expiration_date', 'effective_date', 'status', 'line_of_business', 'carrier', 'producer', 'household_id', 'email', 'phone', 'renewal_owner'];
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
        if (in_array($field, ['expiration_date', 'effective_date'], true)) {
            $parsed = $value === '' ? null : parse_date_any($value);
            if ($value !== '' && $parsed === null) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Date expected (YYYY-MM-DD)']);
                return;
            }
        } elseif ($field === 'email') {
            $parsed = $value === '' ? null : normalize_email($value);
            if ($value !== '' && $parsed === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Valid email expected']);
                return;
            }
        } elseif ($field === 'phone') {
            $parsed = $value === '' ? null : normalize_phone($value);
        } elseif ($field === 'status') {
            $parsed = $value === '' ? null : normalize_policy_status($value);
        } elseif ($field === 'policy_type') {
            $parsed = $value === '' ? null : normalize_policy_type($value);
        } elseif (in_array($field, ['region', 'line_of_business', 'carrier', 'producer', 'household_id', 'renewal_owner'], true)) {
            $parsed = $value === '' ? null : $value;
        }
        $upd = $pdo->prepare('UPDATE policyholders SET ' . $field . ' = ?, updated_at = ? WHERE id = ?');
        $upd->execute([$parsed, now_iso(), $id]);
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
    $meta['tier_rule_id'] = (int)($riskOut['tier_rule_id'] ?? 0);
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
    $updated = recompute_all_policyholders($pdo);
    audit($pdo, (int)$user['id'], 'cron.recompute', ['updated' => $updated]);
    flash('success', 'Recompute completed. Updated policyholders: ' . $updated);
    redirect_to(['tab' => 'settings']);
}


/* ==========================================================================
   v1.1 — renewals, consent, upsert imports, explainable scoring
   Everything below is additive: one file, SQLite, no build step, no egress.
   ========================================================================== */

function request_action(): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && is_string($_POST['action'] ?? null) && (string)$_POST['action'] !== '') {
        return (string)$_POST['action'];
    }
    return is_string($_GET['action'] ?? null) ? (string)$_GET['action'] : '';
}

function normalize_tab(string $tab): string
{
    $tab = strtolower(trim($tab));
    $aliases = ['today' => 'dashboard', 'book' => 'profiles', 'lists' => 'segments', 'health' => 'import', 'scoring' => 'rules'];
    $tab = $aliases[$tab] ?? $tab;
    return $tab !== '' ? $tab : 'dashboard';
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function table_columns(PDO $pdo, string $table): array
{
    $out = [];
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $c) {
        $out[(string)$c['name']] = true;
    }
    return $out;
}

function migrate_v11(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= 11) {
        return;
    }
    $cols = table_columns($pdo, 'policyholders');
    $add = [
        'effective_date' => 'TEXT',
        'expiration_date' => 'TEXT',
        'status' => 'TEXT',
        'line_of_business' => 'TEXT',
        'carrier' => 'TEXT',
        'producer' => 'TEXT',
        'household_id' => 'TEXT',
        'email' => 'TEXT',
        'phone' => 'TEXT',
        'consent_phone' => 'INTEGER NOT NULL DEFAULT 0',
        'consent_sms' => 'INTEGER NOT NULL DEFAULT 0',
        'consent_email' => 'INTEGER NOT NULL DEFAULT 0',
        'opt_out_at' => 'TEXT',
        'last_contacted_at' => 'TEXT',
        'renewal_status' => 'TEXT',
        'renewal_owner' => 'TEXT',
        'prior_premium' => 'REAL',
        'updated_at' => 'TEXT',
    ];
    foreach ($add as $col => $ddl) {
        if (!isset($cols[$col])) {
            $pdo->exec('ALTER TABLE policyholders ADD COLUMN ' . $col . ' ' . $ddl);
        }
    }
    $imp = table_columns($pdo, 'imports');
    if (!isset($imp['rows_updated'])) {
        $pdo->exec('ALTER TABLE imports ADD COLUMN rows_updated INTEGER NOT NULL DEFAULT 0');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (\n"
        . "name TEXT PRIMARY KEY,\n"
        . "value TEXT NOT NULL,\n"
        . "updated_at TEXT NOT NULL\n"
        . ")");
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_log (\n"
        . "id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
        . "policyholder_id INTEGER NOT NULL,\n"
        . "user_id INTEGER,\n"
        . "channel TEXT NOT NULL,\n"
        . "outcome TEXT NOT NULL,\n"
        . "note TEXT NOT NULL DEFAULT '',\n"
        . "ts TEXT NOT NULL\n"
        . ")");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_policyholders_expiration ON policyholders(expiration_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_policyholders_household ON policyholders(household_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_policyholders_renewal ON policyholders(renewal_status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_contact_log_policy ON contact_log(policyholder_id, ts)');
    $pdo->exec('PRAGMA user_version = 11');
}

/* ---------- settings ---------- */

function settings_defaults(): array
{
    return [
        'currency_symbol' => cfg('CURRENCY_SYMBOL', LI_CURRENCY_SYMBOL),
        'terminology' => 'agency',
        'premium_period' => 'monthly',
        'ltv_term_months' => (string)EXPECTED_POLICY_TERM_MONTHS_DEFAULT,
        'ltv_retention' => (string)RETENTION_ADJUSTMENT_DEFAULT,
        'ltv_cross_sell_default' => (string)DEFAULT_CROSS_SELL_MULTIPLIER,
        'consent_required_for_exports' => '1',
        'renewal_cadence' => '120,90,75,45,30,15',
        'checklist_dismissed' => '0',
        'allow_registration' => '0',
        'require_totp' => '0',
        'retention_uploads_days' => '7',
        'retention_audit_days' => '0',
        'retention_contact_days' => '0',
        'retention_inactive_policy_days' => '0',
    ];
}

function load_settings(PDO $pdo): void
{
    $vals = settings_defaults();
    try {
        foreach ($pdo->query('SELECT name, value FROM settings')->fetchAll() as $r) {
            $vals[(string)$r['name']] = (string)$r['value'];
        }
    } catch (Throwable $e) {
        // settings table missing: defaults apply
    }
    $GLOBALS['LI_SETTINGS'] = $vals;
}

function setting(string $name, string $default = ''): string
{
    $s = $GLOBALS['LI_SETTINGS'] ?? null;
    if (!is_array($s)) {
        $s = settings_defaults();
    }
    return array_key_exists($name, $s) ? (string)$s[$name] : $default;
}

function setting_set(PDO $pdo, string $name, string $value): void
{
    $st = $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at');
    $st->execute([$name, $value, now_iso()]);
    if (!is_array($GLOBALS['LI_SETTINGS'] ?? null)) {
        $GLOBALS['LI_SETTINGS'] = settings_defaults();
    }
    $GLOBALS['LI_SETTINGS'][$name] = $value;
}

function currency_symbol(): string
{
    return setting('currency_symbol', LI_CURRENCY_SYMBOL);
}

function money_whole($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return currency_symbol() . number_format((float)$value, 0);
}

function terminology_packs(): array
{
    return [
        'agency' => ['label' => 'Independent agency (P&C + life)', 'client' => 'Client', 'clients' => 'Clients', 'policy' => 'Policy', 'policy_type' => 'Line', 'sum_assured' => 'Coverage limit', 'producer' => 'Producer'],
        'life_health' => ['label' => 'Life & health advisers', 'client' => 'Policyholder', 'clients' => 'Policyholders', 'policy' => 'Policy', 'policy_type' => 'Plan type', 'sum_assured' => 'Sum assured', 'producer' => 'Adviser'],
        'generic' => ['label' => 'Generic book of business', 'client' => 'Customer', 'clients' => 'Customers', 'policy' => 'Record', 'policy_type' => 'Product', 'sum_assured' => 'Coverage', 'producer' => 'Owner'],
    ];
}

function lbl(string $key): string
{
    $packs = terminology_packs();
    $pack = $packs[setting('terminology', 'agency')] ?? $packs['agency'];
    return (string)($pack[$key] ?? $key);
}

/* ---------- normalisation ---------- */

function strip_bom(string $s): string
{
    return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
}

function parse_date_any(string $s): ?string
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $s, $m)) {
        $y = (int)$m[1];
        $mo = (int)$m[2];
        $d = (int)$m[3];
    } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/', $s, $m)) {
        $a = (int)$m[1];
        $b = (int)$m[2];
        $y = (int)$m[3];
        if ($y < 100) {
            $y += 2000;
        }
        if ($a > 12 && $b <= 12) {
            $d = $a;
            $mo = $b;
        } else {
            $mo = $a;
            $d = $b;
        }
    } else {
        $ts = strtotime($s . ' UTC');
        if ($ts === false) {
            return null;
        }
        return gmdate('Y-m-d', $ts);
    }
    if (!checkdate($mo, $d, $y)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

function li_today(): string
{
    // "Today" in UTC. CLI parity checks may pin it (LI_SAMPLE_TODAY) so the PHP app and the demo can be compared on any date.
    $pin = PHP_SAPI === 'cli' && is_string($GLOBALS['LI_SAMPLE_TODAY'] ?? null) ? (string)$GLOBALS['LI_SAMPLE_TODAY'] : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $pin) ? $pin : gmdate('Y-m-d');
}

function days_until(?string $date): ?int
{
    $date = trim((string)$date);
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
        return null;
    }
    $ts = strtotime(substr($date, 0, 10) . ' 00:00:00 UTC');
    if ($ts === false) {
        return null;
    }
    $today = (int)strtotime(li_today() . ' 00:00:00 UTC');
    return (int)round(($ts - $today) / 86400);
}

function add_months_iso(string $date, int $months): string
{
    try {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify(($months >= 0 ? '+' : '') . $months . ' months')->format('Y-m-d');
    } catch (Throwable $e) {
        return '';
    }
}

function normalize_policy_status(string $s): string
{
    $k = strtolower(trim(preg_replace('/\s+/', ' ', $s) ?? ''));
    if ($k === '') {
        return '';
    }
    $map = [
        'active' => 'active', 'a' => 'active', 'in force' => 'active', 'inforce' => 'active', 'in-force' => 'active', 'current' => 'active', 'renewed' => 'active', 'renewal' => 'active', 'new' => 'active', 'new business' => 'active',
        'pending' => 'pending', 'quote' => 'pending', 'quoted' => 'pending', 'p' => 'pending',
        'cancelled' => 'cancelled', 'canceled' => 'cancelled', 'cancel' => 'cancelled', 'c' => 'cancelled', 'cxl' => 'cancelled',
        'expired' => 'expired', 'e' => 'expired', 'lapsed' => 'lapsed', 'lapse' => 'lapsed',
        'non-renewed' => 'non-renewed', 'nonrenewed' => 'non-renewed', 'non renewed' => 'non-renewed', 'non-renewal' => 'non-renewed',
    ];
    return $map[$k] ?? $k;
}

function inactive_statuses(): array
{
    return ['cancelled', 'expired', 'lapsed', 'non-renewed'];
}

function sql_in_force(): string
{
    return "COALESCE(status,'') NOT IN ('cancelled','expired','lapsed','non-renewed')";
}

function sql_days_to_expiry(): string
{
    return "CAST(julianday(expiration_date) - julianday(date('now')) AS INTEGER)";
}

function sql_not_opted_out(): string
{
    return "(opt_out_at IS NULL OR opt_out_at = '')";
}

function normalize_label_case(string $s): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
    if ($s === '') {
        return '';
    }
    if (strlen($s) <= 3 && ctype_alpha($s)) {
        return strtoupper($s);
    }
    if ($s === strtoupper($s) || $s === strtolower($s)) {
        return ucwords(strtolower($s));
    }
    return $s;
}

function normalize_policy_type(string $s): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $s) ?? ''));
}

function policy_type_from_lob(string $lob): string
{
    $k = strtolower($lob);
    if ($k === '') {
        return '';
    }
    foreach (['auto' => 'auto', 'vehicle' => 'auto', 'motor' => 'auto', 'term' => 'term', 'life' => 'life', 'health' => 'health', 'medical' => 'health', 'dental' => 'health', 'home' => 'home', 'dwelling' => 'home', 'property' => 'home', 'condo' => 'home', 'renter' => 'home', 'umbrella' => 'umbrella', 'commercial' => 'commercial', 'business' => 'commercial', 'bop' => 'commercial'] as $needle => $type) {
        if (str_contains($k, $needle)) {
            return $type;
        }
    }
    return normalize_policy_type($lob);
}

function normalize_email(string $s): string
{
    $s = strtolower(trim($s));
    return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : '';
}

function normalize_phone(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    $plus = str_starts_with($s, '+');
    $digits = preg_replace('/\D+/', '', $s) ?? '';
    if (strlen($digits) < 7 || strlen($digits) > 15) {
        return '';
    }
    return ($plus ? '+' : '') . $digits;
}

function phone_key(string $s): string
{
    $d = preg_replace('/\D+/', '', $s) ?? '';
    return strlen($d) > 10 ? substr($d, -10) : $d;
}

function parse_bool_loose(string $s): ?int
{
    $k = strtolower(trim($s));
    if ($k === '') {
        return null;
    }
    if (in_array($k, ['1', 'y', 'yes', 'true', 't', 'x', 'opt-in', 'opt in', 'opted in', 'optin', 'subscribed', 'ok', 'allowed', 'consent', 'granted'], true)) {
        return 1;
    }
    if (in_array($k, ['0', 'n', 'no', 'false', 'f', 'opt-out', 'opt out', 'opted out', 'optout', 'unsubscribed', 'denied', 'dnc', 'do not contact'], true)) {
        return 0;
    }
    return null;
}

function mask_email(string $email): string
{
    $email = trim($email);
    if ($email === '' || !str_contains($email, '@')) {
        return $email === '' ? '' : '•••';
    }
    [$local, $domain] = explode('@', $email, 2);
    return mb_substr($local, 0, 1) . '•••@' . $domain;
}

function mask_phone(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if ($d === '') {
        return '';
    }
    return '••• ' . substr($d, -4);
}

function pii_revealed(int $id): bool
{
    return isset($_SESSION['revealed'][$id]);
}

function fmt_date(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return '—';
    }
    $ts = strtotime(substr($date, 0, 10) . ' UTC');
    return $ts ? gmdate('M j, Y', $ts) : $date;
}

function days_label(?int $d): string
{
    if ($d === null) {
        return '—';
    }
    if ($d < 0) {
        return abs($d) . 'd overdue';
    }
    if ($d === 0) {
        return 'today';
    }
    return 'in ' . $d . 'd';
}

function tier_badge(string $tier): string
{
    $t = $tier !== '' ? $tier : 'Unknown';
    $cls = 'tier tier-' . preg_replace('/[^a-z]+/', '', strtolower($t));
    return '<span class="' . h($cls) . '">' . h($t) . '</span>';
}

function renewal_statuses(): array
{
    return ['not_started' => 'Not started', 'contacted' => 'Contacted', 'quoted' => 'Quoted', 'remarketing' => 'Remarketing', 'renewed' => 'Renewed', 'lost' => 'Lost'];
}

function renewal_status_pill(?string $status): string
{
    $s = (string)$status !== '' ? (string)$status : 'not_started';
    $labels = renewal_statuses();
    return '<span class="pill st-' . h($s) . '">' . h($labels[$s] ?? $s) . '</span>';
}

function renewal_cadence(): array
{
    $out = [];
    foreach (explode(',', setting('renewal_cadence', '120,90,75,45,30,15')) as $p) {
        $n = (int)trim($p);
        if ($n > 0 && $n <= 365) {
            $out[$n] = $n;
        }
    }
    rsort($out);
    return $out ?: [120, 90, 75, 45, 30, 15];
}

function next_touch(?int $days, string $expiration): array
{
    if ($days === null) {
        return ['label' => '—', 'due' => false];
    }
    if ($days < 0) {
        return ['label' => 'Expired · log outcome', 'due' => true];
    }
    foreach (renewal_cadence() as $m) {
        if ($days >= $m) {
            $when = $days - $m;
            $date = gmdate('M j', (int)strtotime(substr($expiration, 0, 10) . ' UTC') - $m * 86400);
            return ['label' => 'T-' . $m . ' · ' . ($when === 0 ? 'today' : $date), 'due' => $when <= 3];
        }
    }
    return ['label' => 'Final call now', 'due' => true];
}

function household_count(string $hid): int
{
    static $map = null;
    if ($hid === '') {
        return 1;
    }
    if ($map === null) {
        $map = [];
        $pdo = $GLOBALS['LI_PDO'] ?? null;
        if ($pdo instanceof PDO) {
            try {
                foreach ($pdo->query("SELECT household_id, COUNT(*) AS c FROM policyholders WHERE household_id IS NOT NULL AND household_id <> '' GROUP BY household_id")->fetchAll() as $r) {
                    $map[(string)$r['household_id']] = (int)$r['c'];
                }
            } catch (Throwable $e) {
                $map = [];
            }
        }
    }
    return max(1, (int)($map[$hid] ?? 1));
}

function nullable_text(string $s): ?string
{
    $s = trim($s);
    return $s === '' ? null : $s;
}

/* ---------- scoring explainability ---------- */

function score_policy_record(array $rec, array $imputed, array $rules): array
{
    $score = compute_confidence($rec, $imputed);
    $riskOut = evaluate_rules_for_record($rec, $rules);
    $hits = (array)($riskOut['rule_hits'] ?? []);
    $score = min(100, (int)round($score + min(20, count($hits) * 4)));
    return [
        'ltv' => compute_ltv($rec),
        'risk_tier' => (string)($riskOut['risk_tier'] ?? 'Unknown'),
        'confidence' => $score,
        'rule_hits' => $hits,
        'tags' => (array)($riskOut['tags'] ?? []),
        'tier_rule_id' => (int)($riskOut['tier_rule_id'] ?? 0),
    ];
}

function ltv_assumptions(): array
{
    return [
        'term_months' => max(1, (int)setting('ltv_term_months', (string)EXPECTED_POLICY_TERM_MONTHS_DEFAULT)),
        'retention' => max(0.0, min(1.0, (float)setting('ltv_retention', (string)RETENTION_ADJUSTMENT_DEFAULT))),
        'default_multiplier' => max(0.0, (float)setting('ltv_cross_sell_default', (string)DEFAULT_CROSS_SELL_MULTIPLIER)),
        'premium_period' => setting('premium_period', 'monthly') === 'annual' ? 'annual' : 'monthly',
    ];
}

function ltv_breakdown(array $r): ?array
{
    if (($r['premium_amount'] ?? null) === null || ($r['tenure_months'] ?? null) === null || trim((string)($r['policy_type'] ?? '')) === '') {
        return null;
    }
    $a = ltv_assumptions();
    $premium = (float)$r['premium_amount'];
    $monthly = $a['premium_period'] === 'annual' ? $premium / 12 : $premium;
    $tenure = (int)$r['tenure_months'];
    $remaining = max(0, $a['term_months'] - $tenure);
    $ptype = strtolower(trim((string)$r['policy_type']));
    $mult = (float)(CROSS_SELL_MULTIPLIER_BY_POLICY_TYPE[$ptype] ?? $a['default_multiplier']);
    return [
        'premium' => $premium,
        'monthly' => $monthly,
        'premium_period' => $a['premium_period'],
        'term_months' => $a['term_months'],
        'tenure' => $tenure,
        'remaining' => $remaining,
        'policy_type' => $ptype,
        'multiplier' => $mult,
        'multiplier_source' => isset(CROSS_SELL_MULTIPLIER_BY_POLICY_TYPE[$ptype]) ? $ptype : 'default',
        'retention' => $a['retention'],
        'ltv' => round($monthly * $remaining * $mult * $a['retention'], 2),
    ];
}

function confidence_breakdown(array $r, array $imputed, int $ruleHits): array
{
    $lines = [['Starting score', 100]];
    if ($imputed) {
        $lines[] = ['Filled in for you: ' . implode(', ', array_map('strval', $imputed)), -min(45, count($imputed) * 10)];
    }
    foreach (['age' => 'Age', 'gender' => 'Gender', 'sum_assured' => lbl('sum_assured')] as $k => $label) {
        $v = $r[$k] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            $lines[] = [$label . ' missing', -5];
        }
    }
    $base = max(0, min(100, array_sum(array_map(fn($l) => (int)$l[1], $lines))));
    if ($ruleHits > 0) {
        $lines[] = ['Matched ' . $ruleHits . ' scoring rule' . ($ruleHits === 1 ? '' : 's') . ' (+4 each, max +20)', min(20, $ruleHits * 4)];
    }
    return ['lines' => $lines, 'total' => min(100, $base + min(20, $ruleHits * 4))];
}

function rule_actions_summary(array $actions): string
{
    $parts = [];
    if (isset($actions['set_risk_tier']) && (string)$actions['set_risk_tier'] !== '') {
        $parts[] = 'tier → ' . (string)$actions['set_risk_tier'];
    }
    if (isset($actions['score_delta']) && (int)$actions['score_delta'] !== 0) {
        $parts[] = 'score ' . ((int)$actions['score_delta'] > 0 ? '+' : '') . (int)$actions['score_delta'];
    }
    if (isset($actions['add_tag']) && (string)$actions['add_tag'] !== '') {
        $parts[] = 'tag ' . (string)$actions['add_tag'];
    }
    if (!empty($actions['stop'])) {
        $parts[] = 'stop';
    }
    return $parts ? implode(' · ', $parts) : '—';
}

function rule_conditions_summary($conds): string
{
    if (!is_array($conds)) {
        return '';
    }
    $logic = strtoupper((string)($conds['logic'] ?? 'AND')) === 'OR' ? ' or ' : ' and ';
    $items = isset($conds['conditions']) && is_array($conds['conditions']) ? $conds['conditions'] : $conds;
    $parts = [];
    foreach ((array)$items as $c) {
        if (!is_array($c)) {
            continue;
        }
        if (isset($c['all']) || isset($c['any'])) {
            $parts[] = '(group)';
            continue;
        }
        $v = $c['value'] ?? '';
        $parts[] = (string)($c['field'] ?? '?') . ' ' . (string)($c['op'] ?? '?') . ' ' . (is_array($v) ? implode('/', array_map('strval', $v)) : (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v));
    }
    return implode($logic, $parts);
}

/* ---------- lists: field catalogue shared by SQL compiler, record evaluator and UI ---------- */

function filter_field_catalog(): array
{
    return [
        'days_to_expiry' => ['num', sql_days_to_expiry(), 'Days to expiry'],
        'expiration_date' => ['text', 'expiration_date', 'Expiration date (YYYY-MM-DD)'],
        'renewal_status' => ['text', "COALESCE(renewal_status,'not_started')", 'Renewal stage'],
        'renewal_owner' => ['text', 'renewal_owner', 'Renewal owner'],
        'status' => ['text', 'status', 'Policy status'],
        'line_of_business' => ['text', 'line_of_business', 'Line of business'],
        'policy_type' => ['text', 'policy_type', 'Policy type'],
        'carrier' => ['text', 'carrier', 'Carrier'],
        'producer' => ['text', 'producer', 'Producer'],
        'region' => ['text', 'region', 'Region'],
        'risk_tier' => ['text', 'risk_tier', 'Risk tier'],
        'household_id' => ['text', 'household_id', 'Household'],
        'policies_in_household' => ['num', "CAST((CASE WHEN household_id IS NULL OR household_id = '' THEN 1 ELSE (SELECT COUNT(*) FROM policyholders hh WHERE hh.household_id = policyholders.household_id) END) AS INTEGER)", 'Policies in household'],
        'premium_amount' => ['num', 'premium_amount', 'Premium'],
        'prior_premium' => ['num', 'prior_premium', 'Prior premium'],
        'sum_assured' => ['num', 'sum_assured', 'Coverage / sum assured'],
        'tenure_months' => ['num', 'tenure_months', 'Tenure (months)'],
        'age' => ['num', 'age', 'Age'],
        'ltv_estimate' => ['num', 'ltv_estimate', 'Lifetime value'],
        'confidence_score' => ['num', 'confidence_score', 'Data confidence'],
        'is_imputed' => ['bool', 'is_imputed', 'Has filled-in values'],
        'email' => ['text', 'email', 'Email'],
        'phone' => ['text', 'phone', 'Phone'],
        'consent_email' => ['bool', 'consent_email', 'Email consent'],
        'consent_sms' => ['bool', 'consent_sms', 'SMS consent'],
        'consent_phone' => ['bool', 'consent_phone', 'Call consent'],
        'opted_out' => ['bool', "CAST((CASE WHEN opt_out_at IS NULL OR opt_out_at = '' THEN 0 ELSE 1 END) AS INTEGER)", 'Opted out'],
    ];
}

function filter_allowed_fields(): array
{
    $out = [];
    foreach (filter_field_catalog() as $k => $def) {
        $out[$k] = $def[0] === 'text' ? 'text' : 'num';
    }
    return $out;
}

function filter_field_sql(string $field): string
{
    $cat = filter_field_catalog();
    return isset($cat[$field]) ? (string)$cat[$field][1] : $field;
}

/* ---------- segment iteration + consent-aware stats ---------- */

function segment_filter_parts(string $where): array
{
    $usesCustom = filter_expr_uses_custom($where);
    if ($usesCustom) {
        validate_filter_expr($where);
        return [true, '1=1', []];
    }
    [$sql, $params] = compile_filter_expr($where);
    return [false, $sql, $params];
}

function segment_iterate(PDO $pdo, array $ruleIds, string $where, callable $fn): void
{
    [$usesCustom, $filterSql, $filterParams] = segment_filter_parts($where);
    $selected = [];
    if ($ruleIds) {
        $selected = array_values(array_filter(load_rules_all($pdo), fn($r) => in_array((int)($r['id'] ?? 0), $ruleIds, true)));
        if (!$selected) {
            return;
        }
    }
    $offsetId = 0;
    while (true) {
        $st = $pdo->prepare('SELECT * FROM policyholders WHERE id > ? AND (' . $filterSql . ') ORDER BY id ASC LIMIT 500');
        $st->execute(array_merge([$offsetId], $filterParams));
        $rows = $st->fetchAll();
        if (!$rows) {
            break;
        }
        foreach ($rows as $ph) {
            $offsetId = (int)$ph['id'];
            $rec = policyholder_record_from_row($ph);
            if ($usesCustom && !eval_filter_expr_for_record($rec, $where)) {
                continue;
            }
            foreach ($selected as $rule) {
                if (!eval_conditions($rec, (array)($rule['conditions'] ?? []))) {
                    continue 2;
                }
            }
            $fn($ph, $rec);
        }
    }
}

function row_reachable(array $ph, string $channel): bool
{
    $optedOut = trim((string)($ph['opt_out_at'] ?? '')) !== '';
    if ($channel === 'all') {
        return true;
    }
    if ($optedOut) {
        return false;
    }
    $email = trim((string)($ph['email'] ?? ''));
    $phone = trim((string)($ph['phone'] ?? ''));
    if ($channel === 'email') {
        return (int)($ph['consent_email'] ?? 0) === 1 && $email !== '';
    }
    if ($channel === 'sms') {
        return (int)($ph['consent_sms'] ?? 0) === 1 && $phone !== '';
    }
    if ($channel === 'phone') {
        return (int)($ph['consent_phone'] ?? 0) === 1 && $phone !== '';
    }
    return $channel === 'mail';
}

function segment_stats(PDO $pdo, array $ruleIds, string $where): array
{
    $out = ['count' => 0, 'email' => 0, 'sms' => 0, 'phone' => 0, 'mail' => 0, 'opted_out' => 0, 'premium' => 0.0];
    [$usesCustom, $filterSql, $filterParams] = segment_filter_parts($where);
    if (!$usesCustom && !$ruleIds) {
        $nOpt = sql_not_opted_out();
        $st = $pdo->prepare('SELECT COUNT(*) AS c,'
            . " SUM(CASE WHEN consent_email = 1 AND email IS NOT NULL AND email <> '' AND $nOpt THEN 1 ELSE 0 END) AS e,"
            . " SUM(CASE WHEN consent_sms = 1 AND phone IS NOT NULL AND phone <> '' AND $nOpt THEN 1 ELSE 0 END) AS s,"
            . " SUM(CASE WHEN consent_phone = 1 AND phone IS NOT NULL AND phone <> '' AND $nOpt THEN 1 ELSE 0 END) AS p,"
            . " SUM(CASE WHEN $nOpt THEN 1 ELSE 0 END) AS m,"
            . ' COALESCE(SUM(premium_amount), 0) AS prem'
            . ' FROM policyholders WHERE ' . $filterSql);
        $st->execute($filterParams);
        $r = $st->fetch() ?: [];
        $out['count'] = (int)($r['c'] ?? 0);
        $out['email'] = (int)($r['e'] ?? 0);
        $out['sms'] = (int)($r['s'] ?? 0);
        $out['phone'] = (int)($r['p'] ?? 0);
        $out['mail'] = (int)($r['m'] ?? 0);
        $out['premium'] = (float)($r['prem'] ?? 0);
        $out['opted_out'] = $out['count'] - $out['mail'];
        return $out;
    }
    segment_iterate($pdo, $ruleIds, $where, static function (array $ph) use (&$out): void {
        $out['count']++;
        $out['premium'] += (float)($ph['premium_amount'] ?? 0);
        foreach (['email', 'sms', 'phone', 'mail'] as $ch) {
            if (row_reachable($ph, $ch)) {
                $out[$ch]++;
            }
        }
    });
    $out['opted_out'] = $out['count'] - $out['mail'];
    return $out;
}

function load_rules_all(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, name, conditions_json, actions_json, priority FROM rules ORDER BY priority DESC, id DESC')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'priority' => (int)$r['priority'], 'conditions' => safe_json((string)$r['conditions_json']), 'actions' => safe_json((string)$r['actions_json'])];
    }
    return $out;
}

/* ---------- import helpers ---------- */

function import_field_labels(): array
{
    return [
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
        'effective_date' => 'Effective date',
        'expiration_date' => 'Expiration / renewal date',
        'status' => 'Policy status',
        'line_of_business' => 'Line of business',
        'carrier' => 'Carrier / company',
        'producer' => 'Producer / CSR',
        'household_id' => 'Household or customer ID',
        'email' => 'Email',
        'phone' => 'Phone',
        'consent_email' => 'Email consent (yes/no)',
        'consent_sms' => 'SMS consent (yes/no)',
        'consent_phone' => 'Call consent (yes/no)',
    ];
}

function import_header_aliases(): array
{
    return [
        'record_id' => ['policy_number', 'policy number', 'policy #', 'policy#', 'policy no', 'policy no.', 'policy_no', 'policynumber', 'policy_id', 'policy id', 'pol #', 'certificate_number', 'policy', 'customer_id', 'client_id', 'account_id', 'member_id', 'id'],
        'display_name' => ['name', 'full name', 'full_name', 'named insured', 'insured name', 'insured_name', 'insured', 'client name', 'client_name', 'customer name', 'customer_name', 'account name', 'customer', 'account_label', 'label'],
        'dob' => ['dob', 'date_of_birth', 'date of birth', 'birthdate', 'birth date', 'birthday'],
        'age' => ['age'],
        'gender' => ['gender', 'sex'],
        'policy_type' => ['policy_type', 'policy type', 'product', 'plan type', 'plan', 'type'],
        'region' => ['region', 'state', 'province', 'territory', 'county', 'area'],
        'premium_amount' => ['premium_amount', 'premium', 'annual premium', 'written premium', 'total premium', 'term premium', 'monthly premium', 'monthly_premium'],
        'tenure_months' => ['tenure_months', 'tenure', 'months on book', 'months_with_agency', 'months'],
        'sum_assured' => ['sum_assured', 'sum assured', 'coverage limit', 'face amount', 'sum insured', 'coverage', 'limit', 'sum'],
        'effective_date' => ['effective_date', 'effective date', 'eff date', 'eff. date', 'policy effective date', 'term effective date', 'inception date', 'effective'],
        'expiration_date' => ['expiration_date', 'expiration date', 'exp date', 'exp. date', 'expiry date', 'expiry', 'renewal date', 'policy expiration date', 'term expiration date', 'x-date', 'xdate', 'expiration'],
        'status' => ['status', 'policy status', 'policy_status'],
        'line_of_business' => ['line_of_business', 'line of business', 'lob', 'business line', 'coverage line', 'line'],
        'carrier' => ['carrier', 'company', 'writing company', 'carrier name', 'insurer', 'insurance company', 'market'],
        'producer' => ['producer', 'agent name', 'agent', 'producer name', 'client csr', 'csr', 'account manager', 'servicer'],
        'household_id' => ['household_id', 'household', 'customer id', 'client id', 'customer number', 'client number', 'account number', 'account id'],
        'email' => ['email', 'primary email', 'email address', 'e-mail', 'client email', 'email_address'],
        'phone' => ['phone', 'primary phone', 'phone number', 'mobile', 'cell', 'cell phone', 'home phone', 'telephone', 'phone_number'],
        'consent_email' => ['consent_email', 'email_opt_in', 'email opt in', 'email opt-in', 'email consent', 'email ok', 'marketing email'],
        'consent_sms' => ['consent_sms', 'sms_opt_in', 'sms opt in', 'sms opt-in', 'sms consent', 'text opt in', 'text ok', 'sms ok'],
        'consent_phone' => ['consent_phone', 'phone_opt_in', 'phone opt in', 'call consent', 'call ok', 'phone ok'],
    ];
}

function guess_import_mapping(array $headers): array
{
    $norm = [];
    foreach ($headers as $hd) {
        $k = strtolower(preg_replace('/\s+/', ' ', trim(strip_bom((string)$hd))) ?? '');
        if ($k !== '') {
            $norm[$k] = (string)$hd;
        }
    }
    $aliases = import_header_aliases();
    $out = array_fill_keys(array_keys($aliases), '');
    $used = [];
    foreach ($aliases as $field => $needles) {
        foreach ($needles as $n) {
            $k = strtolower($n);
            if (isset($norm[$k]) && !isset($used[$norm[$k]])) {
                $out[$field] = $norm[$k];
                $used[$norm[$k]] = true;
                break;
            }
        }
    }
    foreach ($aliases as $field => $needles) {
        if ($out[$field] !== '') {
            continue;
        }
        foreach ($needles as $n) {
            $needle = strtolower($n);
            if (strlen($needle) < 4) {
                continue;
            }
            foreach ($norm as $k => $orig) {
                if (!isset($used[$orig]) && str_contains($k, $needle)) {
                    $out[$field] = $orig;
                    $used[$orig] = true;
                    break 2;
                }
            }
        }
    }
    return $out;
}

function recognised_ams_columns(array $headers): int
{
    $signals = ['policy #', 'named insured', 'line of business', 'writing company', 'client csr', 'agent name', 'expiration date', 'effective date', 'x-date', 'annual premium', 'primary email', 'primary phone', 'customer id'];
    $n = 0;
    foreach ($headers as $hd) {
        if (in_array(strtolower(trim(strip_bom((string)$hd))), $signals, true)) {
            $n++;
        }
    }
    return $n;
}

function csv_column_values(string $path, string $header, int $max = 200000): array
{
    $out = [];
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return $out;
    }
    $headers = fgetcsv($fh);
    if (!is_array($headers)) {
        fclose($fh);
        return $out;
    }
    $idx = header_index_map($headers);
    if (!isset($idx[$header])) {
        fclose($fh);
        return $out;
    }
    $pos = (int)$idx[$header];
    $n = 0;
    while (($row = fgetcsv($fh)) !== false && $n < $max) {
        $out[] = trim((string)($row[$pos] ?? ''));
        $n++;
    }
    fclose($fh);
    return $out;
}

function csv_household_counts(string $path, array $mapping): array
{
    $hdr = (string)($mapping['household_id'] ?? '');
    if ($hdr === '' || $hdr === '__generate__') {
        return [];
    }
    $counts = [];
    foreach (csv_column_values($path, $hdr) as $v) {
        if ($v !== '') {
            $counts[$v] = ($counts[$v] ?? 0) + 1;
        }
    }
    return $counts;
}

function import_existing_count(PDO $pdo, string $path, array $mapping): int
{
    $hdr = (string)($mapping['record_id'] ?? '');
    if ($hdr === '' || $hdr === '__generate__') {
        return 0;
    }
    $ids = array_values(array_unique(array_filter(csv_column_values($path, $hdr), fn($v) => $v !== '')));
    $n = 0;
    foreach (array_chunk($ids, 400) as $chunk) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM policyholders WHERE policy_number IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $st->execute($chunk);
        $n += (int)$st->fetchColumn();
    }
    return $n;
}

function policy_storage_columns(): array
{
    return ['policy_number', 'name', 'dob', 'age', 'gender', 'policy_type', 'region', 'premium_amount', 'tenure_months', 'sum_assured',
        'effective_date', 'expiration_date', 'status', 'line_of_business', 'carrier', 'producer', 'household_id', 'email', 'phone',
        'consent_email', 'consent_sms', 'consent_phone'];
}

function policy_column_values(array $v): array
{
    $text = static fn(string $k): ?string => nullable_text((string)($v[$k] ?? ''));
    return [
        'policy_number' => (string)$v['policy_number'],
        'name' => (string)$v['name'],
        'dob' => $text('dob'),
        'age' => ($v['age'] ?? null) !== null ? (int)$v['age'] : null,
        'gender' => $text('gender'),
        'policy_type' => $text('policy_type'),
        'region' => $text('region'),
        'premium_amount' => ($v['premium_amount'] ?? null) !== null ? (float)$v['premium_amount'] : null,
        'tenure_months' => ($v['tenure_months'] ?? null) !== null ? (int)$v['tenure_months'] : null,
        'sum_assured' => ($v['sum_assured'] ?? null) !== null ? (float)$v['sum_assured'] : null,
        'effective_date' => $text('effective_date'),
        'expiration_date' => $text('expiration_date'),
        'status' => $text('status'),
        'line_of_business' => $text('line_of_business'),
        'carrier' => $text('carrier'),
        'producer' => $text('producer'),
        'household_id' => $text('household_id'),
        'email' => $text('email'),
        'phone' => $text('phone'),
        'consent_email' => ($v['consent_email'] ?? null) !== null ? (int)$v['consent_email'] : null,
        'consent_sms' => ($v['consent_sms'] ?? null) !== null ? (int)$v['consent_sms'] : null,
        'consent_phone' => ($v['consent_phone'] ?? null) !== null ? (int)$v['consent_phone'] : null,
    ];
}

function render_import_health(PDO $pdo): void
{
    $total = (int)$pdo->query('SELECT COUNT(*) FROM policyholders')->fetchColumn();
    if ($total === 0) {
        return;
    }
    $checks = [
        ['Expiration date', "expiration_date IS NOT NULL AND expiration_date <> ''", 'no_expiry', 'Needed for renewals and Today'],
        ['Email or phone', "(email IS NOT NULL AND email <> '') OR (phone IS NOT NULL AND phone <> '')", 'no_contact', 'Needed for outreach'],
        ['Any channel consent', 'consent_email = 1 OR consent_sms = 1 OR consent_phone = 1', '', 'Needed for consent-filtered lists'],
        ['Household / customer ID', "household_id IS NOT NULL AND household_id <> ''", '', 'Finds single-line households'],
        ['Premium, tenure and type', "premium_amount IS NOT NULL AND tenure_months IS NOT NULL AND policy_type IS NOT NULL AND policy_type <> ''", 'unknown_tier', 'Needed for LTV'],
        ['No filled-in values', 'is_imputed = 0', 'imputed', 'Values came straight from your file'],
    ];
    echo '<div class="card"><div class="cardHead"><div><div class="h2">Data health</div><div class="muted">Coverage across ' . h(number_format($total)) . ' records. Fix in the Book, or map the column on your next import — re-imports update in place.</div></div></div>';
    echo '<table class="table compact"><thead><tr><th>Field</th><th>Coverage</th><th class="right">Records</th><th>Why it matters</th><th></th></tr></thead><tbody>';
    foreach ($checks as [$label, $where, $view, $why]) {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM policyholders WHERE ' . $where)->fetchColumn();
        $pct = (int)round($n * 100 / $total);
        echo '<tr><td>' . h($label) . '</td><td class="meterCell"><span class="meter"><span class="meterFill' . ($pct < 75 ? ' meterWarn' : '') . '" data-w="' . h((string)$pct) . '"></span></span></td><td class="right">' . h($pct . '%') . '</td><td class="muted">' . h($why) . '</td><td>' . ($view !== '' && $pct < 100 ? '<a class="link" href="' . h(self_url(['tab' => 'profiles', 'view' => $view])) . '">Show gaps</a>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function logo_svg(bool $onDark = false): string
{
    $page = $onDark ? '#FFFFFF' : '#0B0D12';
    $fold = $onDark ? '#4D66FF' : '#2540FF';
    $lines = $onDark ? '#0A0E1A' : '#FFFFFF';
    return '<svg class="logo" viewBox="0 0 32 32" width="28" height="28" aria-hidden="true" focusable="false">'
        . '<path d="M8 3.5h11.5L26 10v9.8c0 4.6-4.1 7.8-10 9.7-5.9-1.9-10-5.1-10-9.7V5.5a2 2 0 012-2z" fill="' . $page . '"/>'
        . '<path d="M19.5 3.5V8a2 2 0 002 2H26z" fill="' . $fold . '"/>'
        . '<rect x="10.5" y="14" width="9" height="2.6" rx="1.3" fill="' . $lines . '"/>'
        . '<rect x="10.5" y="19" width="6" height="2.6" rx="1.3" fill="' . $lines . '" fill-opacity=".55"/></svg>';
}

function page_meta(string $tab): array
{
    $meta = [
        'dashboard' => ['Today', 'Renewals due, opportunities and data health for your book.'],
        'renewals' => ['Renewals', 'Work the next 120 days of expirations: stage, owner and contact.'],
        'profiles' => ['Book', 'Every policy, scored. Select one to see why it got its tier.'],
        'segments' => ['Lists', 'Build a list in plain language. It refreshes when the book changes, and exports respect consent.'],
        'campaigns' => ['Campaigns', 'Model outreach before you spend. Projections from your own lists and assumptions, not guarantees.'],
        'import' => ['Import & health', 'Bring in your AMS export. Existing policies are updated, new ones added, and every extra column is kept.'],
        'rules' => ['Scoring rules', 'Readable rules that set retention-risk tiers and tags. Highest priority runs first.'],
        'settings' => ['Settings', 'Agency, currency, terminology, LTV assumptions, consent, users, retention and audit.'],
        'account' => ['Account', 'Your password and two-step verification.'],
    ];
    return $meta[$tab] ?? [ucfirst($tab), ''];
}

function tab_url(string $tab, array $params = []): string
{
    $base = (string)($_SERVER['PHP_SELF'] ?? 'liteinsurance.php');
    $q = http_build_query(array_filter(['tab' => $tab] + $params, static fn($v) => $v !== null && $v !== ''));
    return $base . ($q !== '' ? '?' . $q : '');
}

function nav_icon(string $tab): string
{
    $p = [
        'dashboard' => '<path d="M2.5 7L8 2.5 13.5 7v6.5h-11z"/><path d="M6.5 13.5V9.5h3v4"/>',
        'renewals' => '<rect x="2.5" y="3.5" width="11" height="10" rx="1.5"/><path d="M2.5 6.5h11M5.5 2v3M10.5 2v3"/>',
        'profiles' => '<path d="M4 2.5h6l2.5 2.5v8.5H4z"/><path d="M6 8h4M6 10.5h4"/>',
        'segments' => '<path d="M2.5 4h11M4.5 8h7M6.5 12h3"/>',
        'campaigns' => '<circle cx="8" cy="8" r="5.5"/><circle cx="8" cy="8" r="2.5"/>',
        'import' => '<path d="M8 2.5v8M5 7.5l3 3 3-3M3 13.5h10"/>',
        'rules' => '<circle cx="4" cy="5" r="1.5"/><circle cx="4" cy="11" r="1.5"/><path d="M5.5 5h8M5.5 11h8"/>',
        'settings' => '<circle cx="8" cy="8" r="2.2"/><path d="M8 1.8v2M8 12.2v2M1.8 8h2M12.2 8h2M3.6 3.6l1.4 1.4M11 11l1.4 1.4M3.6 12.4L5 11M11 5l1.4-1.4"/>',
    ];
    return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">' . ($p[$tab] ?? '') . '</svg>';
}

function nav_item(string $label, string $tab, string $current, ?int $count = null, string $tone = ''): string
{
    $cls = ($tab === $current) ? 'navItem navOn' : 'navItem';
    $badge = ($count !== null && $count > 0) ? '<span class="navCount' . ($tone === 'warn' ? ' navWarn' : '') . '">' . h(number_format($count)) . '</span>' : '';
    return '<a class="' . h($cls) . '" href="' . h(tab_url($tab)) . '"' . ($tab === $current ? ' aria-current="page"' : '') . '>' . nav_icon($tab) . '<span class="navLabel">' . h($label) . '</span>' . $badge . '</a>';
}

function nav_counts(PDO $pdo): array
{
    $days = sql_days_to_expiry();
    $open = "expiration_date IS NOT NULL AND expiration_date <> '' AND COALESCE(renewal_status,'not_started') NOT IN ('renewed','lost') AND " . sql_in_force();
    $max = max(renewal_cadence());
    $st = $pdo->prepare("SELECT SUM(CASE WHEN $days BETWEEN 0 AND 30 THEN 1 ELSE 0 END) AS today, SUM(CASE WHEN $days BETWEEN -30 AND ? THEN 1 ELSE 0 END) AS ren FROM policyholders WHERE $open");
    $st->execute([$max]);
    $r = $st->fetch() ?: [];
    return [
        'today' => (int)($r['today'] ?? 0),
        'renewals' => (int)($r['ren'] ?? 0),
        'lists' => (int)$pdo->query('SELECT COUNT(*) FROM segments')->fetchColumn(),
        'imputed' => (int)$pdo->query('SELECT COUNT(*) FROM policyholders WHERE is_imputed = 1')->fetchColumn(),
    ];
}

function workspace_box(PDO $pdo, bool $isAdmin): string
{
    $name = trim(setting('agency_name', ''));
    $n = (int)$pdo->query('SELECT COUNT(*) FROM policyholders')->fetchColumn();
    $last = (string)($pdo->query('SELECT MAX(created_at) FROM imports')->fetchColumn() ?: '');
    $ts = $last !== '' ? strtotime($last) : false;
    $sub = number_format($n) . ' ' . ($n === 1 ? 'policy' : 'policies') . ($ts ? ' · synced ' . gmdate('j M', $ts) : ($n ? '' : ' · nothing imported yet'));
    $inner = '<span class="wsMeta"><span class="wsName">' . h($name !== '' ? $name : 'Your agency') . '</span><span class="wsSub">' . h($sub) . '</span></span>'
        . '<svg viewBox="0 0 14 14" aria-hidden="true"><path d="M4 5.5l3-3 3 3M4 8.5l3 3 3-3"/></svg>';
    if (!$isAdmin) {
        return '<div class="wsBox">' . $inner . '</div>';
    }
    return '<a class="wsBox" href="' . h(tab_url('import')) . '" title="Import a newer export or check data health">' . $inner . '</a>';
}

function render_app(PDO $pdo, array $user, string $tab): void
{
    $flash = consume_flash();
    $tab = normalize_tab($tab);
    $GLOBALS['LI_USER'] = $user;
    [$title, $subtitle] = page_meta($tab);
    if ($tab === 'renewals') {
        $subtitle = renewals_subtitle($pdo);
    } elseif (in_array($tab, ['dashboard', 'profiles'], true)) {
        $subtitle = page_subtitle($pdo, $tab, $subtitle);
    }
    $isAdmin = (string)$user['role'] === 'admin';
    $counts = nav_counts($pdo);

    echo '<!doctype html><html lang="en"><head>';
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title . ' — ' . APP_NAME) . '</title>';
    echo '<link rel="icon" href="data:image/svg+xml,' . rawurlencode(logo_svg(false)) . '">';
    echo inter_font_css() . app_css();
    echo '</head><body data-currency="' . h(currency_symbol()) . '">';
    echo '<div class="appShell">';

    echo '<aside class="sideNav" aria-label="Main">';
    echo '<a class="brand" href="' . h(tab_url('dashboard')) . '">' . logo_svg(true) . '<span>' . h(APP_NAME) . '</span></a>';
    echo workspace_box($pdo, $isAdmin);
    echo '<div class="navGroup">Work</div>';
    echo nav_item('Today', 'dashboard', $tab, $counts['today']);
    echo nav_item('Renewals', 'renewals', $tab, $counts['renewals']);
    echo nav_item('Book', 'profiles', $tab);
    if ($isAdmin) {
        echo nav_item('Lists', 'segments', $tab, $counts['lists']);
        echo nav_item('Campaigns', 'campaigns', $tab);
        echo '<div class="navGroup">Data</div>';
        echo nav_item('Import & health', 'import', $tab, $counts['imputed'], 'warn');
        echo '<div class="navGroup">Admin</div>';
        echo nav_item('Scoring rules', 'rules', $tab);
        echo nav_item('Settings', 'settings', $tab);
    }
    $email = (string)$user['email'];
    $local = strpos($email, '@') !== false ? (string)strstr($email, '@', true) : $email;
    echo '<div class="sideFoot"><div class="userRow">';
    echo '<a class="userCard' . ($tab === 'account' ? ' userCardOn' : '') . '" href="' . h(tab_url('account')) . '" title="Account: ' . h($email) . '"><span class="userAvatar">' . h(owner_initials($email)) . '</span><span class="userMeta"><span class="userName">' . h($local !== '' ? $local : $email) . '</span><span class="userRole">' . h(ucfirst((string)$user['role'])) . ' · signed in' . ((int)($user['totp_enabled'] ?? 0) === 1 ? ' · 2FA' : '') . '</span></span></a>';
    echo '<form method="post" action="' . h(self_url(['action' => null])) . '">' . csrf_field() . '<input type="hidden" name="action" value="logout"><button class="navLogout" type="submit" title="Sign out" aria-label="Sign out"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M6 2.5H3.5v11H6M10 5l3 3-3 3M13 8H6"/></svg></button></form>';
    echo '</div><div class="sideVer">v' . h(APP_VERSION) . ' · PHP ' . h(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) . ' · SQLite</div></div>';
    echo '</aside>';

    echo '<header class="topBar"><div class="topTitle"><h1 class="h1">' . h($title) . '</h1><div class="muted">' . h($subtitle) . '</div></div>';
    echo in_array($tab, ['renewals', 'segments', 'campaigns', 'import'], true) ? '' : '<form class="topSearch" method="get" role="search"><input type="hidden" name="tab" value="profiles"><input class="input" type="search" name="q" placeholder="Search policy, ' . h(strtolower(lbl('client'))) . ' or household" aria-label="Search the book" value="' . h($tab === 'profiles' ? (string)($_GET['q'] ?? '') : '') . '"></form>';
    echo page_actions($tab);
    echo '</header>';

    echo '<main class="main">';
    if ($flash) {
        echo render_flash($flash);
    }

    if (!$isAdmin && in_array($tab, ['import', 'rules', 'segments', 'campaigns', 'settings'], true)) {
        echo '<div class="card"><div class="h2">Admin required</div><div class="muted">This tab is available to admins only.</div></div>';
    } else {
        if ($tab === 'account') {
            render_tab_account($pdo, $user);
        } elseif ($tab === 'dashboard') {
            render_tab_dashboard($pdo);
        } elseif ($tab === 'renewals') {
            render_tab_renewals($pdo);
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
    echo '<footer class="footer"><span>' . h(APP_NAME) . ' · one PHP file + SQLite</span><a class="link" href="SETUP.md" target="_blank" rel="noopener">Docs</a><a class="link" href="SECURITY.md" target="_blank" rel="noopener">Security</a></footer>';
    echo '</div>';
    echo app_js();
    echo '</body></html>';
}

function page_subtitle(PDO $pdo, string $tab, string $fallback): string
{
    $n = (int)$pdo->query('SELECT COUNT(*) FROM policyholders')->fetchColumn();
    if ($n === 0) {
        return $fallback;
    }
    if ($tab === 'dashboard') {
        $last = $pdo->query('SELECT filename, created_at FROM imports ORDER BY created_at DESC, id DESC LIMIT 1')->fetch() ?: null;
        $when = $last ? strtotime((string)$last['created_at']) : false;
        $ago = '';
        if ($when) {
            $mins = max(0, (int)floor((time() - $when) / 60));
            $ago = $mins < 1 ? 'just now' : ($mins < 60 ? $mins . ' min ago' : ($mins < 1440 ? (int)floor($mins / 60) . ' h ago' : gmdate('j M', $when)));
        }
        return gmdate('l, j F', strtotime(li_today() . ' UTC') ?: time()) . ($last ? ' · book refreshed from ' . (string)$last['filename'] . ($ago !== '' ? ' ' . $ago : '') : '');
    }
    $hh = (int)$pdo->query("SELECT COUNT(DISTINCT household_id) FROM policyholders WHERE household_id IS NOT NULL AND household_id <> ''")->fetchColumn();
    return number_format($n) . ($n === 1 ? ' policy' : ' policies') . ($hh ? ' · ' . number_format($hh) . ' households' : '') . ' · masked names; reveal is logged';
}

function kpi(string $label, string $value, string $sub = '', string $tone = ''): string
{
    return '<div class="kpi' . ($tone !== '' ? ' kpi-' . h($tone) : '') . '"><div class="kpiLabel">' . h($label) . '</div><div class="kpiValue">' . h($value) . '</div>' . ($sub !== '' ? '<div class="kpiSub">' . h($sub) . '</div>' : '') . '</div>';
}

function chip_link(string $label, array $params, bool $on, ?int $count = null): string
{
    return '<a class="chip' . ($on ? ' chipOn' : '') . '" href="' . h(self_url($params)) . '"' . ($on ? ' aria-current="true"' : '') . '>' . h($label) . ($count !== null ? '<span class="chipCount">' . h((string)$count) . '</span>' : '') . '</a>';
}

function current_is_admin(): bool
{
    $u = $GLOBALS['LI_USER'] ?? null;
    return is_array($u) && (string)($u['role'] ?? '') === 'admin';
}

function risk_pill(?string $tier): string
{
    $t = (string)$tier !== '' ? (string)$tier : 'Unknown';
    return '<span class="tier tierDot tier-' . h(preg_replace('/[^a-z]+/', '', strtolower($t))) . '">' . h($t) . '</span>';
}

function status_pill(?string $status): string
{
    $s = (string)$status !== '' ? (string)$status : 'not_started';
    $labels = renewal_statuses();
    return '<span class="stPill stp-' . h($s) . '">' . h($labels[$s] ?? $s) . '</span>';
}

function annual_premium(float $sum): float
{
    return setting('premium_period', 'monthly') === 'annual' ? $sum : $sum * 12;
}

function sql_risk_rank(): string
{
    return "CASE risk_tier WHEN 'High' THEN 0 WHEN 'Review' THEN 1 WHEN 'Medium' THEN 2 WHEN 'Low' THEN 3 ELSE 4 END";
}

function render_tab_dashboard(PDO $pdo): void
{
    $days = sql_days_to_expiry();
    $inForce = sql_in_force();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM policyholders')->fetchColumn();

    render_first_run_checklist($pdo, $total);
    if ($total === 0) {
        return;
    }
    $open = "expiration_date IS NOT NULL AND expiration_date <> '' AND COALESCE(renewal_status,'not_started') NOT IN ('renewed','lost') AND $inForce";

    // KPI row
    $r90 = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(premium_amount),0) AS p, SUM(CASE WHEN risk_tier IN ('High','Review') THEN 1 ELSE 0 END) AS risk FROM policyholders WHERE $open AND $days BETWEEN 0 AND 90")->fetch() ?: [];
    $hh = $pdo->query("SELECT COUNT(*) AS hh, SUM(CASE WHEN c = 1 THEN 1 ELSE 0 END) AS mono, SUM(c) AS pol FROM (SELECT COALESCE(NULLIF(household_id,''), 'solo:' || id) AS hk, COUNT(*) AS c FROM policyholders WHERE $inForce GROUP BY hk)")->fetch() ?: [];
    $agg = $pdo->query("SELECT AVG(ltv_estimate) AS ltv FROM policyholders WHERE $inForce AND ltv_estimate IS NOT NULL")->fetch() ?: [];
    $conf = (int)round((float)$pdo->query('SELECT AVG(confidence_score) FROM policyholders')->fetchColumn());
    $imputed = (int)$pdo->query('SELECT COUNT(*) FROM policyholders WHERE is_imputed = 1')->fetchColumn();
    $hhN = (int)($hh['hh'] ?? 0);
    echo '<div class="kpiRow">';
    echo kpi_card('Renewals · next 90 days', number_format((int)($r90['n'] ?? 0)), money_whole(annual_premium((float)($r90['p'] ?? 0))) . ' annual premium');
    echo kpi_card('At-risk renewals', number_format((int)($r90['risk'] ?? 0)), 'High or Review retention risk', (int)($r90['risk'] ?? 0) > 0 ? 'bad' : '');
    echo kpi_card('Policies per household', $hhN > 0 ? number_format((int)($hh['pol'] ?? 0) / $hhN, 2) : '—', number_format((int)($hh['mono'] ?? 0)) . ' of ' . number_format($hhN) . ' households are monoline');
    echo kpi_card('Average LTV', money_whole($agg['ltv'] ?? null), 'premium × remaining term × retention');
    echo kpi_card('Data confidence', (string)$conf, number_format($imputed) . ' records with imputed fields', '', $imputed > 0 ? 'warn' : '');
    echo '</div>';

    // Worklist buckets
    $buckets = ['30' => [0, 30, '≤ 30 days'], '60' => [31, 60, '31–60'], '90' => [61, 90, '61–90'], '120' => [91, 120, '91–120']];
    $w = (string)($_GET['w'] ?? '30');
    if (!isset($buckets[$w])) {
        $w = '30';
    }
    $bc = $pdo->query("SELECT SUM(CASE WHEN $days BETWEEN 0 AND 30 THEN 1 ELSE 0 END) AS b30, SUM(CASE WHEN $days BETWEEN 31 AND 60 THEN 1 ELSE 0 END) AS b60, SUM(CASE WHEN $days BETWEEN 61 AND 90 THEN 1 ELSE 0 END) AS b90, SUM(CASE WHEN $days BETWEEN 91 AND 120 THEN 1 ELSE 0 END) AS b120 FROM policyholders WHERE $open")->fetch() ?: [];
    [$lo, $hi] = $buckets[$w];
    $st = $pdo->prepare("SELECT id, policy_number, name, policy_type, line_of_business, expiration_date, premium_amount, prior_premium, risk_tier, renewal_status, renewal_owner, producer, $days AS d FROM policyholders WHERE $open AND $days BETWEEN ? AND ? ORDER BY d ASC, " . sql_risk_rank() . ', id ASC LIMIT 7');
    $st->execute([$lo, $hi]);
    $work = $st->fetchAll();
    $inBucket = (int)($bc['b' . $w] ?? 0);

    echo '<div class="todayGrid"><div class="card cardFlush">';
    echo '<div class="cardHeadPad"><div><div class="h2">Renewal worklist</div><div class="muted">Sorted by days to expiry, then retention risk</div></div><nav class="seg" aria-label="Renewal window">';
    foreach ($buckets as $k => [, , $label]) {
        $k = (string)$k;
        echo '<a class="' . ($k === $w ? 'on' : '') . '" href="' . h(tab_url('dashboard', ['w' => $k !== '30' ? $k : null])) . '"' . ($k === $w ? ' aria-current="true"' : '') . '>' . h($label) . '<b>' . h(number_format((int)($bc['b' . $k] ?? 0))) . '</b></a>';
    }
    echo '</nav></div>';
    echo '<div class="scrollX"><table class="t2 dense"><thead><tr><th>Insured</th><th>' . h(lbl('policy')) . '</th><th>' . h(lbl('policy_type')) . '</th><th>Expires</th><th>Change</th><th>Risk</th><th>Owner</th><th>Status</th></tr></thead><tbody>';
    foreach ($work as $r) {
        $d = (int)$r['d'];
        $url = tab_url('profiles', ['id' => (string)$r['id']]);
        $assigned = trim((string)($r['renewal_owner'] ?? '')) !== '';
        $chg = premium_change_html($r['premium_amount'] ?? null, $r['prior_premium'] ?? null);
        echo '<tr class="rowLink" data-href="' . h($url) . '"><td><a class="ins" href="' . h($url) . '">' . h(mask_name((string)$r['name']) ?: (string)$r['policy_number']) . '</a></td><td class="mono">' . h((string)$r['policy_number']) . '</td><td>' . h((string)($r['line_of_business'] ?: $r['policy_type'] ?: '—')) . '</td>';
        echo '<td>' . h(date_dmy((string)$r['expiration_date'])) . '<span class="sub' . ($d <= 15 ? ' due' : '') . '">' . h(days_words($d)) . '</span></td>';
        echo '<td>' . ($chg !== '' ? trim($chg) : '<span class="muted">—</span>') . '</td>';
        echo '<td>' . risk_pill((string)($r['risk_tier'] ?? '')) . '</td><td>' . owner_avatar($assigned ? (string)$r['renewal_owner'] : (string)($r['producer'] ?? ''), $assigned) . '</td><td>' . status_pill($r['renewal_status'] ?? null) . '</td></tr>';
    }
    if (!$work) {
        $noDates = (int)$pdo->query("SELECT COUNT(*) FROM policyholders WHERE expiration_date IS NULL OR expiration_date = ''")->fetchColumn();
        echo '<tr><td colspan="8" class="empty">' . h($noDates === $total ? 'No expiration dates yet. Map an expiration or effective date column on import to fill this worklist.' : 'Nothing open in this window.') . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="cardFoot"><span>Showing ' . h((string)count($work)) . ' of ' . h(number_format($inBucket)) . ' · Change = renewal premium vs last term; ≥ 10% suggests remarketing</span><a class="linkStrong" href="' . h(tab_url('renewals')) . '">Open Renewals →</a></div>';
    echo '</div>';

    echo '<div class="stack">';
    render_today_opportunities($pdo);
    render_today_health($pdo, $imputed, $total);
    render_today_consent($pdo);
    echo '</div></div>';
}

function kpi_card(string $label, string $value, string $sub, string $valueTone = '', string $subTone = ''): string
{
    return '<div class="kpiCard"><div class="l">' . h($label) . '</div><div class="n' . ($valueTone !== '' ? ' ' . h($valueTone) : '') . '">' . h($value) . '</div><div class="s' . ($subTone !== '' ? ' ' . h($subTone) : '') . '">' . h($sub) . '</div></div>';
}

function date_dmy(string $date): string
{
    if (trim($date) === '') {
        return '—';
    }
    $ts = strtotime(substr($date, 0, 10) . ' UTC');
    return $ts ? gmdate('j M Y', $ts) : $date;
}

function days_words(?int $d): string
{
    if ($d === null) {
        return '—';
    }
    if ($d < 0) {
        return abs($d) . (abs($d) === 1 ? ' day' : ' days') . ' overdue';
    }
    if ($d === 0) {
        return 'today';
    }
    return 'in ' . $d . ($d === 1 ? ' day' : ' days');
}

function opp_icon(string $kind): string
{
    $p = [
        'stack' => '<rect x="2.5" y="5" width="7" height="7" rx="1"/><rect x="6.5" y="3" width="7" height="7" rx="1"/>',
        'filter' => '<path d="M2.5 4h11M4.5 8h7M6.5 12h3"/>',
        'star' => '<path d="M8 2.2l1.8 3.7 4 .6-2.9 2.8.7 4L8 11.4l-3.6 1.9.7-4-2.9-2.8 4-.6z"/>',
        'up' => '<path d="M2.5 11.5l4-4 3 3 4-5"/><path d="M10 5.5h3.5V9"/>',
    ];
    return '<span class="oppIc"><svg viewBox="0 0 16 16" aria-hidden="true">' . ($p[$kind] ?? '') . '</svg></span>';
}

function render_today_opportunities(PDO $pdo): void
{
    $inForce = sql_in_force();
    $days = sql_days_to_expiry();
    $rows = [];
    $hhKnown = (int)$pdo->query("SELECT COUNT(*) FROM policyholders WHERE household_id IS NOT NULL AND household_id <> ''")->fetchColumn();
    if ($hhKnown > 0) {
        $mono = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT household_id FROM policyholders WHERE household_id IS NOT NULL AND household_id <> '' AND $inForce GROUP BY household_id HAVING COUNT(*) = 1)")->fetchColumn();
        $rows[] = ['stack', 'Monoline households', 'Households with only one policy: round them out', $mono, 'Build list', tab_url('segments', ['new' => '1', 'preset' => 'single'])];
    }
    $seg = $pdo->query('SELECT id, name, rule_ids FROM segments ORDER BY COALESCE(last_run_at, \'\') DESC, id DESC LIMIT 1')->fetch();
    if ($seg) {
        $c = safe_json((string)($seg['rule_ids'] ?? ''));
        $stats = is_array($c['stats'] ?? null) ? (array)$c['stats'] : null;
        $cnt = $stats ? (int)($stats['count'] ?? 0) : null;
        if ($cnt === null) {
            try {
                [$ruleIds, $where] = segment_criteria($seg);
                $cnt = (int)segment_stats($pdo, $ruleIds, $where)['count'];
            } catch (Throwable $e) {
                $cnt = 0;
            }
        }
        $desc = is_array($c) && trim((string)($c['description'] ?? '')) !== '' ? (string)$c['description'] : 'Saved list';
        $rows[] = ['filter', (string)$seg['name'], $desc, $cnt, 'Open', tab_url('segments', ['edit' => (string)$seg['id']])];
    }
    $jump = (int)$pdo->query("SELECT COUNT(*) FROM policyholders WHERE prior_premium IS NOT NULL AND prior_premium > 0 AND premium_amount >= prior_premium * 1.10 AND expiration_date IS NOT NULL AND $days BETWEEN 0 AND 120 AND $inForce")->fetchColumn();
    if ($jump > 0 || count($rows) < 2) {
        $rows[] = ['up', 'Premium up 10%+ at renewal', 'Remarket before they shop around', $jump, 'Open', tab_url('renewals', ['chg' => '1'])];
    }
    if (count($rows) < 3) {
        $avg = (float)$pdo->query("SELECT AVG(ltv_estimate) FROM policyholders WHERE $inForce AND ltv_estimate IS NOT NULL")->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM policyholders WHERE $inForce AND ltv_estimate >= ? AND expiration_date IS NOT NULL AND $days BETWEEN 0 AND 90");
        $st->execute([$avg * 1.25]);
        $rows[] = ['star', 'High-LTV renewals due a review', 'LTV 25% above average, renewing in 90 days', (int)$st->fetchColumn(), 'Open', tab_url('profiles', ['view' => 'renewing'])];
    }
    echo '<div class="card cardFlush"><div class="cardHeadPad"><div><div class="h2">Opportunities</div><div class="muted">From your saved and suggested lists</div></div></div>';
    foreach (array_slice($rows, 0, 3) as [$icon, $title, $desc, $n, $cta, $url]) {
        echo '<a class="oppRow" href="' . h($url) . '">' . opp_icon($icon) . '<span><strong>' . h($title) . '</strong><span class="muted">' . h($desc) . '</span></span><span class="oppNum"><b>' . h(number_format((int)$n)) . '</b><em>' . h($cta) . '</em></span></a>';
    }
    echo '</div>';
}

function render_today_health(PDO $pdo, int $imputed, int $total): void
{
    $fields = [];
    try {
        foreach ($pdo->query("SELECT je.value AS f, COUNT(*) AS c FROM policyholders, json_each(policyholders.metadata, '$.imputed_fields') AS je GROUP BY je.value ORDER BY c DESC LIMIT 5")->fetchAll() as $r) {
            $fields[(string)$r['f']] = (int)$r['c'];
        }
    } catch (Throwable $e) {
        $fields = [];
    }
    $noExpiry = (int)$pdo->query("SELECT COUNT(*) FROM policyholders WHERE expiration_date IS NULL OR expiration_date = ''")->fetchColumn();
    if ($noExpiry > 0) {
        $fields = ['expiration_date' => $noExpiry] + $fields;
    }
    echo '<div class="card cardFlush"><div class="cardHeadPad"><div><div class="h2">Data health</div><div class="muted">' . h($imputed > 0 ? number_format($imputed) . ' records have imputed fields' : 'Every scoring field came from your file') . '</div></div><a class="btnSecondary" href="' . h(tab_url('profiles', ['view' => 'issues'])) . '">Fix queue</a></div>';
    if ($fields) {
        echo '<div class="hbars">';
        foreach (array_slice($fields, 0, 5, true) as $f => $c) {
            $pct = $total > 0 ? (int)round($c * 100 / $total) : 0;
            echo '<div class="hbar" title="' . h(number_format($c) . ' of ' . number_format($total) . ($f === 'expiration_date' ? ' records have no expiration date' : ' records had ' . $f . ' filled in from book averages')) . '"><span>' . h($f) . '</span><i><b data-w="' . h((string)max(2, $pct)) . '"></b></i><strong>' . h(number_format($c)) . '</strong></div>';
        }
        echo '</div>';
    } else {
        echo '<div class="pad muted">No gaps found. Re-imports keep it that way by updating records in place.</div>';
    }
    echo '</div>';
}

function render_today_consent(PDO $pdo): void
{
    $inForce = sql_in_force();
    $nOpt = sql_not_opted_out();
    $c = $pdo->query("SELECT SUM(CASE WHEN consent_phone = 1 AND phone IS NOT NULL AND phone <> '' AND $nOpt THEN 1 ELSE 0 END) AS p, SUM(CASE WHEN consent_sms = 1 AND phone IS NOT NULL AND phone <> '' AND $nOpt THEN 1 ELSE 0 END) AS s, SUM(CASE WHEN consent_email = 1 AND email IS NOT NULL AND email <> '' AND $nOpt THEN 1 ELSE 0 END) AS e, SUM(CASE WHEN NOT $nOpt THEN 1 ELSE 0 END) AS o, SUM(CASE WHEN $nOpt AND COALESCE(consent_email,0) = 0 AND COALESCE(consent_sms,0) = 0 AND COALESCE(consent_phone,0) = 0 THEN 1 ELSE 0 END) AS none FROM policyholders WHERE $inForce")->fetch() ?: [];
    $recent = (int)$pdo->query("SELECT COUNT(*) FROM policyholders WHERE opt_out_at IS NOT NULL AND opt_out_at <> '' AND opt_out_at >= '" . gmdate('Y-m-d', time() - 14 * 86400) . "'")->fetchColumn();
    echo '<div class="card cardFlush"><div class="cardHeadPad"><div><div class="h2">Consent &amp; opt-outs</div><div class="muted">Phone and text exports respect these automatically</div></div></div><ul class="dots">';
    if ($recent > 0) {
        echo '<li class="warn">' . h(number_format($recent)) . ' opt-out' . ($recent === 1 ? '' : 's') . ' in the last 14 days: honour them within 10 business days</li>';
    }
    if ((int)($c['o'] ?? 0) > 0) {
        echo '<li class="warn">' . h(number_format((int)$c['o'])) . ' opted out of all contact: left out of every export</li>';
    }
    if ((int)($c['none'] ?? 0) > 0) {
        echo '<li class="warn">' . h(number_format((int)$c['none'])) . ' have no consent recorded on any channel</li>';
    }
    echo '<li>' . h(number_format((int)($c['p'] ?? 0))) . ' can be called · ' . h(number_format((int)($c['s'] ?? 0))) . ' texted · ' . h(number_format((int)($c['e'] ?? 0))) . ' emailed</li>';
    echo '</ul></div>';
}

function render_first_run_checklist(PDO $pdo, int $total): void
{
    if (setting('checklist_dismissed', '0') === '1' && $total > 0) {
        return;
    }
    $isAdmin = current_is_admin();
    $steps = [
        ['Create the admin account', true, null],
        ['Load the sample book or import your own CSV', $total > 0, ['tab' => 'import']],
        ['Map expiration dates and contact fields', (int)$pdo->query("SELECT COUNT(*) FROM policyholders WHERE expiration_date IS NOT NULL AND expiration_date <> ''")->fetchColumn() > 0, ['tab' => 'import']],
        ['Review the scoring rules', (int)$pdo->query('SELECT COUNT(*) FROM rules')->fetchColumn() > 0, ['tab' => 'rules']],
        ['Build your first call or email list', (int)$pdo->query('SELECT COUNT(*) FROM segments')->fetchColumn() > 0, ['tab' => 'segments']],
        ['Set currency and terminology', (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE name IN ('currency_symbol','terminology')")->fetchColumn() > 0, ['tab' => 'settings']],
    ];
    $done = count(array_filter($steps, fn($s) => $s[1]));
    if ($done === count($steps) && $total > 0) {
        return;
    }
    echo '<div class="card checklist"><div class="cardHead"><div><div class="h2">Get set up · ' . h((string)$done) . ' of ' . h((string)count($steps)) . ' done</div><div class="muted">About ten minutes from upload to a working renewal list.</div></div>';
    if ($isAdmin && $total > 0) {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="checklist_dismiss"><button class="btnGhost btnSm" type="submit">Hide</button></form>';
    }
    echo '</div><ol class="steps">';
    foreach ($steps as $i => [$label, $ok, $link]) {
        echo '<li class="step' . ($ok ? ' stepDone' : '') . '"><span class="stepNum">' . ($ok ? '✓' : h((string)($i + 1))) . '</span><span class="stepLabel">' . h($label) . '</span>';
        if (!$ok && $link && $isAdmin) {
            echo '<a class="link" href="' . h(self_url($link)) . '">Start</a>';
        }
        echo '</li>';
    }
    echo '</ol>';
    if ($total === 0 && $isAdmin) {
        echo '<div class="actionsRow"><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="load_sample"><button class="btn" type="submit">Load sample book (200 policies)</button></form><a class="btnSecondary" href="' . h(self_url(['tab' => 'import'])) . '">Import my CSV</a></div>';
    }
    echo '</div>';
}

function renewal_filters(): array
{
    $max = max(renewal_cadence());
    $win = (int)($_GET['win'] ?? ($_POST['win'] ?? $max));
    if ($win < 1 || $win > 365) {
        $win = $max;
    }
    $owner = trim((string)($_GET['owner'] ?? ($_POST['owner'] ?? '')));
    return [$win, $owner];
}

/** Line, risk and premium-change filters shared by the board and its exports. */
function renewal_extra_filters(): array
{
    $line = trim((string)($_GET['line'] ?? ($_POST['line'] ?? '')));
    $risk = (string)($_GET['risk'] ?? ($_POST['risk'] ?? '')) === '1';
    $chg = (string)($_GET['chg'] ?? ($_POST['chg'] ?? '')) === '1';
    $sql = [];
    $args = [];
    if ($line !== '') {
        $sql[] = "COALESCE(NULLIF(line_of_business,''), policy_type) = ?";
        $args[] = mb_substr($line, 0, 120);
    }
    if ($risk) {
        $sql[] = "risk_tier IN ('High','Review')";
    }
    if ($chg) {
        $sql[] = 'prior_premium > 0 AND premium_amount >= prior_premium * 1.1';
    }
    return ['line' => $line, 'risk' => $risk, 'chg' => $chg, 'sql' => implode(' AND ', $sql), 'args' => $args];
}

function renewal_owner_sql(): string
{
    return "COALESCE(NULLIF(renewal_owner,''), NULLIF(producer,''))";
}

function renewal_window_query(PDO $pdo, int $win, string $owner, string $select, string $extra = '', int $limit = 600, array $extraArgs = []): array
{
    $days = sql_days_to_expiry();
    $where = "expiration_date IS NOT NULL AND expiration_date <> '' AND $days BETWEEN -30 AND ? AND " . sql_in_force();
    $args = [$win];
    if ($owner === '__none__') {
        $where .= ' AND ' . renewal_owner_sql() . ' IS NULL';
    } elseif ($owner !== '') {
        $where .= ' AND ' . renewal_owner_sql() . ' = ?';
        $args[] = $owner;
    }
    if ($extra !== '') {
        $where .= ' AND ' . $extra;
        foreach ($extraArgs as $a) {
            $args[] = $a;
        }
    }
    $st = $pdo->prepare('SELECT ' . $select . ", $days AS d FROM policyholders WHERE " . $where . ' ORDER BY expiration_date ASC, id ASC LIMIT ' . (int)$limit);
    $st->execute($args);
    return $st->fetchAll();
}

/** Board columns follow the renewal cadence: one column per touchpoint, the last one also holds 30 days of overdue renewals. */
function renewal_stage_columns(): array
{
    $cad = array_values(renewal_cadence());
    $n = count($cad);
    $names = [
        ['Bind', 'Confirm, bind and update records'],
        ['Present to client', 'Recommendation and coverage gaps'],
        ['Compare quotes', 'Incumbent against alternatives'],
        ['Remarket decision', 'Remarket if the rise is 10–15% or more'],
        ['Update exposures', 'Ask the client what changed'],
        ['Flag & prioritise', 'Pull the list; rank by premium and risk'],
    ];
    $cols = [];
    for ($i = 0; $i < $n; $i++) {
        $hi = $cad[$i];
        $last = $i === $n - 1;
        $lo = $last ? -30 : $cad[$i + 1] + 1;
        [$title, $desc] = $names[$n - 1 - $i] ?? ['Early review', 'Check the file and the client’s situation'];
        $cols[] = ['key' => 't' . $hi, 'title' => $title, 'desc' => $desc, 'min' => $lo, 'max' => $hi, 'window' => $last ? '≤ ' . $hi . ' days' : $hi . '–' . $lo . ' days', 'hot' => $last];
    }
    return $cols;
}

function owner_initials(string $owner): string
{
    $o = trim($owner);
    if (strpos($o, '@') !== false) {
        $o = (string)strstr($o, '@', true);
    }
    $parts = preg_split('/[\s._-]+/u', $o, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) {
        return '?';
    }
    $ini = mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '');
    return mb_strtoupper($ini);
}

function owner_avatar(string $owner, bool $assigned = true): string
{
    if (trim($owner) === '') {
        return '<span class="avatar avatarNone" title="Unassigned">—</span>';
    }
    $tone = ['a', 'b', 'c', 'd'][crc32($owner) % 4];
    return '<span class="avatar avatar-' . $tone . ($assigned ? '' : ' avatarSoft') . '" title="' . h(($assigned ? 'Owner: ' : 'Producer (no owner yet): ') . $owner) . '">' . h(owner_initials($owner)) . '</span>';
}

function premium_change_html($premium, $prior): string
{
    if ($premium === null || $premium === '' || $prior === null || $prior === '' || (float)$prior <= 0) {
        return '';
    }
    $pct = ((float)$premium - (float)$prior) / (float)$prior * 100;
    $cls = $pct >= 10 ? 'chgUp' : ($pct >= 5 ? 'chgMid' : ($pct < 0 ? 'chgDown' : ''));
    return ' <b class="chg ' . $cls . '" title="Renewal premium vs prior term">' . h(($pct > 0 ? '+' : ($pct < 0 ? '−' : '')) . number_format(abs($pct), 1) . '%') . '</b>';
}

function renewals_subtitle(PDO $pdo): string
{
    $cad = renewal_cadence();
    $max = max($cad);
    $days = sql_days_to_expiry();
    $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(premium_amount),0) AS p FROM policyholders WHERE expiration_date IS NOT NULL AND expiration_date <> '' AND $days BETWEEN 0 AND ? AND COALESCE(renewal_status,'not_started') NOT IN ('renewed','lost') AND " . sql_in_force());
    $st->execute([$max]);
    $r = $st->fetch() ?: ['n' => 0, 'p' => 0];
    $annual = setting('premium_period', 'monthly') === 'annual' ? (float)$r['p'] : (float)$r['p'] * 12;
    return (int)$r['n'] . ' ' . ((int)$r['n'] === 1 ? 'policy expires' : 'policies expire') . ' in the next ' . $max . ' days · ' . money_whole($annual) . ' annual premium · stages follow a ' . implode('/', $cad) . '-day cadence';
}

function renewal_filter_hidden(array $keep = ['owner', 'line', 'risk', 'chg']): string
{
    [$win, $owner] = renewal_filters();
    $f = renewal_extra_filters();
    $vals = ['win' => (string)$win, 'owner' => $owner, 'line' => $f['line'], 'risk' => $f['risk'] ? '1' : '', 'chg' => $f['chg'] ? '1' : ''];
    $out = '';
    foreach ($vals as $k => $v) {
        if ($v !== '' && ($k === 'win' || in_array($k, $keep, true))) {
            $out .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
        }
    }
    return $out;
}

function page_actions(string $tab): string
{
    $admin = current_is_admin();
    $out = '';
    if ($tab === 'renewals') {
        $out .= '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="renewal_export_ics">' . renewal_filter_hidden() . '<button class="btnSecondary btnLg" type="submit">Calendar feed (.ics)</button></form>'
            . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="renewal_export_calls">' . renewal_filter_hidden() . '<button class="btn btnLg" type="submit" title="Only call-consented, not opted-out ' . h(strtolower(lbl('clients'))) . ' with open renewals">Export call list</button></form>';
    } elseif ($tab === 'dashboard' && $admin) {
        $out .= '<a class="btnSecondary btnLg" href="' . h(tab_url('import')) . '">Import CSV</a><a class="btn btnLg" href="' . h(tab_url('segments', ['new' => '1'])) . '">New list</a>';
    } elseif ($tab === 'profiles') {
        $view = (string)($_GET['view'] ?? '');
        if ($admin) {
            $out .= '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="book_export">' . book_filter_hidden() . '<button class="btnSecondary btnLg" type="submit" title="Opted-out records are left out">Export CSV</button></form>';
            $out .= '<a class="btn btnLg" href="' . h(tab_url('segments', ['new' => '1', 'preset' => $view !== '' ? $view : 'all'])) . '">Save as list</a>';
        }
    } elseif ($tab === 'segments' && $admin) {
        $edit = (int)($_GET['edit'] ?? 0);
        if ($edit > 0) {
            $out .= '<a class="btnSecondary btnLg" href="' . h(tab_url('segments', ['dup' => (string)$edit])) . '">Duplicate</a>';
        }
        $out .= '<button class="btn btnLg" type="submit" form="listForm" name="action" value="segment_save">Save list</button>';
    } elseif ($tab === 'campaigns' && $admin) {
        $out .= '<form method="post" id="campCallForm">' . csrf_field() . '<input type="hidden" name="action" value="export_segment_prepare"><input type="hidden" name="id" value="" data-camp-seg><input type="hidden" name="channel" value="phone" data-camp-ch><button class="btnSecondary btnLg" type="submit" data-camp-export>Export call list</button></form>';
        $out .= '<button class="btn btnLg" type="submit" form="campaignForm">Save scenario</button>';
    } elseif ($tab === 'import' && $admin) {
        $out .= '<a class="btnSecondary btnLg" href="' . h(self_url(['action' => 'download_sample_csv'])) . '">Download sample CSV</a>';
    } elseif ($tab === 'rules' && $admin) {
        $out .= '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="export_rules"><button class="btnSecondary btnLg" type="submit">Export rules JSON</button></form><a class="btn btnLg" href="' . h(tab_url('rules')) . '#ruleEditor">New rule</a>';
    }
    return $out !== '' ? '<div class="topActions">' . $out . '</div>' : '';
}

function render_tab_renewals(PDO $pdo): void
{
    [, $owner] = renewal_filters();
    $f = renewal_extra_filters();
    $columns = renewal_stage_columns();
    $max = max(renewal_cadence());
    $rows = renewal_window_query($pdo, $max, $owner, 'id, policy_number, name, policy_type, line_of_business, carrier, producer, expiration_date, premium_amount, prior_premium, risk_tier, renewal_status, renewal_owner', $f['sql'], 2000, $f['args']);
    $rank = ['High' => 0, 'Review' => 1, 'Medium' => 2, 'Low' => 3];
    usort($rows, static function (array $a, array $b) use ($rank): int {
        return ((int)$a['d'] <=> (int)$b['d']) ?: (($rank[(string)$a['risk_tier']] ?? 4) <=> ($rank[(string)$b['risk_tier']] ?? 4)) ?: ((int)$a['id'] <=> (int)$b['id']);
    });
    $statuses = renewal_statuses();
    $owners = $pdo->query('SELECT DISTINCT ' . renewal_owner_sql() . ' AS o FROM policyholders WHERE ' . renewal_owner_sql() . ' IS NOT NULL ORDER BY o LIMIT 80')->fetchAll(PDO::FETCH_COLUMN);
    $lines = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(line_of_business,''), policy_type) AS l FROM policyholders WHERE COALESCE(NULLIF(line_of_business,''), policy_type) IS NOT NULL AND COALESCE(NULLIF(line_of_business,''), policy_type) <> '' ORDER BY l LIMIT 80")->fetchAll(PDO::FETCH_COLUMN);
    $more = (string)($_GET['more'] ?? '');
    $clear = ['id' => null, 'q' => null, 'view' => null, 'edit' => null, 'more' => null];

    echo '<form method="get" class="boardFilters" aria-label="Filter the renewal board"><input type="hidden" name="tab" value="renewals">' . ($f['risk'] ? '<input type="hidden" name="risk" value="1">' : '');
    echo '<select class="pillSelect" name="owner" aria-label="Owner" data-autosubmit><option value="">Owner: Anyone</option><option value="__none__"' . ($owner === '__none__' ? ' selected' : '') . '>Owner: Unassigned</option>';
    foreach ($owners as $o) {
        echo '<option value="' . h((string)$o) . '"' . ($owner === (string)$o ? ' selected' : '') . '>Owner: ' . h((string)$o) . '</option>';
    }
    echo '</select><select class="pillSelect" name="line" aria-label="Line" data-autosubmit><option value="">Line: All lines</option>';
    foreach ($lines as $l) {
        echo '<option value="' . h((string)$l) . '"' . ($f['line'] === (string)$l ? ' selected' : '') . '>Line: ' . h((string)$l) . '</option>';
    }
    echo '</select>';
    echo '<a class="pillToggle' . ($f['risk'] ? ' pillOn' : '') . '" href="' . h(self_url(['tab' => 'renewals', 'risk' => $f['risk'] ? null : '1'] + $clear)) . '" aria-pressed="' . ($f['risk'] ? 'true' : 'false') . '">Risk: High, Review</a>';
    echo '<label class="switch"><input type="checkbox" name="chg" value="1"' . ($f['chg'] ? ' checked' : '') . ' data-autosubmit><i aria-hidden="true"></i>Only premium change ≥ 10%</label>';
    echo '<noscript><button class="btnSecondary btnSm" type="submit">Apply</button></noscript>';
    echo '<span class="boardHint">' . h((string)count($rows)) . ' on the board · stage follows the expiry date</span>';
    echo '</form>';

    if (!$rows) {
        $any = (int)$pdo->query("SELECT COUNT(*) FROM policyholders WHERE expiration_date IS NOT NULL AND expiration_date <> ''")->fetchColumn();
        echo '<div class="card"><div class="h2">Nothing to renew here</div><div class="muted">' . ($any ? 'No policies match these filters in the next ' . h((string)$max) . ' days.' : 'Import a CSV with an expiration (X-date) or effective date column and renewals appear here.') . '</div></div>';
    }

    echo '<div class="board">';
    foreach ($columns as $col) {
        $items = array_values(array_filter($rows, static fn(array $r): bool => (int)$r['d'] >= $col['min'] && (int)$r['d'] <= $col['max']));
        $limit = $more === $col['key'] ? count($items) : 3;
        echo '<section class="boardCol' . ($col['hot'] ? ' boardHot' : '') . '" aria-label="' . h($col['title']) . '">';
        echo '<div class="boardHead"><h3>' . h($col['title']) . '<b>' . h((string)count($items)) . '</b></h3><div class="boardWin">' . h($col['window']) . '</div><p>' . h($col['desc']) . '</p></div>';
        foreach (array_slice($items, 0, $limit) as $r) {
            $d = (int)$r['d'];
            $key = (string)($r['renewal_status'] ?? '') !== '' ? (string)$r['renewal_status'] : 'not_started';
            if (!isset($statuses[$key])) {
                $key = 'not_started';
            }
            $assigned = trim((string)($r['renewal_owner'] ?? '')) !== '';
            $ownerName = $assigned ? (string)$r['renewal_owner'] : (string)($r['producer'] ?? '');
            $line = (string)($r['line_of_business'] ?: $r['policy_type'] ?: '—');
            $expTs = strtotime(substr((string)$r['expiration_date'], 0, 10) . ' UTC');
            $touch = next_touch($d, (string)$r['expiration_date']);
            echo '<article class="rcard" title="Next touch: ' . h($touch['label']) . '">';
            echo '<div class="rcardTop"><a class="rcardName" href="' . h(self_url(['tab' => 'profiles', 'id' => (string)$r['id'], 'win' => null, 'owner' => null, 'line' => null, 'risk' => null, 'chg' => null, 'more' => null])) . '">' . h(mask_name((string)$r['name']) ?: (string)$r['policy_number']) . '</a>' . owner_avatar($ownerName, $assigned) . '</div>';
            echo '<div class="rcardPol">' . h((string)$r['policy_number']) . ' · ' . h($line) . '</div>';
            echo '<div class="rcardDate' . ($d < 0 ? ' due' : '') . '">' . h($expTs ? gmdate('j M Y', $expTs) : '—') . ($d < 0 ? ' · ' . h(abs($d) . ($d === -1 ? ' day' : ' days')) . ' overdue' : '') . '</div>';
            echo '<div class="rcardPrem">' . h(money_or_blank($r['premium_amount'] ?? null)) . premium_change_html($r['premium_amount'] ?? null, $r['prior_premium'] ?? null) . '</div>';
            echo '<div class="rcardPills">' . tier_badge((string)($r['risk_tier'] ?? '')) . '</div>';
            echo '<form method="post" class="rcardForm">' . csrf_field() . '<input type="hidden" name="action" value="renewal_update"><input type="hidden" name="id" value="' . h((string)$r['id']) . '"><input type="hidden" name="renewal_owner" value="' . h((string)($r['renewal_owner'] ?? '')) . '">';
            echo '<span class="stPick stp-' . h($key) . '"><select name="renewal_status" aria-label="Renewal status for ' . h((string)$r['policy_number']) . '" data-autosubmit>';
            foreach ($statuses as $sk => $sl) {
                echo '<option value="' . h($sk) . '"' . ($sk === $key ? ' selected' : '') . '>' . h($sl) . '</option>';
            }
            echo '</select></span><noscript><button class="btnSecondary btnSm" type="submit">Save</button></noscript></form>';
            echo '</article>';
        }
        if (count($items) > $limit) {
            echo '<a class="boardMore" href="' . h(self_url(['tab' => 'renewals', 'more' => $col['key']])) . '">+ ' . h((string)(count($items) - $limit)) . ' more</a>';
        } elseif ($more === $col['key'] && count($items) > 3) {
            echo '<a class="boardMore" href="' . h(self_url(['tab' => 'renewals', 'more' => null])) . '">Show fewer</a>';
        }
        if (!$items) {
            echo '<div class="boardEmpty">Nothing in this window.</div>';
        }
        echo '</section>';
    }
    echo '</div>';
    echo '<p class="muted tiny boardNote">Open a card to assign an owner, log a call or see why it was scored. Owner shows the assigned renewal owner, or the producer (faded) until someone is assigned.</p>';
}

function handle_renewal_update(PDO $pdo, array $user): void
{
    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['renewal_status'] ?? '');
    $owner = trim((string)($_POST['renewal_owner'] ?? ''));
    if ($id <= 0 || !array_key_exists($status, renewal_statuses())) {
        flash('error', 'Pick a valid renewal stage.');
        redirect_to([]);
    }
    $st = $pdo->prepare('UPDATE policyholders SET renewal_status = ?, renewal_owner = ?, updated_at = ? WHERE id = ?');
    $st->execute([$status === 'not_started' ? null : $status, $owner !== '' ? mb_substr($owner, 0, 120) : null, now_iso(), $id]);
    audit($pdo, (int)$user['id'], 'renewals.update', ['id' => $id, 'status' => $status, 'owner' => $owner]);
    flash('success', 'Renewal updated.');
    redirect_to([]);
}

function handle_renewal_export_calls(PDO $pdo, array $user): void
{
    [$win, $owner] = renewal_filters();
    $f = renewal_extra_filters();
    $rows = renewal_window_query($pdo, $win, $owner, '*', "COALESCE(renewal_status,'not_started') NOT IN ('renewed','lost') AND consent_phone = 1 AND phone IS NOT NULL AND phone <> '' AND " . sql_not_opted_out() . ($f['sql'] !== '' ? ' AND ' . $f['sql'] : ''), 5000, $f['args']);
    audit($pdo, (int)$user['id'], 'renewals.export_calls', ['window' => $win, 'owner' => $owner, 'rows' => count($rows)]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="renewal_call_list_' . gmdate('Ymd') . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['policy_number', 'name', 'phone', 'line', 'carrier', 'expiration_date', 'days_to_expiry', 'next_touch', 'premium_amount', 'prior_premium', 'risk_tier', 'renewal_status', 'renewal_owner', 'last_contacted_at']);
    foreach ($rows as $r) {
        $touch = next_touch((int)$r['d'], (string)$r['expiration_date']);
        fputcsv($out, [(string)$r['policy_number'], (string)$r['name'], (string)$r['phone'], (string)($r['line_of_business'] ?: $r['policy_type']), (string)($r['carrier'] ?? ''), (string)$r['expiration_date'], (string)$r['d'], $touch['label'], (string)($r['premium_amount'] ?? ''), (string)($r['prior_premium'] ?? ''), (string)($r['risk_tier'] ?? ''), (string)($r['renewal_status'] ?: 'not_started'), (string)($r['renewal_owner'] ?? ''), (string)($r['last_contacted_at'] ?? '')]);
    }
    fclose($out);
}

function ics_escape(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $s);
}

function handle_renewal_export_ics(PDO $pdo, array $user): void
{
    [$win, $owner] = renewal_filters();
    $f = renewal_extra_filters();
    $rows = renewal_window_query($pdo, $win, $owner, 'id, policy_number, name, policy_type, line_of_business, carrier, expiration_date, premium_amount, risk_tier, renewal_status, renewal_owner', "COALESCE(renewal_status,'not_started') NOT IN ('renewed','lost')" . ($f['sql'] !== '' ? ' AND ' . $f['sql'] : ''), 5000, $f['args']);
    audit($pdo, (int)$user['id'], 'renewals.export_ics', ['window' => $win, 'owner' => $owner, 'rows' => count($rows)]);
    $host = preg_replace('/[^a-z0-9.-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'liteinsurance.local')) ?: 'liteinsurance.local';
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//LiteInsurance//Renewals//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:Renewals'];
    $stamp = gmdate('Ymd\THis\Z');
    foreach ($rows as $r) {
        $date = str_replace('-', '', substr((string)$r['expiration_date'], 0, 10));
        $next = gmdate('Ymd', (int)strtotime(substr((string)$r['expiration_date'], 0, 10) . ' UTC') + 86400);
        $line = (string)($r['line_of_business'] ?: $r['policy_type'] ?: '');
        $summary = 'Renewal: ' . (string)$r['policy_number'] . ($line !== '' ? ' (' . $line . ')' : '');
        $desc = mask_name((string)$r['name']) . ' · ' . money_or_blank($r['premium_amount'] ?? null) . ' · tier ' . (string)($r['risk_tier'] ?? 'Unknown') . ' · stage ' . (renewal_statuses()[(string)($r['renewal_status'] ?: 'not_started')] ?? '') . ((string)($r['renewal_owner'] ?? '') !== '' ? ' · owner ' . (string)$r['renewal_owner'] : '');
        array_push($lines, 'BEGIN:VEVENT', 'UID:renewal-' . (int)$r['id'] . '-' . $date . '@' . $host, 'DTSTAMP:' . $stamp, 'DTSTART;VALUE=DATE:' . $date, 'DTEND;VALUE=DATE:' . $next, 'SUMMARY:' . ics_escape($summary), 'DESCRIPTION:' . ics_escape($desc), 'END:VEVENT');
    }
    $lines[] = 'END:VCALENDAR';
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="renewals_' . gmdate('Ymd') . '.ics"');
    echo implode("\r\n", $lines) . "\r\n";
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
    if (!current_is_admin()) {
        return '<td' . ($variant === 'num' ? ' class="right"' : '') . '>' . h($value) . '</td>';
    }
    return '<td' . ($variant === 'num' ? ' class="right"' : '') . '><input class="' . h($classes) . '" data-policy-cell data-policy-id="' . h((string)$id) . '" data-field="' . h($field) . '" value="' . h($value) . '" aria-label="' . h($label) . '"></td>';
}

function book_views(): array
{
    $days = sql_days_to_expiry();
    return [
        '' => ['All policies', ''],
        'renewing' => ['Renewing ≤ 90 days', "expiration_date IS NOT NULL AND $days BETWEEN 0 AND 90"],
        'at_risk' => ['High retention risk', "risk_tier IN ('High','Review')"],
        'issues' => ['Data issues', "(is_imputed = 1 OR confidence_score < 60 OR expiration_date IS NULL OR expiration_date = '')"],
        'low_conf' => ['Low confidence (< 60)', 'confidence_score < 60'],
        'no_contact' => ['No email or phone', "(email IS NULL OR email = '') AND (phone IS NULL OR phone = '')"],
        'no_expiry' => ['No expiration date', "(expiration_date IS NULL OR expiration_date = '')"],
        'imputed' => ['Filled-in values', 'is_imputed = 1'],
        'unknown_tier' => ['Tier unknown', "(risk_tier IS NULL OR risk_tier = 'Unknown')"],
        'opted_out' => ['Opted out', "(opt_out_at IS NOT NULL AND opt_out_at <> '')"],
    ];
}

function book_filters(): array
{
    $views = book_views();
    $view = (string)($_GET['view'] ?? ($_POST['view'] ?? ''));
    if (!isset($views[$view])) {
        $view = '';
    }
    $q = trim((string)($_GET['q'] ?? ($_POST['q'] ?? '')));
    $tier = trim((string)($_GET['tier'] ?? ($_POST['tier'] ?? '')));
    $region = trim((string)($_GET['region'] ?? ($_POST['region'] ?? '')));
    $where = [];
    $args = [];
    if ($q !== '') {
        $where[] = '(policy_number LIKE ? OR name LIKE ? OR household_id LIKE ? OR email LIKE ?)';
        array_push($args, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
    }
    if ($tier !== '') {
        $where[] = 'risk_tier = ?';
        $args[] = $tier;
    }
    if ($region !== '') {
        $where[] = 'region = ?';
        $args[] = $region;
    }
    if ($view !== '' && $views[$view][1] !== '') {
        $where[] = '(' . $views[$view][1] . ')';
    }
    return ['view' => $view, 'q' => $q, 'tier' => $tier, 'region' => $region, 'sql' => $where ? implode(' AND ', $where) : '1=1', 'args' => $args];
}

function book_filter_hidden(): string
{
    $f = book_filters();
    $out = '';
    foreach (['view', 'q', 'tier', 'region'] as $k) {
        if ($f[$k] !== '') {
            $out .= '<input type="hidden" name="' . h($k) . '" value="' . h($f[$k]) . '">';
        }
    }
    return $out;
}

function render_tab_profiles(PDO $pdo): void
{
    $f = book_filters();
    $views = book_views();
    $view = $f['view'];
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 12;
    $offset = ($page - 1) * $limit;
    $isAdmin = current_is_admin();
    $days = sql_days_to_expiry();

    $st = $pdo->prepare('SELECT COUNT(*) FROM policyholders WHERE ' . $f['sql']);
    $st->execute($f['args']);
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / $limit));
    if ($page > $pages) {
        $page = $pages;
        $offset = ($page - 1) * $limit;
    }
    $st = $pdo->prepare("SELECT id, policy_number, name, policy_type, line_of_business, premium_amount, ltv_estimate, risk_tier, confidence_score, expiration_date, renewal_status, status, $days AS d FROM policyholders WHERE " . $f['sql'] . " ORDER BY CASE WHEN expiration_date IS NULL OR expiration_date = '' THEN 1 ELSE 0 END, expiration_date ASC, id ASC LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset);
    $st->execute($f['args']);
    $rows = $st->fetchAll();
    $openId = (int)($_GET['id'] ?? 0);
    if ($openId <= 0 && $rows) {
        $openId = (int)$rows[0]['id'];
    }
    $tiers = $pdo->query("SELECT DISTINCT risk_tier FROM policyholders WHERE risk_tier IS NOT NULL AND risk_tier <> '' ORDER BY risk_tier")->fetchAll(PDO::FETCH_COLUMN);
    $regions = $pdo->query("SELECT DISTINCT region FROM policyholders WHERE region IS NOT NULL AND region <> '' ORDER BY region LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
    $keep = ['q' => $f['q'] ?: null, 'tier' => $f['tier'] ?: null, 'region' => $f['region'] ?: null];

    echo '<div class="bookWrap"><div class="bookMain">';
    echo '<nav class="vtabs" aria-label="Saved views">';
    $primary = ['', 'renewing', 'at_risk', 'issues'];
    foreach ($primary as $key) {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM policyholders' . ($views[$key][1] !== '' ? ' WHERE ' . $views[$key][1] : ''))->fetchColumn();
        echo '<a class="' . ($key === $view ? 'on' : '') . '" href="' . h(tab_url('profiles', ['view' => $key !== '' ? $key : null] + $keep)) . '"' . ($key === $view ? ' aria-current="page"' : '') . '>' . h($views[$key][0]) . '<b>' . h(number_format($n)) . '</b></a>';
    }
    if (!in_array($view, $primary, true)) {
        echo '<a class="on" aria-current="page" href="' . h(tab_url('profiles', ['view' => $view] + $keep)) . '">' . h($views[$view][0]) . '<b>' . h(number_format($total)) . '</b></a>';
    }
    echo '<details><summary>+ More views</summary><div class="pop">';
    foreach ($views as $key => [$label, $sql]) {
        if (in_array($key, $primary, true)) {
            continue;
        }
        $n = (int)$pdo->query('SELECT COUNT(*) FROM policyholders WHERE ' . $sql)->fetchColumn();
        echo '<a href="' . h(tab_url('profiles', ['view' => $key] + $keep)) . '"><span>' . h($label) . '</span><span class="muted">' . h(number_format($n)) . '</span></a>';
    }
    echo '</div></details></nav>';

    echo '<div class="fchips">';
    $base = ['view' => $view !== '' ? $view : null] + $keep;
    foreach (['q' => 'Search', 'tier' => 'Risk', 'region' => 'Region'] as $k => $label) {
        if ($f[$k] !== '') {
            echo '<a class="fchip" href="' . h(tab_url('profiles', [$k => null] + $base)) . '" title="Remove this filter">' . h($label . ': ' . $f[$k]) . '<span aria-hidden="true">×</span></a>';
        }
    }
    echo '<details class="addFilter"><summary>+ Add filter</summary><form method="get" class="pop"><input type="hidden" name="tab" value="profiles">' . ($view !== '' ? '<input type="hidden" name="view" value="' . h($view) . '">' : '');
    echo '<label class="label" for="bq">Policy #, name, household or email</label><input class="input" id="bq" name="q" value="' . h($f['q']) . '">';
    echo '<label class="label" for="bt">Risk tier</label><select class="select" id="bt" name="tier"><option value="">Any tier</option>';
    foreach ($tiers as $t) {
        echo '<option' . ($f['tier'] === (string)$t ? ' selected' : '') . '>' . h((string)$t) . '</option>';
    }
    echo '</select><label class="label" for="br">Region</label><select class="select" id="br" name="region"><option value="">Any region</option>';
    foreach ($regions as $rg) {
        echo '<option' . ($f['region'] === (string)$rg ? ' selected' : '') . '>' . h((string)$rg) . '</option>';
    }
    echo '</select><button class="btn" type="submit">Apply</button></form></details>';
    echo '<span class="fcount">' . h(number_format($total)) . ' result' . ($total === 1 ? '' : 's') . ' · sorted by expiry</span></div>';

    if ($isAdmin) {
        echo '<form method="post" id="bulkForm" class="bulkBar" hidden>' . csrf_field() . '<input type="hidden" name="action" value="bulk_update">' . book_filter_hidden();
        echo '<strong data-bulk-count>0 selected</strong>';
        echo '<select name="bulk_status" aria-label="Set renewal status" data-autosubmit><option value="">Set renewal status…</option>';
        foreach (renewal_statuses() as $k => $l) {
            echo '<option value="' . h($k) . '">' . h($l) . '</option>';
        }
        echo '</select><input name="bulk_owner" maxlength="120" placeholder="Assign owner…" aria-label="Owner"><button type="submit" name="op" value="owner">Assign</button><button type="submit" name="op" value="export">Export</button><button class="bulkClear" type="button" data-bulk-clear>Clear</button></form>';
    }
    echo '<input type="hidden" id="profileInlineCsrf" value="' . h(csrf_token()) . '">';
    echo '<div class="card cardFlush"><div class="scrollX"><table class="t2 dense"><thead><tr>' . ($isAdmin ? '<th class="ck"><input type="checkbox" data-bulk-all aria-label="Select every policy on this page"></th>' : '') . '<th>Insured</th><th>' . h(lbl('policy')) . '</th><th>' . h(lbl('policy_type')) . '</th><th class="r">Premium/mo</th><th class="r">LTV</th><th>Risk</th><th>Confidence</th><th>Expires</th></tr></thead><tbody>';
    $period = setting('premium_period', 'monthly');
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $url = tab_url('profiles', ['id' => (string)$id, 'view' => $view !== '' ? $view : null, 'page' => $page > 1 ? (string)$page : null] + $keep);
        $d = $r['d'] !== null ? (int)$r['d'] : null;
        $hot = $d !== null && $d <= 15 && $d >= -30 && !in_array((string)$r['renewal_status'], ['renewed', 'lost'], true);
        $c = (int)($r['confidence_score'] ?? 0);
        $prem = $r['premium_amount'] === null || $r['premium_amount'] === '' ? null : ($period === 'annual' ? (float)$r['premium_amount'] / 12 : (float)$r['premium_amount']);
        echo '<tr class="rowLink' . ($id === $openId ? ' rowOpen' : '') . '" data-href="' . h($url) . '">';
        if ($isAdmin) {
            echo '<td class="ck"><input type="checkbox" name="ids[]" value="' . h((string)$id) . '" form="bulkForm" data-bulk-pick aria-label="Select ' . h((string)$r['policy_number']) . '"></td>';
        }
        echo '<td><a class="ins" href="' . h($url) . '">' . h(mask_name((string)$r['name']) ?: (string)$r['policy_number']) . '</a></td><td class="mono">' . h((string)$r['policy_number']) . '</td><td>' . h((string)($r['line_of_business'] ?: $r['policy_type'] ?: '—')) . '</td>';
        echo '<td class="r">' . h($prem === null ? '—' : money_or_blank($prem)) . '</td><td class="r"><strong data-derived-field="ltv_estimate" data-policy-id="' . h((string)$id) . '">' . h(money_whole($r['ltv_estimate'] ?? null)) . '</strong></td>';
        echo '<td>' . risk_pill((string)($r['risk_tier'] ?? '')) . '</td><td><span class="conf' . ($c < 80 ? ' mid' : '') . '"><span class="trk"><i data-w="' . h((string)$c) . '"></i></span><b>' . h((string)$c) . '</b></span></td>';
        echo '<td class="' . ($hot ? 'due' : '') . '">' . h(date_dmy((string)($r['expiration_date'] ?? ''))) . '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="9" class="empty">No ' . h(strtolower(lbl('clients'))) . ' match these filters.</td></tr>';
    }
    echo '</tbody></table></div>';
    $from = $total ? $offset + 1 : 0;
    echo '<div class="cardFoot"><span>' . h(number_format($from) . '–' . number_format($offset + count($rows)) . ' of ' . number_format($total)) . '</span><span class="pager2">';
    echo '<a class="linkStrong' . ($page <= 1 ? ' off' : '') . '" href="' . h(tab_url('profiles', ['page' => $page > 2 ? (string)($page - 1) : null, 'view' => $view !== '' ? $view : null] + $keep)) . '">← Previous</a>';
    echo '<a class="linkStrong' . ($page >= $pages ? ' off' : '') . '" href="' . h(tab_url('profiles', ['page' => (string)min($pages, $page + 1), 'view' => $view !== '' ? $view : null] + $keep)) . '">Next page →</a></span></div></div>';
    echo '<div class="muted tiny" id="profileInlineStatus">' . h($isAdmin ? 'Select rows to change status, assign an owner or export. Open a policy and choose Edit to change its fields; LTV, tier and confidence rescore on save.' : 'Read-only for your role. Open a policy for details and to log contact.') . '</div>';

    if ($isAdmin) {
        echo '<details class="card"><summary class="h2">Bulk fill missing fields</summary>';
        echo '<form method="post" class="form">' . csrf_field() . '<input type="hidden" name="action" value="repair_apply">';
        echo '<div class="grid2"><div><label class="label">Set region where missing</label><input class="input" name="set_region" maxlength="80" placeholder="Unknown"></div>';
        echo '<div><label class="label">Set ' . h(strtolower(lbl('policy_type'))) . ' where missing</label><input class="input" name="set_policy_type" maxlength="80" placeholder="term"></div></div>';
        echo '<div><button class="btnSecondary" type="submit">Apply repair</button></div></form></details>';
    }
    echo '</div><aside class="bookPanel" aria-label="Policy detail">';
    if ($openId > 0) {
        render_policy_panel($pdo, $openId);
    } else {
        echo '<div class="pSec"><div class="muted">Select a policy to see why it was scored the way it was.</div></div>';
    }
    echo '</aside></div>';
}

function render_policy_panel(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('SELECT * FROM policyholders WHERE id = ?');
    $st->execute([$id]);
    $ph = $st->fetch();
    if (!$ph) {
        echo '<div class="pSec"><div class="muted">That record no longer exists.</div></div>';
        return;
    }
    $isAdmin = current_is_admin();
    $rec = policyholder_record_from_row($ph);
    $meta = safe_json((string)($ph['metadata'] ?? '{}'));
    $meta = is_array($meta) ? $meta : [];
    $imputed = is_array($meta['imputed_fields'] ?? null) ? array_map('strval', (array)$meta['imputed_fields']) : [];
    $hitIds = array_map('intval', is_array($meta['rule_hits'] ?? null) ? (array)$meta['rule_hits'] : []);
    $tierRule = (int)($meta['tier_rule_id'] ?? 0);
    $revealed = pii_revealed($id);
    $d = $rec['days_to_expiry'];
    $tier = $rec['risk_tier'] !== '' ? $rec['risk_tier'] : 'Unknown';
    $editing = $isAdmin && (string)($_GET['pedit'] ?? '') === '1';
    $line = (string)($rec['line_of_business'] !== '' ? $rec['line_of_business'] : $rec['policy_type']);

    // Head
    echo '<div class="pSec" id="panel"><div class="pName"><h2>' . h($revealed ? $rec['name'] : (mask_name($rec['name']) ?: $rec['policy_number'])) . '</h2>';
    if (!$revealed) {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="reveal_pii"><input type="hidden" name="id" value="' . h((string)$id) . '"><button class="revealBtn" type="submit" title="Revealing is recorded in the audit log"><svg viewBox="0 0 12 12" aria-hidden="true"><rect x="2" y="5.2" width="8" height="5.3" rx="1"/><path d="M4 5.2V3.8a2 2 0 014 0v1.4"/></svg>Reveal name</button></form>';
    } else {
        echo '<span class="muted tiny">Revealed · logged</span>';
    }
    echo '</div>';
    $since = $rec['effective_date'] !== '' ? strtotime(substr($rec['effective_date'], 0, 10) . ' UTC') : false;
    echo '<div class="pMeta">' . h(implode(' · ', array_filter([$rec['policy_number'], $line, $rec['region'], $rec['carrier'], $since ? 'effective ' . gmdate('M Y', $since) : '']))) . '</div>';
    $tags = is_array($meta['tags'] ?? null) ? (array)$meta['tags'] : [];
    echo '<div class="pChips"><span data-derived-field="risk_tier" data-policy-id="' . h((string)$id) . '" hidden>' . h($tier) . '</span>' . risk_pill($tier) . implode('', array_map(fn($t) => '<span class="tagChip">' . h((string)$t) . '</span>', $tags)) . status_pill($rec['renewal_status']) . ($rec['opted_out'] === 1 ? '<span class="pill st-lost">Opted out</span>' : '') . '</div></div>';

    // Why this tier
    $rulesById = [];
    foreach (load_rules_all($pdo) as $r) {
        $rulesById[(int)$r['id']] = $r;
    }
    echo '<div class="pSec"><h3><span>Why “' . h($tier) . '” retention risk</span>' . ($isAdmin ? '<a href="' . h(tab_url('rules', $tierRule > 0 ? ['edit' => (string)$tierRule] : [])) . '">Edit rules</a>' : '') . '</h3>';
    $set = $rulesById[$tierRule] ?? null;
    if ($set) {
        $conds = is_array($set['conditions']) ? $set['conditions'] : [];
        $items = isset($conds['conditions']) && is_array($conds['conditions']) ? $conds['conditions'] : $conds;
        echo '<div class="why2"><div class="why2Head">' . h((string)$set['name']) . '<span>priority ' . h((string)$set['priority']) . ' · matched</span></div><ul>';
        $sym = ['>=' => '≥', '<=' => '≤', '!=' => '≠', 'in' => '∈', '==' => '=', 'contains' => 'contains', 'regex' => '~'];
        foreach ((array)$items as $c) {
            if (!is_array($c) || !isset($c['field'])) {
                if (is_array($c)) {
                    echo '<li>(group of conditions)</li>';
                }
                continue;
            }
            $ok = eval_condition_leaf($rec, $c);
            $actual = value_for_rule_field($rec, (string)$c['field']);
            $actualText = $actual === null || $actual === '' ? 'blank' : (is_bool($actual) ? ($actual ? 'true' : 'false') : (string)$actual);
            $v = $c['value'] ?? '';
            $vText = is_array($v) ? implode(', ', array_map('strval', $v)) : (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v);
            $op = strtolower((string)($c['op'] ?? ''));
            echo '<li class="' . ($ok ? '' : 'no') . '">' . h((string)$c['field'] . ' ' . $actualText . ' ' . ($sym[$op] ?? $op) . ' ' . $vText) . '</li>';
        }
        echo '</ul><div class="why2Then">→ ' . h(rule_actions_summary(is_array($set['actions']) ? $set['actions'] : [])) . '</div></div>';
    } else {
        echo '<div class="why2"><div class="why2Head">No rule set a tier</div><div class="pNote">Unknown means none of the enabled rules that set a tier matched. Rules run highest priority first; the first one that sets a tier wins.</div></div>';
    }
    $others = array_values(array_filter($hitIds, fn($x) => $x !== $tierRule));
    if ($others) {
        $names = [];
        $overridden = false;
        foreach ($others as $rid) {
            $r = $rulesById[$rid] ?? null;
            $names[] = $r ? '“' . (string)$r['name'] . '” (priority ' . (int)$r['priority'] . ')' : 'rule #' . $rid . ' (deleted)';
            if ($r && is_array($r['actions']) && (string)($r['actions']['set_risk_tier'] ?? '') !== '' && $set) {
                $overridden = true;
            }
        }
        echo '<p class="pNote">Also matched ' . h(implode(', ', $names)) . ($overridden ? ', overridden by the higher-priority rule' : '') . '.</p>';
    }
    echo '</div>';

    // LTV
    $b = ltv_breakdown($rec);
    echo '<div class="pSec"><h3><span>Lifetime value <span data-derived-field="ltv_estimate" data-policy-id="' . h((string)$id) . '">' . h($b ? money_whole($b['ltv']) : '—') . '</span></span></h3>';
    if ($b) {
        echo '<div class="formula2">' . h(money_or_blank($b['monthly'])) . '/mo <em>×</em> ' . h((string)$b['remaining']) . ' months left <em>×</em> ' . h(number_format($b['multiplier'], 2)) . ' ' . h((string)$b['multiplier_source']) . ' <em>×</em> ' . h(number_format($b['retention'], 2)) . ' retention</div>';
        echo '<p class="pNote">Months left = ' . h((string)$b['term_months']) . '-month relationship − ' . h((string)$b['tenure']) . ' months tenure' . ($b['premium_period'] === 'annual' ? '; annual premium ' . h(money_or_blank($b['premium'])) . ' ÷ 12' : '') . '. Assumptions are set in Settings.</p>';
    } else {
        echo '<p class="pNote">Needs premium, tenure and ' . h(strtolower(lbl('policy_type'))) . '. Fill those in and the value appears.</p>';
    }
    echo '</div>';

    // Confidence
    $cb = confidence_breakdown($rec, $imputed, count($hitIds));
    echo '<div class="pSec"><h3><span>Data confidence <span data-derived-field="confidence_score" data-policy-id="' . h((string)$id) . '">' . h((string)$rec['confidence_score']) . '</span></span>' . ($isAdmin && ($imputed || $cb['total'] < 100) ? '<a href="' . h(self_url(['pedit' => '1', 'id' => (string)$id])) . '#panel">Fix now</a>' : '') . '</h3>';
    $chips = '';
    foreach ($imputed as $fname) {
        $chips .= '<span class="chipWarn">−10 ' . h(str_replace('_', ' ', $fname)) . ' imputed</span>';
    }
    foreach ($cb['lines'] as [$label, $pts]) {
        if ((int)$pts === -5) {
            $chips .= '<span class="chipWarn">−5 ' . h(strtolower((string)$label)) . '</span>';
        } elseif ((int)$pts > 0 && $label !== 'Starting score') {
            $chips .= '<span class="chipOk">+' . h((string)$pts) . ' ' . h(count($hitIds) . ' rule' . (count($hitIds) === 1 ? '' : 's') . ' matched') . '</span>';
        }
    }
    echo $chips !== '' ? $chips : '<span class="chipOk">Every scoring field came from your file</span>';
    echo '</div>';

    // Details
    $owner = $rec['renewal_owner'] !== '' ? $rec['renewal_owner'] : ($rec['producer'] !== '' ? $rec['producer'] . ' (producer)' : '—');
    $tick = static fn(bool $ok): string => $ok ? '✓' : '—';
    $consent = 'Phone ' . $tick((int)$rec['consent_phone'] === 1 && $rec['opted_out'] !== 1) . '  Email ' . $tick((int)$rec['consent_email'] === 1 && $rec['opted_out'] !== 1) . '  SMS ' . $tick((int)$rec['consent_sms'] === 1 && $rec['opted_out'] !== 1);
    $hot = $d !== null && $d <= 15 && !in_array($rec['renewal_status'], ['renewed', 'lost'], true);
    $periodWord = setting('premium_period', 'monthly') === 'annual' ? 'year' : 'month';
    $custom = is_array($meta['custom_fields'] ?? null) ? (array)$meta['custom_fields'] : [];
    echo '<div class="pSec"><h3><span>Details</span>' . ($isAdmin ? '<a href="' . h(self_url(['pedit' => $editing ? null : '1', 'id' => (string)$id])) . '#panel">' . ($editing ? 'Done' : 'Edit') . '</a>' : '') . '</h3><dl class="kv2">';
    if ($editing) {
        $cell = static function (string $field, string $value, string $label) use ($ph): string {
            return '<dt><label for="pc_' . h($field) . '">' . h($label) . '</label></dt><dd><input class="profileCellInput" id="pc_' . h($field) . '" data-policy-cell data-policy-id="' . h((string)(int)$ph['id']) . '" data-field="' . h($field) . '" value="' . h($value) . '"></dd>';
        };
        if ($revealed) {
            echo $cell('name', $rec['name'], 'Display name');
        }
        echo $cell('age', $rec['age'] !== null ? (string)$rec['age'] : '', 'Age');
        echo $cell('policy_type', $rec['policy_type'], lbl('policy_type'));
        echo $cell('region', $rec['region'], 'Region');
        echo $cell('premium_amount', $rec['premium_amount'] !== null ? (string)$rec['premium_amount'] : '', 'Premium / ' . $periodWord);
        echo $cell('tenure_months', $rec['tenure_months'] !== null ? (string)$rec['tenure_months'] : '', 'Tenure (months)');
        echo $cell('sum_assured', $rec['sum_assured'] !== null ? (string)$rec['sum_assured'] : '', lbl('sum_assured'));
        echo $cell('expiration_date', $rec['expiration_date'], 'Expiration date');
        foreach (array_slice($custom, 0, 4, true) as $k => $v) {
            echo $cell('custom.' . (string)$k, is_bool($v) ? ($v ? 'true' : 'false') : (string)$v, 'custom.' . (string)$k);
        }
    } else {
        echo '<dt>Age</dt><dd>' . h($rec['age'] !== null ? (string)$rec['age'] : '—') . '</dd>';
        $prem = $rec['premium_amount'] !== null ? money_or_blank($rec['premium_amount']) . ' / ' . $periodWord : '—';
        if ($rec['prior_premium'] !== null && $rec['premium_amount'] !== null && (float)$rec['prior_premium'] > 0) {
            $pct = ((float)$rec['premium_amount'] / (float)$rec['prior_premium'] - 1) * 100;
            $prem .= ' (' . ($pct >= 0 ? '+' : '−') . number_format(abs($pct), 1) . '%)';
        }
        echo '<dt>Premium</dt><dd>' . h($prem) . '</dd>';
        echo '<dt>Tenure</dt><dd>' . h($rec['tenure_months'] !== null ? $rec['tenure_months'] . ' months' : '—') . '</dd>';
        echo '<dt>' . h(lbl('sum_assured')) . '</dt><dd>' . h($rec['sum_assured'] !== null ? money_whole($rec['sum_assured']) : '—') . '</dd>';
        echo '<dt>Expires</dt><dd class="' . ($hot ? 'due' : '') . '">' . h(date_dmy($rec['expiration_date']) . ($d !== null ? ' · ' . days_words($d) : '')) . '</dd>';
        echo '<dt>Owner</dt><dd>' . h($owner) . '</dd>';
        echo '<dt>Household</dt><dd>' . ($rec['household_id'] !== '' ? '<a class="link" href="' . h(tab_url('profiles', ['q' => $rec['household_id']])) . '">' . h($rec['household_id']) . '</a> · ' . h((string)$rec['policies_in_household']) . ' polic' . ($rec['policies_in_household'] === 1 ? 'y' : 'ies') : '—') . '</dd>';
        echo '<dt>Email</dt><dd>' . h($rec['email'] !== '' ? ($revealed ? $rec['email'] : mask_email($rec['email'])) : '—') . '</dd>';
        echo '<dt>Phone</dt><dd>' . h($rec['phone'] !== '' ? ($revealed ? $rec['phone'] : mask_phone($rec['phone'])) : '—') . '</dd>';
        echo '<dt>Consent</dt><dd>' . h($rec['opted_out'] === 1 ? 'Opted out of all contact' : $consent) . '</dd>';
        foreach (array_slice($custom, 0, 4, true) as $k => $v) {
            echo '<dt>custom.' . h((string)$k) . '</dt><dd>' . h(is_bool($v) ? ($v ? 'true' : 'false') : (string)$v) . '</dd>';
        }
    }
    echo '</dl>' . ($editing ? '<p class="pNote">Changes save when you leave a field or press Enter; LTV, tier and confidence rescore at once.</p>' : '') . '</div>';

    // Actions
    $touch = next_touch($d, $rec['expiration_date']);
    echo '<div class="pActions">';
    echo '<a class="btnDark" href="#d-log" data-open-drawer="d-log">Log a call</a>';
    echo '<a class="btnSecondary" href="#d-status" data-open-drawer="d-status">Set status</a>';
    if ($isAdmin) {
        echo '<a class="btnSecondary" href="' . h(self_url(['pedit' => $editing ? null : '1', 'id' => (string)$id])) . '#panel">' . ($editing ? 'Done' : 'Edit') . '</a>';
    }
    echo '</div>';
    echo '<div class="pDrawer">';
    echo '<details id="d-log"><summary>Log contact · next touch ' . h($touch['label']) . '</summary><div><form method="post" class="form formTight">' . csrf_field() . '<input type="hidden" name="action" value="contact_log"><input type="hidden" name="id" value="' . h((string)$id) . '">';
    echo '<div class="grid2"><select class="select" name="channel" aria-label="Channel"><option value="call">Call</option><option value="email">Email</option><option value="sms">SMS</option><option value="meeting">Meeting</option><option value="mail">Mail</option></select>';
    echo '<select class="select" name="outcome" aria-label="Outcome"><option value="reached">Reached</option><option value="left_message">Left message</option><option value="no_answer">No answer</option><option value="quoted">Quoted</option><option value="renewed">Renewed</option><option value="not_interested">Not interested</option></select></div>';
    echo '<input class="input" name="note" maxlength="500" placeholder="Note (optional)" aria-label="Note"><div><button class="btnDark" type="submit">Log contact</button></div></form></div></details>';
    echo '<details id="d-status"><summary>Renewal status and owner</summary><div><form method="post" class="form formTight">' . csrf_field() . '<input type="hidden" name="action" value="renewal_update"><input type="hidden" name="id" value="' . h((string)$id) . '">';
    echo '<label class="label" for="rs">Status</label><select class="select" id="rs" name="renewal_status">';
    foreach (renewal_statuses() as $k => $l) {
        echo '<option value="' . h($k) . '"' . ($k === $rec['renewal_status'] ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    echo '</select><label class="label" for="ro">Owner</label><input class="input" id="ro" name="renewal_owner" maxlength="120" value="' . h($rec['renewal_owner']) . '" placeholder="Who is working this renewal">';
    echo '<div><button class="btnSecondary" type="submit">Save</button></div></form></div></details>';
    echo '<details><summary>Consent and opt-out</summary><div><form method="post" class="form formTight">' . csrf_field() . '<input type="hidden" name="action" value="consent_update"><input type="hidden" name="id" value="' . h((string)$id) . '"><div class="checkRow">';
    foreach (['consent_phone' => 'Calls', 'consent_sms' => 'SMS', 'consent_email' => 'Email'] as $k => $l) {
        echo '<label class="check"><input type="checkbox" name="' . h($k) . '" value="1"' . ((int)$rec[$k] === 1 ? ' checked' : '') . '> ' . h($l) . '</label>';
    }
    echo '<label class="check"><input type="checkbox" name="opted_out" value="1"' . ($rec['opted_out'] === 1 ? ' checked' : '') . '> Opted out of all</label></div><div><button class="btnSecondary" type="submit">Save consent</button></div></form></div></details>';
    $log = $pdo->prepare('SELECT c.ts, c.channel, c.outcome, c.note, u.email AS user_email FROM contact_log c LEFT JOIN users u ON u.id = c.user_id WHERE c.policyholder_id = ? ORDER BY c.ts DESC, c.id DESC LIMIT 10');
    $log->execute([$id]);
    $entries = $log->fetchAll();
    echo '<details id="d-history"><summary>Contact history (' . h((string)count($entries)) . ')' . ($ph['last_contacted_at'] ? ' · last ' . h(date_dmy((string)$ph['last_contacted_at'])) : '') . '</summary><div>';
    if ($entries) {
        echo '<ul class="timeline2">';
        foreach ($entries as $e) {
            echo '<li><strong>' . h(ucfirst((string)$e['channel'])) . '</strong> · ' . h(str_replace('_', ' ', (string)$e['outcome'])) . ' <span class="muted tiny">' . h(date_dmy((string)$e['ts'])) . ' · ' . h((string)($e['user_email'] ?? 'system')) . '</span>' . ((string)$e['note'] !== '' ? '<div class="muted">' . h((string)$e['note']) . '</div>' : '') . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<div class="muted">No contact logged yet.</div>';
    }
    echo '</div></details></div>';
}

function handle_book_export(PDO $pdo, array $user): void
{
    $f = book_filters();
    book_csv_out($pdo, $user, 'WHERE ' . $f['sql'], $f['args'], 'book');
}

function book_csv_out(PDO $pdo, array $user, string $whereSql, array $args, string $label): void
{
    $st = $pdo->prepare('SELECT * FROM policyholders ' . $whereSql . ' AND ' . sql_not_opted_out() . ' ORDER BY id ASC LIMIT 20000');
    $st->execute($args);
    $rows = $st->fetchAll();
    audit($pdo, (int)$user['id'], 'book.export', ['label' => $label, 'rows' => count($rows)]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="liteinsurance_' . $label . '_' . gmdate('Ymd') . '.csv"');
    $out = fopen('php://output', 'wb');
    $cols = ['policy_number', 'name', 'line_of_business', 'policy_type', 'carrier', 'region', 'premium_amount', 'prior_premium', 'expiration_date', 'renewal_status', 'renewal_owner', 'producer', 'household_id', 'email', 'phone', 'consent_email', 'consent_sms', 'consent_phone', 'ltv_estimate', 'risk_tier', 'confidence_score'];
    fputcsv($out, $cols);
    foreach ($rows as $r) {
        fputcsv($out, array_map(static fn(string $c): string => (string)($r[$c] ?? ''), $cols));
    }
    fclose($out);
}

function handle_bulk_update(PDO $pdo, array $user): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($x) => $x > 0)));
    $op = (string)($_POST['op'] ?? '');
    $status = (string)($_POST['bulk_status'] ?? '');
    if ($op === '' && $status !== '') {
        $op = 'status';
    }
    $back = ['tab' => 'profiles', 'view' => (string)($_POST['view'] ?? '') ?: null, 'q' => (string)($_POST['q'] ?? '') ?: null, 'tier' => (string)($_POST['tier'] ?? '') ?: null, 'region' => (string)($_POST['region'] ?? '') ?: null];
    if (!$ids) {
        flash('error', 'Select at least one policy first.');
        redirect_to($back);
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    if ($op === 'export') {
        book_csv_out($pdo, $user, 'WHERE id IN (' . $ph . ')', $ids, 'selected');
        return;
    }
    if ($op === 'status' && array_key_exists($status, renewal_statuses())) {
        $pdo->prepare('UPDATE policyholders SET renewal_status = ?, updated_at = ? WHERE id IN (' . $ph . ')')->execute(array_merge([$status === 'not_started' ? null : $status, now_iso()], $ids));
        audit($pdo, (int)$user['id'], 'renewals.bulk_status', ['ids' => $ids, 'status' => $status]);
        flash('success', 'Set ' . count($ids) . ' renewal' . (count($ids) === 1 ? '' : 's') . ' to ' . renewal_statuses()[$status] . '.');
        redirect_to($back);
    }
    if ($op === 'owner') {
        $owner = mb_substr(trim((string)($_POST['bulk_owner'] ?? '')), 0, 120);
        $pdo->prepare('UPDATE policyholders SET renewal_owner = ?, updated_at = ? WHERE id IN (' . $ph . ')')->execute(array_merge([$owner !== '' ? $owner : null, now_iso()], $ids));
        audit($pdo, (int)$user['id'], 'renewals.bulk_owner', ['ids' => $ids, 'owner' => $owner]);
        flash('success', ($owner !== '' ? 'Assigned ' . count($ids) . ' to ' . $owner : 'Cleared the owner on ' . count($ids)) . '.');
        redirect_to($back);
    }
    flash('error', 'Pick a status, an owner or Export.');
    redirect_to($back);
}

function handle_contact_log(PDO $pdo, array $user): void
{
    $id = (int)($_POST['id'] ?? 0);
    $channel = (string)($_POST['channel'] ?? '');
    $outcome = (string)($_POST['outcome'] ?? '');
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
    if ($id <= 0 || !in_array($channel, ['call', 'email', 'sms', 'meeting', 'mail'], true) || !in_array($outcome, ['reached', 'left_message', 'no_answer', 'quoted', 'renewed', 'not_interested'], true)) {
        flash('error', 'Pick a channel and outcome.');
        redirect_to([]);
    }
    $st = $pdo->prepare('SELECT renewal_status FROM policyholders WHERE id = ?');
    $st->execute([$id]);
    $cur = $st->fetchColumn();
    if ($cur === false) {
        flash('error', 'Record not found.');
        redirect_to(['id' => null]);
    }
    $now = now_iso();
    $pdo->prepare('INSERT INTO contact_log (policyholder_id, user_id, channel, outcome, note, ts) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, (int)$user['id'], $channel, $outcome, $note, $now]);
    $status = (string)($cur ?? '');
    $next = $status;
    if ($outcome === 'renewed') {
        $next = 'renewed';
    } elseif ($outcome === 'quoted' && in_array($status, ['', 'not_started', 'contacted'], true)) {
        $next = 'quoted';
    } elseif (in_array($status, ['', 'not_started'], true) && in_array($outcome, ['reached', 'left_message', 'not_interested'], true)) {
        $next = 'contacted';
    }
    $pdo->prepare('UPDATE policyholders SET last_contacted_at = ?, renewal_status = ?, updated_at = ? WHERE id = ?')->execute([$now, $next !== '' && $next !== 'not_started' ? $next : null, $now, $id]);
    audit($pdo, (int)$user['id'], 'contact.log', ['id' => $id, 'channel' => $channel, 'outcome' => $outcome]);
    flash('success', 'Contact logged.');
    redirect_to([]);
}

function handle_consent_update(PDO $pdo, array $user): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        redirect_to([]);
    }
    $vals = [];
    foreach (['consent_email', 'consent_sms', 'consent_phone'] as $k) {
        $vals[$k] = (string)($_POST[$k] ?? '') === '1' ? 1 : 0;
    }
    $optOut = (string)($_POST['opted_out'] ?? '') === '1';
    $st = $pdo->prepare('SELECT opt_out_at FROM policyholders WHERE id = ?');
    $st->execute([$id]);
    $prev = $st->fetchColumn();
    if ($prev === false) {
        redirect_to(['id' => null]);
    }
    $optAt = $optOut ? ((string)$prev !== '' ? (string)$prev : now_iso()) : null;
    if ($optOut) {
        $vals = ['consent_email' => 0, 'consent_sms' => 0, 'consent_phone' => 0];
    }
    $pdo->prepare('UPDATE policyholders SET consent_email = ?, consent_sms = ?, consent_phone = ?, opt_out_at = ?, updated_at = ? WHERE id = ?')->execute([$vals['consent_email'], $vals['consent_sms'], $vals['consent_phone'], $optAt, now_iso(), $id]);
    audit($pdo, (int)$user['id'], 'consent.update', ['id' => $id] + $vals + ['opted_out' => $optOut]);
    flash('success', $optOut ? 'Opt-out recorded. This record is excluded from outreach exports.' : 'Consent saved.');
    redirect_to([]);
}

function handle_reveal_pii(PDO $pdo, array $user): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        if (!isset($_SESSION['revealed']) || !is_array($_SESSION['revealed'])) {
            $_SESSION['revealed'] = [];
        }
        $_SESSION['revealed'][$id] = time();
        audit($pdo, (int)$user['id'], 'pii.reveal', ['id' => $id]);
    }
    redirect_to([]);
}

function list_builder_catalog(PDO $pdo): array
{
    $out = [];
    foreach (filter_field_catalog() as $key => [$type, $sql, $label]) {
        if (in_array($key, ['email', 'phone'], true)) {
            continue;
        }
        $out[] = ['k' => $key, 't' => $type, 'l' => $label];
    }
    foreach ($pdo->query('SELECT field_key, label, field_type FROM custom_field_defs WHERE enabled = 1 ORDER BY field_key ASC LIMIT 40')->fetchAll() as $d) {
        $t = (string)($d['field_type'] ?? 'text');
        $out[] = ['k' => 'custom.' . (string)$d['field_key'], 't' => $t === 'number' ? 'num' : ($t === 'bool' ? 'text' : 'text'), 'l' => 'Custom: ' . (string)$d['label']];
    }
    return $out;
}

function list_presets(): array
{
    return [
        'renewing' => ['Renewing in 90 days', 'Open renewals to work this quarter', ['logic' => 'AND', 'rows' => [['f' => 'days_to_expiry', 'op' => 'between', 'v' => '0', 'v2' => '90'], ['f' => 'renewal_status', 'op' => 'in', 'v' => 'not_started, contacted']]]],
        'at_risk' => ['High-risk renewals', 'High or Review tier, renewing within 120 days', ['logic' => 'AND', 'rows' => [['f' => 'days_to_expiry', 'op' => 'between', 'v' => '0', 'v2' => '120'], ['f' => 'risk_tier', 'op' => 'in', 'v' => 'High, Review']]]],
        'single' => ['Monoline households', 'One policy in the household: cross-sell home, auto or life', ['logic' => 'AND', 'rows' => [['f' => 'policies_in_household', 'op' => 'eq', 'v' => '1'], ['f' => 'opted_out', 'op' => 'no', 'v' => '']]]],
        'email_ltv' => ['Email-consented, high LTV', 'Worth a personal check-in before renewal', ['logic' => 'AND', 'rows' => [['f' => 'consent_email', 'op' => 'yes', 'v' => ''], ['f' => 'ltv_estimate', 'op' => 'gte', 'v' => '8000']]]],
        'winback' => ['Win-back: lapsed or cancelled', 'Former clients you can still contact', ['logic' => 'AND', 'rows' => [['f' => 'status', 'op' => 'in', 'v' => 'lapsed, cancelled, non-renewed'], ['f' => 'opted_out', 'op' => 'no', 'v' => '']]]],
        'issues' => ['Data repair queue', 'Imputed, low-confidence or missing an expiration date', ['logic' => 'OR', 'rows' => [['f' => 'confidence_score', 'op' => 'lt', 'v' => '70'], ['f' => 'is_imputed', 'op' => 'yes', 'v' => ''], ['f' => 'expiration_date', 'op' => 'empty', 'v' => '']]]],
    ];
}

function book_view_preset(string $view): ?array
{
    $map = [
        'all' => ['Whole book', 'Every policy', ['logic' => 'AND', 'rows' => []]],
        'renewing' => null,
        'at_risk' => ['High retention risk', 'High or Review tier', ['logic' => 'AND', 'rows' => [['f' => 'risk_tier', 'op' => 'in', 'v' => 'High, Review']]]],
        'low_conf' => ['Low data confidence', 'Confidence below 60', ['logic' => 'AND', 'rows' => [['f' => 'confidence_score', 'op' => 'lt', 'v' => '60']]]],
        'no_expiry' => ['No expiration date', 'Records missing a renewal date', ['logic' => 'AND', 'rows' => [['f' => 'expiration_date', 'op' => 'empty', 'v' => '']]]],
        'imputed' => ['Filled-in values', 'Records with imputed fields', ['logic' => 'AND', 'rows' => [['f' => 'is_imputed', 'op' => 'yes', 'v' => '']]]],
        'unknown_tier' => ['Tier unknown', 'No rule set a tier', ['logic' => 'AND', 'rows' => [['f' => 'risk_tier', 'op' => 'eq', 'v' => 'Unknown']]]],
        'opted_out' => ['Opted out', 'Everyone who opted out of contact', ['logic' => 'AND', 'rows' => [['f' => 'opted_out', 'op' => 'yes', 'v' => '']]]],
    ];
    $presets = list_presets();
    if (isset($presets[$view])) {
        return $presets[$view];
    }
    return $map[$view] ?? null;
}

function clean_consent($v): array
{
    return array_values(array_intersect(['phone', 'sms', 'email'], array_map('strval', (array)$v)));
}

function where_with_consent(string $where, array $consent): string
{
    $map = ['phone' => 'consent_phone = 1', 'sms' => 'consent_sms = 1', 'email' => 'consent_email = 1'];
    $parts = [];
    foreach (clean_consent($consent) as $c) {
        $parts[] = $map[$c];
    }
    if (!$parts) {
        return $where;
    }
    $parts[] = 'opted_out = 0';
    $c = implode(' AND ', $parts);
    return trim($where) === '' ? $c : '(' . $where . ') AND ' . $c;
}

function render_tab_segments(PDO $pdo): void
{
    $editId = (int)($_GET['edit'] ?? 0);
    $dupId = (int)($_GET['dup'] ?? 0);
    $isNew = (string)($_GET['new'] ?? '') === '1';
    $presetKey = (string)($_GET['preset'] ?? '');
    $segments = $pdo->query('SELECT id, name, rule_ids, last_run_at FROM segments ORDER BY id DESC')->fetchAll();
    $rules = $pdo->query('SELECT id, name, priority FROM rules ORDER BY priority DESC, id DESC')->fetchAll();
    $consentRequired = setting('consent_required_for_exports', '1') === '1';
    if ($editId <= 0 && $dupId <= 0 && !$isNew && $presetKey === '' && $segments) {
        $editId = (int)$segments[0]['id'];
    }
    $source = null;
    foreach ($segments as $s) {
        if ((int)$s['id'] === ($dupId > 0 ? $dupId : $editId)) {
            $source = $s;
        }
    }
    $sid = 0;
    $name = '';
    $desc = '';
    $consent = ['phone'];
    $criteria = ['rule_ids' => [], 'where' => '', 'builder' => null];
    if ($source) {
        $c = safe_json((string)$source['rule_ids']);
        $c = is_array($c) ? $c : [];
        $criteria['rule_ids'] = is_array($c['rule_ids'] ?? null) ? array_map('intval', (array)$c['rule_ids']) : [];
        $criteria['where'] = is_string($c['where'] ?? null) ? (string)$c['where'] : '';
        $criteria['builder'] = is_array($c['builder'] ?? null) ? $c['builder'] : null;
        $desc = (string)($c['description'] ?? '');
        $consent = clean_consent($c['consent'] ?? []);
        if ($dupId > 0) {
            $name = 'Copy of ' . (string)$source['name'];
        } else {
            $sid = (int)$source['id'];
            $name = (string)$source['name'];
        }
    } elseif ($presetKey !== '' && ($p = book_view_preset($presetKey))) {
        [$name, $desc, $criteria['builder']] = $p;
    }
    $builderJson = json_encode($criteria['builder'] ?? ($criteria['where'] === '' ? ['logic' => 'AND', 'rows' => [['f' => 'days_to_expiry', 'op' => 'between', 'v' => '0', 'v2' => '90']]] : null), JSON_UNESCAPED_SLASHES);

    echo '<div class="listsGrid">';
    // Saved lists + templates
    echo '<div class="card savedCard"><div class="savedHead"><div class="h2">Saved lists</div><a class="linkStrong" href="' . h(tab_url('segments', ['new' => '1'])) . '">+ New</a></div>';
    foreach ($segments as $s) {
        $c = safe_json((string)($s['rule_ids'] ?? ''));
        $stats = is_array($c['stats'] ?? null) ? (array)$c['stats'] : null;
        if ($stats === null) {
            try {
                [$segRules, $segWhere] = segment_criteria($s);
                $stats = segment_stats($pdo, $segRules, $segWhere);
            } catch (Throwable $e) {
                $stats = null;
            }
        }
        $ran = $s['last_run_at'] ? strtotime((string)$s['last_run_at']) : false;
        $when = $ran ? (gmdate('Y-m-d', $ran) === gmdate('Y-m-d') ? 'Refreshed today' : 'Refreshed ' . gmdate('j M', $ran)) : 'Not refreshed yet';
        echo '<a class="sl' . ((int)$s['id'] === $sid ? ' on' : '') . '" href="' . h(tab_url('segments', ['edit' => (string)$s['id']])) . '"' . ((int)$s['id'] === $sid ? ' aria-current="page"' : '') . '><strong>' . h((string)$s['name']) . '</strong><b>' . h($stats ? number_format((int)($stats['count'] ?? 0)) : '—') . '</b><span>' . h($when) . '</span></a>';
    }
    if (!$segments) {
        echo '<div class="muted pad">No lists yet. Start from a template.</div>';
    }
    echo '<div class="eyebrow tplHead">Templates</div>';
    foreach (list_presets() as [$label, $pdesc, $preset]) {
        echo '<button class="tpl" type="button" data-preset="' . h((string)json_encode($preset, JSON_UNESCAPED_SLASHES)) . '" data-preset-name="' . h($label) . '" data-preset-desc="' . h($pdesc) . '">' . h($label) . '</button>';
    }
    echo '</div>';

    // Builder
    echo '<form method="post" class="builderCol" id="listForm">' . csrf_field();
    if ($sid > 0) {
        echo '<input type="hidden" name="id" value="' . h((string)$sid) . '">';
    }
    echo '<div class="card builderCard">';
    echo '<input class="titleInput" id="listName" name="name" required maxlength="120" placeholder="Name this list" aria-label="List name" value="' . h($name) . '">';
    echo '<input class="descInput" name="description" maxlength="200" placeholder="Who is this list for? (optional)" aria-label="Description" value="' . h($desc) . '">';
    echo '<div class="lb" id="listBuilder" data-catalog="' . h((string)json_encode(list_builder_catalog($pdo), JSON_UNESCAPED_SLASHES)) . '" data-initial="' . h((string)$builderJson) . '">';
    echo '<div class="showRow"><span>Show policies where</span><select class="select" id="lbLogic" aria-label="Match"><option value="AND">all</option><option value="OR">any</option></select><span>of these are true</span></div>';
    echo '<div id="lbRows"></div><div class="lbAddRow"><button class="linkBtn2" type="button" id="lbAdd">+ Add condition</button></div>';
    echo '<input type="hidden" name="builder_json" id="lbJson" value="">';
    echo '</div><div class="divider2"></div>';
    echo '<div class="consentPick"><strong>Leave out contacts without consent for</strong><div class="checkRow">';
    foreach (['phone' => 'Phone calls', 'sms' => 'Text messages', 'email' => 'Email'] as $k => $l) {
        echo '<label class="check"><input type="checkbox" name="consent[]" value="' . h($k) . '"' . (in_array($k, $consent, true) ? ' checked' : '') . ' data-lb-consent> ' . h($l) . '</label>';
    }
    echo '</div><div class="muted tiny">Opted-out records are always left out of exports' . ($consentRequired ? ', and every export is filtered by consent for its channel' : '') . '.</div></div>';
    echo '</div>';
    echo '<div class="codeBox"><div class="codeHead"><span>Advanced · filter text (the conditions above write it; edit it for parentheses or NOT)</span><button type="button" data-copy="listWhere">Copy</button></div>';
    echo '<textarea class="textarea" id="listWhere" name="where" rows="2" maxlength="800" spellcheck="false" aria-label="Filter text">' . h((string)$criteria['where']) . '</textarea>';
    echo '<details' . ($criteria['rule_ids'] ? ' open' : '') . '><summary>Also require scoring rules to match' . ($criteria['rule_ids'] ? ' (' . count($criteria['rule_ids']) . ')' : '') . '</summary><div class="checkGrid">';
    foreach ($rules as $r) {
        $rid = (int)$r['id'];
        echo '<label class="check"><input type="checkbox" name="rule_ids[]" value="' . h((string)$rid) . '"' . (in_array($rid, (array)$criteria['rule_ids'], true) ? ' checked' : '') . '> ' . h((string)$r['name']) . '</label>';
    }
    if (!$rules) {
        echo '<div class="muted">No rules available.</div>';
    }
    echo '</div><div class="muted tiny">Fields: ' . h(implode(', ', array_keys(filter_field_catalog()))) . ', plus custom.field_key. Operators: = != &gt; &lt; &gt;= &lt;= IN LIKE IS NULL, with AND / OR / NOT.</div></details></div>';
    echo '<noscript><div class="actionsRow"><button class="btn" type="submit" name="action" value="segment_save">Save list</button></div></noscript>';
    echo '</form>';

    // Live result + preview
    echo '<div class="stack"><div class="card" id="listResult"><div class="eyebrow">Live result</div>';
    echo '<div class="big"><b data-lr="count">—</b><span data-lr="hh">policies</span></div>';
    echo '<dl class="res"><dt>Average LTV</dt><dd data-lr="ltv">—</dd><dt>Monthly premium</dt><dd data-lr="prem">—</dd><dt>Reachable by phone</dt><dd data-lr="phone">—</dd><dt>Left out: no consent</dt><dd class="warn" data-lr="left">—</dd></dl>';
    echo '<div class="lbCount" id="listCount" aria-live="polite">Counting…</div>';
    if ($sid > 0) {
        $first = $consent[0] ?? 'mail';
        echo '<form method="post" class="resBtns">' . csrf_field() . '<input type="hidden" name="id" value="' . h((string)$sid) . '"><input type="hidden" name="action" value="export_segment_prepare"><input type="hidden" name="channel" value="' . h($first) . '" data-lr-channel><button class="btnDark" type="submit" data-lr="export" title="Consent-filtered for the first channel ticked above; mail if none">Export CSV</button><a class="btnSecondary" href="' . h(tab_url('campaigns', ['seg' => (string)$sid])) . '">Simulate</a></form>';
        echo '<form method="post" class="actionsRow" data-confirm="Delete this list?">' . csrf_field() . '<input type="hidden" name="id" value="' . h((string)$sid) . '"><button class="linkBtn" type="submit" name="action" value="segment_delete">Delete list</button></form>';
    } else {
        echo '<p class="muted tiny">Save the list to export it or simulate a campaign.</p>';
    }
    echo '</div><div class="card cardFlush" id="listPreview"><div class="pvHead"><div class="h2">Preview</div><span class="muted tiny" data-lr="pvcount"></span></div><div data-lr="pv"><div class="pad muted">Counting…</div></div></div></div>';
    echo '</div>';
}

function segment_payload(PDO $pdo, array $ruleIds, string $where, $builder, array $consent = [], string $description = ''): array
{
    $stats = segment_stats($pdo, $ruleIds, where_with_consent($where, $consent));
    $payload = ['rule_ids' => $ruleIds, 'where' => $where, 'mode' => 'rules_and_where', 'consent' => clean_consent($consent), 'description' => mb_substr(trim($description), 0, 200), 'stats' => ['count' => $stats['count'], 'email' => $stats['email'], 'sms' => $stats['sms'], 'phone' => $stats['phone'], 'mail' => $stats['mail']]];
    if (is_array($builder) && isset($builder['rows']) && is_array($builder['rows'])) {
        $payload['builder'] = ['logic' => (string)($builder['logic'] ?? 'AND') === 'OR' ? 'OR' : 'AND', 'rows' => array_slice(array_values(array_filter($builder['rows'], 'is_array')), 0, 20)];
    }
    return [$payload, $stats];
}

function handle_segment_save(PDO $pdo, array $user): void
{
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $ruleIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['rule_ids'] ?? [])), fn($x) => $x > 0)));
    $where = trim((string)($_POST['where'] ?? ''));
    $builder = safe_json((string)($_POST['builder_json'] ?? ''));
    $consent = clean_consent($_POST['consent'] ?? []);
    $description = (string)($_POST['description'] ?? '');
    if ($name === '') {
        flash('error', 'List name is required.');
        redirect_to(['tab' => 'segments', 'edit' => $id > 0 ? (string)$id : null, 'new' => $id > 0 ? null : '1']);
    }
    try {
        validate_filter_expr($where);
        [$payload, $stats] = segment_payload($pdo, $ruleIds, $where, $builder, $consent, $description);
    } catch (Throwable $e) {
        flash('error', 'That filter is not valid: ' . $e->getMessage());
        redirect_to(['tab' => 'segments', 'edit' => $id > 0 ? (string)$id : null]);
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($id > 0) {
        $pdo->prepare('UPDATE segments SET name = ?, rule_ids = ?, last_run_at = ? WHERE id = ?')->execute([$name, $json, now_iso(), $id]);
        audit($pdo, (int)$user['id'], 'segments.update', ['id' => $id, 'name' => $name, 'count' => $stats['count']]);
        flash('success', 'List updated · ' . $stats['count'] . ' policies.');
    } else {
        $pdo->prepare('INSERT INTO segments (name, rule_ids, last_run_at) VALUES (?, ?, ?)')->execute([$name, $json, now_iso()]);
        $id = (int)$pdo->lastInsertId();
        audit($pdo, (int)$user['id'], 'segments.create', ['id' => $id, 'name' => $name, 'count' => $stats['count']]);
        flash('success', 'List created · ' . $stats['count'] . ' policies.');
    }
    redirect_to(['tab' => 'segments', 'edit' => (string)$id, 'new' => null, 'preset' => null, 'dup' => null]);
}

function handle_segment_estimate(PDO $pdo, array $user): void
{
    $id = (int)($_POST['id'] ?? 0);
    $ruleIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['rule_ids'] ?? [])), fn($x) => $x > 0)));
    $where = trim((string)($_POST['where'] ?? ''));
    try {
        validate_filter_expr($where);
        $stats = segment_stats($pdo, $ruleIds, $where);
    } catch (Throwable $e) {
        flash('error', 'That filter is not valid: ' . $e->getMessage());
        redirect_to(['tab' => 'segments']);
    }
    if ($id > 0) {
        $st = $pdo->prepare('SELECT rule_ids FROM segments WHERE id = ?');
        $st->execute([$id]);
        $c = safe_json((string)$st->fetchColumn());
        if (is_array($c) && trim((string)($c['where'] ?? '')) === $where) {
            $c['stats'] = ['count' => $stats['count'], 'email' => $stats['email'], 'sms' => $stats['sms'], 'phone' => $stats['phone'], 'mail' => $stats['mail']];
            $pdo->prepare('UPDATE segments SET rule_ids = ?, last_run_at = ? WHERE id = ?')->execute([json_encode($c, JSON_UNESCAPED_SLASHES), now_iso(), $id]);
        }
    }
    audit($pdo, (int)$user['id'], 'segments.estimate', ['rule_ids' => $ruleIds, 'where' => $where, 'count' => $stats['count']]);
    flash('success', 'Estimated segment size: ' . $stats['count'] . ' · reachable by email ' . $stats['email'] . ', SMS ' . $stats['sms'] . ', call ' . $stats['phone'] . '.');
    redirect_to(['tab' => 'segments']);
}

function handle_segment_count(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    $ruleIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['rule_ids'] ?? [])), fn($x) => $x > 0)));
    $where = trim((string)($_POST['where'] ?? ''));
    $consent = clean_consent($_POST['consent'] ?? []);
    try {
        validate_filter_expr($where);
        $base = segment_stats($pdo, $ruleIds, $where);
        $stats = $consent ? segment_stats($pdo, $ruleIds, where_with_consent($where, $consent)) : $base;
        $live = ['ltv_sum' => 0.0, 'ltv_n' => 0, 'hh' => [], 'preview' => []];
        segment_iterate($pdo, $ruleIds, where_with_consent($where, $consent), static function (array $ph, array $rec) use (&$live): void {
            if ($ph['ltv_estimate'] !== null && $ph['ltv_estimate'] !== '') {
                $live['ltv_sum'] += (float)$ph['ltv_estimate'];
                $live['ltv_n']++;
            }
            $live['hh'][(string)($ph['household_id'] ?: 'solo:' . $ph['id'])] = true;
            if (count($live['preview']) < 5) {
                $live['preview'][] = ['name' => mask_name((string)$ph['name']) ?: (string)$ph['policy_number'], 'policy' => (string)$ph['policy_number'], 'line' => (string)($ph['line_of_business'] ?: $ph['policy_type'] ?: ''), 'ltv' => $ph['ltv_estimate'] !== null ? money_whole($ph['ltv_estimate']) : '—', 'tier' => (string)($ph['risk_tier'] ?: 'Unknown'), 'id' => (int)$ph['id']];
            }
        });
        $premium = (float)$stats['premium'];
        if (setting('premium_period', 'monthly') === 'annual') {
            $premium /= 12;
        }
        echo json_encode(['ok' => true] + $stats + [
            'base_count' => $base['count'],
            'left_out' => max(0, $base['count'] - $stats['count']),
            'avg_ltv' => $live['ltv_n'] ? money_whole($live['ltv_sum'] / $live['ltv_n']) : '—',
            'households' => count($live['hh']),
            'monthly_premium' => money_whole($premium),
            'preview' => $live['preview'],
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function handle_export_segment_prepare(): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        redirect_to(['tab' => 'segments']);
    }
    $channel = (string)($_POST['channel'] ?? 'email');
    if (!in_array($channel, ['email', 'sms', 'phone', 'mail', 'all'], true)) {
        $channel = 'email';
    }
    if ($channel === 'all' && setting('consent_required_for_exports', '1') === '1') {
        flash('error', 'Consent-filtered exports are required. Pick a channel, or change the policy in Settings.');
        redirect_to(['tab' => 'segments']);
    }
    $_SESSION['export_token'] = bin2hex(random_bytes(16));
    $_SESSION['export_channel'] = $channel;
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
    $channel = (string)($_SESSION['export_channel'] ?? 'email');
    unset($_SESSION['export_token']);
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
    $customDefs = $pdo->query('SELECT field_key, label FROM custom_field_defs WHERE enabled = 1 ORDER BY field_key ASC')->fetchAll();
    $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string)$seg['name'])) ?? '', '_');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="segment_' . (int)$seg['id'] . '_' . ($slug !== '' ? $slug . '_' : '') . $channel . '.csv"');
    $out = fopen('php://output', 'wb');
    $headers = ['policy_number', 'name', 'email', 'phone', 'age', 'gender', 'policy_type', 'line_of_business', 'carrier', 'region', 'premium_amount', 'tenure_months', 'sum_assured', 'expiration_date', 'days_to_expiry', 'renewal_status', 'ltv_estimate', 'risk_tier', 'confidence_score', 'consent_email', 'consent_sms', 'consent_phone', 'opted_out'];
    foreach ($customDefs as $def) {
        $headers[] = 'custom.' . (string)$def['field_key'];
    }
    fputcsv($out, $headers);
    $n = 0;
    try {
        segment_iterate($pdo, $ruleIds, $where, static function (array $ph, array $rec) use ($out, $channel, $customDefs, &$n): void {
            if (!row_reachable($ph, $channel)) {
                return;
            }
            $line = [
                $rec['policy_number'], $rec['name'], in_array($channel, ['email', 'all'], true) ? $rec['email'] : '', in_array($channel, ['sms', 'phone', 'all'], true) ? $rec['phone'] : '',
                (string)($rec['age'] ?? ''), $rec['gender'], $rec['policy_type'], $rec['line_of_business'], $rec['carrier'], $rec['region'],
                (string)($rec['premium_amount'] ?? ''), (string)($rec['tenure_months'] ?? ''), (string)($rec['sum_assured'] ?? ''),
                $rec['expiration_date'], (string)($rec['days_to_expiry'] ?? ''), $rec['renewal_status'],
                (string)($rec['ltv_estimate'] ?? ''), $rec['risk_tier'], (string)$rec['confidence_score'],
                (string)$rec['consent_email'], (string)$rec['consent_sms'], (string)$rec['consent_phone'], (string)$rec['opted_out'],
            ];
            $custom = is_array($rec['custom'] ?? null) ? (array)$rec['custom'] : [];
            foreach ($customDefs as $def) {
                $v = $custom[(string)$def['field_key']] ?? '';
                $line[] = is_bool($v) ? ($v ? 'true' : 'false') : (string)$v;
            }
            fputcsv($out, $line);
            $n++;
        });
    } catch (Throwable $e) {
        // invalid stored filter: export header only
    }
    fclose($out);
    $uid = (int)($_SESSION['user_id'] ?? 0);
    audit($pdo, $uid, 'segments.export', ['id' => (int)$seg['id'], 'channel' => $channel, 'rows' => $n]);
}

function campaign_channels(): array
{
    return ['email' => 'Email', 'sms' => 'SMS', 'phone' => 'Phone calls', 'mail' => 'Direct mail'];
}

function campaign_model(int $size, int $reachable, float $rate, float $avg, float $cost, float $lift): array
{
    $conversions = $reachable * $rate * (1 + $lift);
    $gross = $conversions * $avg;
    $estCost = $reachable * $cost;
    $net = $gross - $estCost;
    $breakEven = ($avg * (1 + $lift)) > 0 ? $cost / ($avg * (1 + $lift)) : null;
    return [
        'segment_size' => $size,
        'reachable' => $reachable,
        'contacts' => $reachable,
        'expected_conversions' => $conversions,
        'gross_revenue' => $gross,
        'estimated_cost' => $estCost,
        'net_revenue' => $net,
        'roi' => $estCost > 0 ? $net / $estCost : null,
        'break_even_rate' => $breakEven,
    ];
}

function render_tab_campaigns(PDO $pdo): void
{
    $segments = $pdo->query('SELECT id, name, rule_ids FROM segments ORDER BY id DESC')->fetchAll();
    $campaigns = $pdo->query('SELECT c.id, c.name, c.segment_id, c.assumptions, c.projected_revenue, c.created_at, s.name AS segment_name FROM campaigns c LEFT JOIN segments s ON s.id = c.segment_id ORDER BY c.created_at DESC, c.id DESC LIMIT 6')->fetchAll();
    $viewId = (int)($_GET['view'] ?? 0);
    $shown = null;
    foreach ($campaigns as $c) {
        if ((int)$c['id'] === $viewId) {
            $shown = $c;
        }
    }
    $a = $shown ? safe_json((string)($shown['assumptions'] ?? '{}')) : null;
    $a = is_array($a) ? $a : [];
    $segPick = (int)($_GET['seg'] ?? ($shown ? (int)$shown['segment_id'] : 0));
    $channel = (string)($a['channel'] ?? 'phone');
    if (!array_key_exists($channel, campaign_channels())) {
        $channel = 'phone';
    }
    $rate = isset($a['cross_sell_rate']) ? (float)$a['cross_sell_rate'] * 100 : 5.0;
    $avg = isset($a['avg_offer_value']) ? (float)$a['avg_offer_value'] : 1200.0;
    $cost = isset($a['campaign_cost_per_contact']) ? (float)$a['campaign_cost_per_contact'] : 2.5;
    $lift = isset($a['conversion_lift']) ? (float)$a['conversion_lift'] * 100 : 0.0;
    $total = (int)$pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn();
    $letter = $shown ? (string)$shown['name'] : 'Scenario ' . chr(65 + ($total % 26));
    $cur = currency_symbol();

    echo '<div class="campGrid">';
    echo '<form method="post" class="card campForm" id="campaignForm">' . csrf_field() . '<input type="hidden" name="action" value="campaign_save">';
    echo '<div class="eyebrow eyebrowAcc">' . h($shown ? 'Saved scenario · saving makes a copy' : $letter) . '</div><div class="campTitle" data-camp-title' . ($shown ? ' data-fixed="1"' : '') . '>' . h($shown ? (string)$shown['name'] : 'New scenario') . '</div>';
    echo '<label class="fld">List<select class="select" name="segment_id" required data-camp-list>';
    foreach ($segments as $s) {
        $c = safe_json((string)($s['rule_ids'] ?? ''));
        $stats = is_array($c['stats'] ?? null) ? (array)$c['stats'] : null;
        if ($stats === null) {
            try {
                [$ruleIds, $where] = segment_criteria($s);
                $stats = segment_stats($pdo, $ruleIds, $where);
            } catch (Throwable $e) {
                $stats = ['count' => 0, 'email' => 0, 'sms' => 0, 'phone' => 0, 'mail' => 0];
            }
        }
        echo '<option value="' . h((string)$s['id']) . '"' . ((int)$s['id'] === $segPick ? ' selected' : '') . ' data-name="' . h((string)$s['name']) . '" data-size="' . h((string)(int)($stats['count'] ?? 0)) . '" data-email="' . h((string)(int)($stats['email'] ?? 0)) . '" data-sms="' . h((string)(int)($stats['sms'] ?? 0)) . '" data-phone="' . h((string)(int)($stats['phone'] ?? 0)) . '" data-mail="' . h((string)(int)($stats['mail'] ?? 0)) . '">' . h((string)$s['name'] . ' · ' . number_format((int)($stats['count'] ?? 0))) . '</option>';
    }
    if (!$segments) {
        echo '<option value="0">No lists yet: build one under Lists</option>';
    }
    echo '</select></label>';
    echo '<div class="fld">Channel<div class="segctl" role="radiogroup" aria-label="Channel">';
    foreach (['phone' => 'Phone', 'email' => 'Email', 'sms' => 'SMS', 'mail' => 'Mail'] as $k => $l) {
        echo '<label><input type="radio" name="channel" value="' . h($k) . '"' . ($k === $channel ? ' checked' : '') . '><span>' . h($l) . '</span></label>';
    }
    echo '</div><span class="help" data-camp-help>Reach counts only consented, not opted-out contacts.</span></div>';
    echo '<label class="fld">Cost per contact<span class="unit"><input class="input" name="campaign_cost_per_contact" type="number" step="0.01" min="0" value="' . h(number_format($cost, 2, '.', '')) . '"><span>' . h($cur !== '' ? $cur : 'USD') . '</span></span><span class="help">Staff time plus dialer or send cost</span></label>';
    echo '<label class="fld">Expected conversion rate<span class="unit"><input class="input" name="cross_sell_rate_pct" type="number" step="0.1" min="0" max="100" value="' . h(rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') ?: '0') . '"><span>%</span></span><span class="help">Use your own past results; this is a placeholder</span></label>';
    echo '<label class="fld">Average new annual premium<span class="unit"><input class="input" name="avg_offer_value" type="number" step="1" min="0" value="' . h((string)round($avg)) . '"><span>' . h($cur !== '' ? $cur : 'USD') . '</span></span></label>';
    echo '<label class="fld">Lift from offer or discount<span class="unit"><input class="input" name="conversion_lift_pct" type="number" step="1" min="0" max="500" value="' . h((string)round($lift)) . '"><span>%</span></span></label>';
    echo '<input type="hidden" name="name" value="">';
    echo '<noscript><button class="btn" type="submit">Save scenario</button></noscript></form>';

    echo '<div class="stack"><div class="card"><div class="cardHead"><div class="h2">Projected funnel</div><span class="muted tiny">Updates as you type</span></div>';
    foreach ([['', 'size', 'In list', 'data-camp-listname'], ['reach', 'reach', 'Reachable', 'data-camp-reachsub'], ['sales', 'conv', 'Expected sales', 'data-camp-convsub']] as [$cls, $k, $label, $subAttr]) {
        echo '<div class="fn2' . ($cls !== '' ? ' ' . $cls : '') . '"><div><strong>' . h($label) . '</strong><span ' . $subAttr . '>—</span></div><i data-fn-bar="' . h($k) . '"></i><b data-fn="' . h($k) . '">—</b></div>';
    }
    echo '<div class="tiles"><div class="tile"><div class="l">Gross new premium</div><div class="n" data-fn="gross">—</div></div><div class="tile neg"><div class="l">Contact cost</div><div class="n" data-fn="cost">—</div></div><div class="tile good" data-fn-nettile><div class="l">Net</div><div class="n" data-fn="net">—</div></div><div class="tile"><div class="l">Return on cost</div><div class="n" data-fn="roi">—</div></div></div>';
    echo '<div class="callout"><svg viewBox="0 0 14 14" aria-hidden="true"><circle cx="7" cy="7" r="5.8"/><path d="M7 6.2v3.6M7 4.2v.1"/></svg><span>Break-even at a <b data-fn="be">—</b> conversion rate. Formula: net = reachable × rate × premium × (1 + lift) − reachable × cost.</span></div></div>';

    echo '<div class="card"><div class="cardHead"><div class="h2">Saved scenarios</div><span class="muted tiny">Same list, different assumptions</span></div>';
    if ($campaigns) {
        $best = null;
        $nets = [];
        foreach ($campaigns as $c) {
            $ca = safe_json((string)($c['assumptions'] ?? '{}'));
            $ca = is_array($ca) ? $ca : [];
            $nets[(int)$c['id']] = isset($ca['net_revenue']) ? (float)$ca['net_revenue'] : (float)($c['projected_revenue'] ?? 0);
        }
        arsort($nets);
        $best = count($nets) > 1 ? (int)array_key_first($nets) : null;
        echo '<div class="scns">';
        foreach ($campaigns as $c) {
            $ca = safe_json((string)($c['assumptions'] ?? '{}'));
            $ca = is_array($ca) ? $ca : [];
            $net = $nets[(int)$c['id']];
            $reach = (int)($ca['reachable'] ?? $ca['contacts'] ?? 0);
            $estCost = (float)($ca['estimated_cost'] ?? 0);
            $gross = (float)($ca['gross_revenue'] ?? 0);
            $ratio = $estCost > 0 ? $gross / $estCost : null;
            $on = (int)$c['id'] === $viewId;
            echo '<div class="scn' . ($on ? ' on' : '') . '"><div class="t"><a href="' . h(tab_url('campaigns', ['view' => (string)$c['id']])) . '">' . h((string)$c['name']) . '</a>' . ((int)$c['id'] === $best ? '<span class="best">Best net</span>' : '') . '</div>';
            echo '<div class="d">' . h(number_format($reach) . ' contacts · ' . money_or_blank($ca['campaign_cost_per_contact'] ?? 0) . ' each · ' . money_whole($ca['avg_offer_value'] ?? 0) . ' premium' . ((string)($c['segment_name'] ?? '') !== '' ? ' · ' . (string)$c['segment_name'] : '')) . '</div>';
            echo '<div class="v' . ($net < 0 ? ' neg' : '') . '">' . h(($net < 0 ? '−' : '') . money_whole(abs($net))) . '<small>net' . ($ratio !== null ? ' · ' . h(number_format($ratio, $ratio < 10 ? 1 : 0)) . '× cost' : '') . '</small></div>';
            echo '<form method="post" data-confirm="Delete this scenario?">' . csrf_field() . '<input type="hidden" name="action" value="campaign_delete"><input type="hidden" name="id" value="' . h((string)$c['id']) . '"><button type="submit">Delete</button></form></div>';
        }
        echo '</div>';
    } else {
        echo '<div class="muted">Save a scenario to compare assumptions side by side.</div>';
    }
    echo '</div></div></div>';
}

function handle_campaign_save(PDO $pdo, array $user): void
{
    $name = trim((string)($_POST['name'] ?? ''));
    $segmentId = (int)($_POST['segment_id'] ?? 0);
    $channel = (string)($_POST['channel'] ?? 'email');
    if (!array_key_exists($channel, campaign_channels())) {
        $channel = 'email';
    }
    $rate = isset($_POST['cross_sell_rate_pct']) ? (float)$_POST['cross_sell_rate_pct'] / 100 : (float)($_POST['cross_sell_rate'] ?? 0);
    $lift = isset($_POST['conversion_lift_pct']) ? (float)$_POST['conversion_lift_pct'] / 100 : (float)($_POST['conversion_lift'] ?? 0);
    $rate = max(0.0, min(1.0, $rate));
    $lift = max(0.0, min(5.0, $lift));
    $avg = max(0.0, (float)($_POST['avg_offer_value'] ?? 0));
    $cost = max(0.0, (float)($_POST['campaign_cost_per_contact'] ?? 0));
    if ($segmentId <= 0) {
        flash('error', 'Pick a list first. Build one under Lists.');
        redirect_to(['tab' => 'campaigns']);
    }
    $st = $pdo->prepare('SELECT * FROM segments WHERE id = ?');
    $st->execute([$segmentId]);
    $seg = $st->fetch();
    if (!$seg) {
        flash('error', 'List not found.');
        redirect_to(['tab' => 'campaigns']);
    }
    if ($name === '') {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn();
        $labels = ['phone' => 'Phone', 'email' => 'Email', 'sms' => 'SMS', 'mail' => 'Mail'];
        $name = chr(65 + ($n % 26)) . ' · ' . ($labels[$channel] ?? $channel) . ', ' . rtrim(rtrim(number_format($rate * 100, 1, '.', ''), '0'), '.') . '%';
    }
    [$ruleIds, $where] = segment_criteria($seg);
    try {
        $stats = segment_stats($pdo, $ruleIds, $where);
    } catch (Throwable $e) {
        flash('error', 'That list has an invalid filter. Edit it under Lists.');
        redirect_to(['tab' => 'campaigns']);
    }
    $model = campaign_model((int)$stats['count'], (int)$stats[$channel], $rate, $avg, $cost, $lift);
    $assumptions = ['channel' => $channel, 'cross_sell_rate' => $rate, 'avg_offer_value' => $avg, 'campaign_cost_per_contact' => $cost, 'conversion_lift' => $lift] + $model;
    $ins = $pdo->prepare('INSERT INTO campaigns (name, segment_id, assumptions, projected_revenue, created_at) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([mb_substr($name, 0, 120), $segmentId, json_encode($assumptions, JSON_UNESCAPED_SLASHES), $model['gross_revenue'], now_iso()]);
    $newId = (int)$pdo->lastInsertId();
    audit($pdo, (int)$user['id'], 'campaigns.simulate', ['id' => $newId, 'segment_id' => $segmentId, 'channel' => $channel, 'projected_revenue' => $model['gross_revenue'], 'net_revenue' => $model['net_revenue']]);
    flash('success', 'Saved “' . $name . '”. Net: ' . currency_symbol() . number_format($model['net_revenue'], 2) . ' from ' . $model['reachable'] . ' reachable contacts.');
    redirect_to(['tab' => 'campaigns', 'view' => (string)$newId, 'seg' => null]);
}

function handle_campaign_delete(PDO $pdo, array $user): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('DELETE FROM campaigns WHERE id = ?')->execute([$id]);
        audit($pdo, (int)$user['id'], 'campaigns.delete', ['id' => $id]);
        flash('success', 'Scenario deleted.');
    }
    redirect_to(['tab' => 'campaigns', 'view' => null]);
}

function render_tab_settings(PDO $pdo): void
{
    $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $rules = (int)$pdo->query('SELECT COUNT(*) FROM rules')->fetchColumn();
    $segs = (int)$pdo->query('SELECT COUNT(*) FROM segments')->fetchColumn();
    $pol = (int)$pdo->query('SELECT COUNT(*) FROM policyholders')->fetchColumn();
    $a = ltv_assumptions();

    echo '<div class="grid2">';
    echo '<div class="card"><div class="h2">Workspace</div>';
    echo '<form method="post" class="form">' . csrf_field() . '<input type="hidden" name="action" value="settings_save"><input type="hidden" name="section" value="workspace">';
    echo '<label class="label" for="sAgency">Agency name</label><input class="input" id="sAgency" name="agency_name" maxlength="80" placeholder="Shown at the top of the sidebar" value="' . h(setting('agency_name', '')) . '">';
    echo '<div class="grid2"><div><label class="label" for="sCur">Currency symbol</label><input class="input" id="sCur" name="currency_symbol" maxlength="4" value="' . h(currency_symbol()) . '"></div>';
    echo '<div><label class="label" for="sPer">Premium amounts are</label><select class="select" id="sPer" name="premium_period"><option value="monthly"' . ($a['premium_period'] === 'monthly' ? ' selected' : '') . '>Monthly</option><option value="annual"' . ($a['premium_period'] === 'annual' ? ' selected' : '') . '>Annual</option></select></div></div>';
    echo '<label class="label" for="sTerm">Terminology</label><select class="select" id="sTerm" name="terminology">';
    foreach (terminology_packs() as $k => $p) {
        echo '<option value="' . h($k) . '"' . (setting('terminology', 'agency') === $k ? ' selected' : '') . '>' . h($p['label'] . ' — ' . $p['clients'] . ', ' . $p['policy_type'] . ', ' . $p['sum_assured']) . '</option>';
    }
    echo '</select><div><button class="btn" type="submit">Save workspace</button></div></form></div>';

    echo '<div class="card"><div class="h2">Lifetime value assumptions</div><div class="muted">LTV = monthly premium × (relationship months − tenure) × cross-sell multiplier × retention.</div>';
    echo '<form method="post" class="form">' . csrf_field() . '<input type="hidden" name="action" value="settings_save"><input type="hidden" name="section" value="ltv">';
    echo '<div class="grid3"><div><label class="label" for="sMonths">Relationship (months)</label><input class="input" id="sMonths" name="ltv_term_months" type="number" min="1" max="600" value="' . h((string)$a['term_months']) . '"></div>';
    echo '<div><label class="label" for="sRet">Retention</label><input class="input" id="sRet" name="ltv_retention" type="number" step="0.01" min="0" max="1" value="' . h((string)$a['retention']) . '"></div>';
    echo '<div><label class="label" for="sMult">Default multiplier</label><input class="input" id="sMult" name="ltv_cross_sell_default" type="number" step="0.01" min="0" max="5" value="' . h((string)$a['default_multiplier']) . '"></div></div>';
    $mults = [];
    foreach (CROSS_SELL_MULTIPLIER_BY_POLICY_TYPE as $t => $m) {
        $mults[] = $t . ' ' . number_format((float)$m, 2);
    }
    echo '<div class="muted tiny">Per-type multipliers (edit in the file header): ' . h(implode(' · ', $mults)) . '.</div>';
    echo '<label class="check"><input type="checkbox" name="recompute" value="1" checked> Recompute every policy after saving</label>';
    echo '<div><button class="btn" type="submit">Save assumptions</button></div></form></div>';
    echo '</div>';

    echo '<div class="grid2">';
    echo '<div class="card"><div class="h2">Consent &amp; outreach</div>';
    echo '<form method="post" class="form">' . csrf_field() . '<input type="hidden" name="action" value="settings_save"><input type="hidden" name="section" value="consent">';
    echo '<label class="check"><input type="checkbox" name="consent_required_for_exports" value="1"' . (setting('consent_required_for_exports', '1') === '1' ? ' checked' : '') . '> List exports must be filtered by channel consent (opt-outs are always excluded)</label>';
    echo '<label class="label" for="sCad">Renewal touchpoints (days before expiry)</label><input class="input" id="sCad" name="renewal_cadence" maxlength="60" value="' . h(implode(',', renewal_cadence())) . '">';
    echo '<div class="muted tiny">Used for “next touch” on Today and Renewals. Default 120, 90, 75, 45, 30, 15. Not legal advice — confirm consent rules for your market (e.g. TCPA in the US) with counsel.</div>';
    echo '<div><button class="btn" type="submit">Save consent policy</button></div></form>';
    $opted = (int)$pdo->query("SELECT COUNT(*) FROM policyholders WHERE opt_out_at IS NOT NULL AND opt_out_at <> ''")->fetchColumn();
    echo '<div class="divider"></div><div class="h3">Suppression list</div><div class="muted">Paste emails, phone numbers or policy numbers — one per line. Matches are opted out of all channels. Currently opted out: ' . h(number_format($opted)) . '.</div>';
    echo '<form method="post" class="form" data-confirm="Opt out every matching record?">' . csrf_field() . '<input type="hidden" name="action" value="suppression_apply"><textarea class="textarea" name="suppression" rows="5" maxlength="200000" placeholder="jane@example.com&#10;+1 555 010 0142&#10;P-3017" aria-label="Suppression list"></textarea><div><button class="btnSecondary" type="submit">Apply opt-outs</button></div></form>';
    echo '</div>';

    echo '<div class="card"><div class="h2">Data inventory</div><div class="muted">What personal data this install holds, for privacy requests and audits.</div>';
    $inv = $pdo->query("SELECT SUM(name IS NOT NULL AND name <> '' AND name <> policy_number) AS names, SUM(dob IS NOT NULL AND dob <> '') AS dobs, SUM(gender IS NOT NULL AND gender <> '') AS genders, SUM(email IS NOT NULL AND email <> '') AS emails, SUM(phone IS NOT NULL AND phone <> '') AS phones FROM policyholders")->fetch() ?: [];
    $customN = (int)$pdo->query('SELECT COUNT(*) FROM custom_field_defs WHERE enabled = 1')->fetchColumn();
    $contactN = (int)$pdo->query('SELECT COUNT(*) FROM contact_log')->fetchColumn();
    $auditN = (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    $dbSize = is_file(db_path()) ? (int)filesize(db_path()) : 0;
    $staged = 0;
    if (is_dir(upload_dir())) {
        $staged = count(glob(upload_dir() . DIRECTORY_SEPARATOR . '*.csv') ?: []);
    }
    echo '<table class="table compact"><tbody>';
    foreach ([['Names', $inv['names'] ?? 0, 'Identify the ' . strtolower(lbl('client'))], ['Dates of birth', $inv['dobs'] ?? 0, 'Age for scoring'], ['Gender', $inv['genders'] ?? 0, 'Optional scoring input'], ['Email addresses', $inv['emails'] ?? 0, 'Consent-filtered outreach'], ['Phone numbers', $inv['phones'] ?? 0, 'Consent-filtered outreach'], ['Custom CSV fields', $customN, 'Preserved from imports'], ['Contact log entries', $contactN, 'Renewal history'], ['Audit events', $auditN, 'Who did what, when']] as [$l, $n, $why]) {
        echo '<tr><td>' . h($l) . '</td><td class="right">' . h(number_format((int)$n)) . '</td><td class="muted">' . h($why) . '</td></tr>';
    }
    echo '<tr><td>Database file</td><td class="right">' . h(number_format($dbSize / 1024, 0)) . ' KB</td><td class="muted"><code>' . h(basename(db_path())) . '</code></td></tr>';
    echo '<tr><td>Staged CSV uploads</td><td class="right">' . h((string)$staged) . '</td><td class="muted">Delete old files from <code>' . h(basename(upload_dir())) . '/</code> after importing</td></tr>';
    echo '</tbody></table></div>';
    echo '</div>';

    echo '<div class="grid2">';
    render_users_card($pdo, (array)($GLOBALS['LI_USER'] ?? []));
    render_retention_card($pdo);
    echo '</div>';

    echo '<div class="grid2">';
    echo '<div class="card"><div class="cardHead"><div class="h2">Audit log</div><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="audit_export"><button class="btnSecondary btnSm" type="submit">Export CSV</button></form></div>';
    $audit = $pdo->query('SELECT a.ts, a.action, u.email AS user_email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.ts DESC, a.id DESC LIMIT 12')->fetchAll();
    echo '<table class="table compact"><thead><tr><th>Time</th><th>Action</th><th>User</th></tr></thead><tbody>';
    foreach ($audit as $r) {
        echo '<tr><td class="muted">' . h((string)$r['ts']) . '</td><td class="mono">' . h((string)$r['action']) . '</td><td>' . h((string)($r['user_email'] ?? 'system')) . '</td></tr>';
    }
    if (!$audit) {
        echo '<tr><td colspan="3" class="muted">No events yet.</td></tr>';
    }
    echo '</tbody></table></div>';

    echo '<div class="card"><div class="h2">App config (read-only)</div>';
    echo '<table class="table compact"><tbody>';
    echo '<tr><td>DB path</td><td class="muted"><code>' . h(db_path()) . '</code></td></tr>';
    echo '<tr><td>Upload dir</td><td class="muted"><code>' . h(upload_dir()) . '</code></td></tr>';
    echo '<tr><td>Session timeout</td><td class="muted">' . h((string)SESSION_TIMEOUT_SECONDS) . ' seconds</td></tr>';
    echo '<tr><td>Accent</td><td class="muted"><span class="swatch"></span> ' . h(ACCENT_COLOR) . '</td></tr>';
    echo '<tr><td>PHP</td><td class="muted">' . h(PHP_VERSION) . '</td></tr>';
    echo '</tbody></table>';
    echo '<div class="kpiGrid">' . kpi('Users', (string)$users) . kpi('Policyholders', (string)$pol) . kpi('Rules', (string)$rules) . kpi('Segments', (string)$segs) . '</div>';
    echo '<form method="post" class="inline">' . csrf_field() . '<button class="btnSecondary" type="submit" name="action" value="recompute_now">Recompute LTV + risk tiers</button></form>';
    echo '<div class="muted tiny">Use this after changing rules. Cron is recommended for scheduled runs (it also keeps “days to expiry” rules current).</div>';
    echo '<div class="divider"></div><div class="h3">Cron</div><div class="muted">Recommended cPanel cron (daily):</div>';
    echo '<pre class="codeBlock">php /home/USER/public_html/liteinsurance.php action=cron_jobs cron_token=YOUR_TOKEN</pre>';
    echo '<div class="muted tiny">If <code>CRON_TOKEN</code> is blank, cron runs only via CLI.</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="card"><div class="h2">Setup doctor</div><div class="muted">Checks the self-hosted PHP workflow: PHP, PDO SQLite, DB path, upload path, upload limits, .env, CRON_TOKEN, and cron readiness.</div>';
    echo render_setup_doctor_table(setup_doctor_report());
    echo '</div>';
}

function handle_settings_save(PDO $pdo, array $user): void
{
    $section = (string)($_POST['section'] ?? '');
    $changed = [];
    if ($section === 'workspace') {
        $agency = trim((string)($_POST['agency_name'] ?? ''));
        if (mb_strlen($agency) <= 80) {
            setting_set($pdo, 'agency_name', $agency);
            $changed[] = 'agency_name';
        }
        $cur = trim((string)($_POST['currency_symbol'] ?? ''));
        if (mb_strlen($cur) <= 4) {
            setting_set($pdo, 'currency_symbol', $cur);
            $changed[] = 'currency_symbol';
        }
        $term = (string)($_POST['terminology'] ?? '');
        if (isset(terminology_packs()[$term])) {
            setting_set($pdo, 'terminology', $term);
            $changed[] = 'terminology';
        }
        $per = (string)($_POST['premium_period'] ?? '');
        if (in_array($per, ['monthly', 'annual'], true)) {
            setting_set($pdo, 'premium_period', $per);
            $changed[] = 'premium_period';
        }
    } elseif ($section === 'ltv') {
        $months = (int)($_POST['ltv_term_months'] ?? 0);
        $ret = (float)($_POST['ltv_retention'] ?? -1);
        $mult = (float)($_POST['ltv_cross_sell_default'] ?? -1);
        if ($months < 1 || $months > 600 || $ret < 0 || $ret > 1 || $mult < 0 || $mult > 5) {
            flash('error', 'Months 1–600, retention 0–1, multiplier 0–5.');
            redirect_to(['tab' => 'settings']);
        }
        setting_set($pdo, 'ltv_term_months', (string)$months);
        setting_set($pdo, 'ltv_retention', (string)$ret);
        setting_set($pdo, 'ltv_cross_sell_default', (string)$mult);
        $changed = ['ltv_term_months', 'ltv_retention', 'ltv_cross_sell_default'];
    } elseif ($section === 'access') {
        setting_set($pdo, 'allow_registration', (string)($_POST['allow_registration'] ?? '') === '1' ? '1' : '0');
        setting_set($pdo, 'require_totp', (string)($_POST['require_totp'] ?? '') === '1' ? '1' : '0');
        $changed = ['allow_registration', 'require_totp'];
    } elseif ($section === 'retention') {
        foreach (['retention_uploads_days', 'retention_audit_days', 'retention_contact_days', 'retention_inactive_policy_days'] as $k) {
            setting_set($pdo, $k, (string)max(0, min(3650, (int)($_POST[$k] ?? 0))));
            $changed[] = $k;
        }
    } elseif ($section === 'consent') {
        setting_set($pdo, 'consent_required_for_exports', (string)($_POST['consent_required_for_exports'] ?? '') === '1' ? '1' : '0');
        $cad = [];
        foreach (explode(',', (string)($_POST['renewal_cadence'] ?? '')) as $p) {
            $n = (int)trim($p);
            if ($n > 0 && $n <= 365) {
                $cad[$n] = $n;
            }
        }
        rsort($cad);
        if ($cad) {
            setting_set($pdo, 'renewal_cadence', implode(',', array_slice($cad, 0, 8)));
        }
        $changed = ['consent_required_for_exports', 'renewal_cadence'];
    }
    audit($pdo, (int)$user['id'], 'settings.save', ['section' => $section, 'keys' => $changed]);
    if ($section === 'ltv' && (string)($_POST['recompute'] ?? '') === '1') {
        $n = recompute_all_policyholders($pdo);
        flash('success', 'Assumptions saved. Recomputed ' . $n . ' policies.');
    } else {
        flash('success', 'Settings saved.');
    }
    redirect_to(['tab' => 'settings']);
}

function handle_suppression_apply(PDO $pdo, array $user): void
{
    $lines = preg_split('/[\r\n,;]+/', (string)($_POST['suppression'] ?? '')) ?: [];
    $emails = [];
    $phones = [];
    $policies = [];
    foreach ($lines as $line) {
        $v = trim($line);
        if ($v === '') {
            continue;
        }
        if (str_contains($v, '@')) {
            $e = normalize_email($v);
            if ($e !== '') {
                $emails[$e] = true;
            }
            continue;
        }
        $digits = phone_key($v);
        if (strlen($digits) >= 7 && preg_match('/^[\d\s()+.\-]+$/', $v)) {
            $phones[$digits] = true;
        }
        $policies[$v] = true;
    }
    $now = now_iso();
    $count = 0;
    $upd = $pdo->prepare("UPDATE policyholders SET opt_out_at = COALESCE(NULLIF(opt_out_at, ''), ?), consent_email = 0, consent_sms = 0, consent_phone = 0, updated_at = ? WHERE id = ?");
    $pdo->beginTransaction();
    $offset = 0;
    while (true) {
        $st = $pdo->prepare('SELECT id, policy_number, email, phone FROM policyholders WHERE id > ? ORDER BY id ASC LIMIT 1000');
        $st->execute([$offset]);
        $rows = $st->fetchAll();
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $offset = (int)$r['id'];
            $hit = isset($policies[(string)$r['policy_number']])
                || ((string)$r['email'] !== '' && isset($emails[strtolower((string)$r['email'])]))
                || ((string)$r['phone'] !== '' && isset($phones[phone_key((string)$r['phone'])]));
            if ($hit) {
                $upd->execute([$now, $now, (int)$r['id']]);
                $count++;
            }
        }
    }
    $pdo->commit();
    audit($pdo, (int)$user['id'], 'consent.suppression', ['entries' => count($emails) + count($phones) + count($policies), 'matched' => $count]);
    flash('success', 'Suppression applied: ' . $count . ' record(s) opted out from ' . (count($emails) + count($phones) + count($policies)) . ' entries.');
    redirect_to(['tab' => 'settings']);
}

function handle_audit_export(PDO $pdo, array $user): void
{
    audit($pdo, (int)$user['id'], 'audit.export', []);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="liteinsurance_audit_' . gmdate('Ymd') . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['id', 'ts', 'user', 'action', 'payload']);
    $offset = 0;
    while (true) {
        $st = $pdo->prepare('SELECT a.id, a.ts, a.action, a.payload, u.email AS user_email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.id > ? ORDER BY a.id ASC LIMIT 1000');
        $st->execute([$offset]);
        $rows = $st->fetchAll();
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $offset = (int)$r['id'];
            fputcsv($out, [(string)$r['id'], (string)$r['ts'], (string)($r['user_email'] ?? 'system'), (string)$r['action'], (string)$r['payload']]);
        }
    }
    fclose($out);
}

function handle_checklist_dismiss(PDO $pdo, array $user): void
{
    setting_set($pdo, 'checklist_dismissed', '1');
    audit($pdo, (int)$user['id'], 'settings.checklist_dismiss', []);
    redirect_to(['tab' => 'dashboard']);
}

function recompute_all_policyholders(PDO $pdo): int
{
    $rules = load_rules($pdo);
    $updated = 0;
    $offsetId = 0;
    $upd = $pdo->prepare('UPDATE policyholders SET ltv_estimate = ?, risk_tier = ?, confidence_score = ?, metadata = ? WHERE id = ?');
    $pdo->beginTransaction();
    while (true) {
        $st = $pdo->prepare('SELECT * FROM policyholders WHERE id > ? ORDER BY id ASC LIMIT 300');
        $st->execute([$offsetId]);
        $rows = $st->fetchAll();
        if (!$rows) {
            break;
        }
        foreach ($rows as $ph) {
            $offsetId = (int)$ph['id'];
            $metadata = safe_json((string)($ph['metadata'] ?? '{}'));
            $metadata = is_array($metadata) ? $metadata : [];
            $imputed = is_array($metadata['imputed_fields'] ?? null) ? (array)$metadata['imputed_fields'] : [];
            $s = score_policy_record(policyholder_record_from_row($ph), $imputed, $rules);
            $newMeta = ['imputed_fields' => $imputed, 'rule_hits' => $s['rule_hits'], 'tags' => $s['tags'], 'tier_rule_id' => $s['tier_rule_id']];
            if (isset($metadata['custom_fields']) && is_array($metadata['custom_fields'])) {
                $newMeta['custom_fields'] = $metadata['custom_fields'];
            }
            $upd->execute([$s['ltv'], $s['risk_tier'], $s['confidence'], json_encode($newMeta, JSON_UNESCAPED_SLASHES), (int)$ph['id']]);
            $updated++;
            if (($updated % 300) === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }
    }
    $pdo->commit();
    return $updated;
}

function render_import_mapping(PDO $pdo, array $stage): void
{
    $rawHeaders = array_map(fn($x) => trim(strip_bom((string)$x)), (array)$stage['headers']);
    $headers = array_values(array_filter($rawHeaders, fn($x) => $x !== ''));
    $saved = is_array($stage['mapping'] ?? null) ? (array)$stage['mapping'] : null;
    $mapping = $saved ?? guess_import_mapping($headers);
    $fields = import_field_labels();
    $byHeader = [];
    foreach ($mapping as $field => $hname) {
        $field = (string)$field;
        $hname = (string)$hname;
        if ($hname === '' || $hname === '__generate__') {
            continue;
        }
        if (str_starts_with($field, 'ignore:')) {
            $byHeader[$hname] = '__ignore__';
        } elseif (isset($fields[$field]) && !isset($byHeader[$hname])) {
            $byHeader[$hname] = $field;
        }
    }
    $scan = stage_scan($stage);
    $ams = recognised_ams_columns($headers);
    $matched = count(array_filter($byHeader, fn($f) => $f !== '__ignore__'));
    $ignored = count(array_filter($byHeader, fn($f) => $f === '__ignore__'));
    $custom = count($headers) - $matched - $ignored;
    $sensitive = ['display_name', 'dob', 'email', 'phone'];
    $used = [];

    echo '<div class="importGrid"><form method="post" class="card cardFlush" id="mapForm">' . csrf_field() . '<input type="hidden" name="action" value="import_preview">';
    echo '<div class="cardHeadPad"><div><div class="h2">Map columns</div><div class="muted">' . h($matched . ' matched automatically · ' . $custom . ' kept as custom field' . ($custom === 1 ? '' : 's') . ' · ' . $ignored . ' ignored') . '</div></div>';
    if ($ams >= 3) {
        echo '<span class="preset">Agency-system export <small>' . h((string)$ams) . ' known columns detected</small></span>';
    } elseif ($saved) {
        echo '<span class="preset">Your mapping <small>edited</small></span>';
    }
    echo '</div><div class="scrollX"><table class="t2 mapTable"><thead><tr><th>CSV column</th><th>Sample</th><th>LiteInsurance field</th><th>Result</th></tr></thead><tbody>';
    foreach ($rawHeaders as $i => $hname) {
        if ($hname === '') {
            continue;
        }
        $cur = $byHeader[$hname] ?? '';
        $key = custom_field_key($hname, $used);
        $sample = (string)($scan['samples'][$hname] ?? '');
        $shown = $sample === '' ? '—' : mask_sample($cur !== '' && $cur !== '__ignore__' ? $cur : '', $sample);
        if ($cur === '' && preg_match('/name|email|phone|birth|dob/i', $hname)) {
            $shown = $sample === '' ? '—' : mask_name($sample);
        }
        echo '<tr><td>' . h($hname) . '</td><td class="mono">' . h($shown) . '</td><td><select class="select" name="cmap[' . h((string)$i) . ']" data-col="' . h($hname) . '">';
        echo '<option value=""' . ($cur === '' ? ' selected' : '') . '>Custom field: custom.' . h($key) . '</option>';
        echo '<option value="__ignore__"' . ($cur === '__ignore__' ? ' selected' : '') . '>Ignore this column</option>';
        foreach ($fields as $fk => $fl) {
            echo '<option value="' . h($fk) . '"' . ($cur === $fk ? ' selected' : '') . '>' . h($fl) . '</option>';
        }
        echo '</select></td><td>';
        if ($cur === '__ignore__') {
            echo '<span class="resPill ign">Ignored · not imported</span>';
        } elseif ($cur === '') {
            echo '<span class="resPill custom">Kept as custom</span>';
        } elseif (in_array($cur, $sensitive, true)) {
            echo '<span class="resPill sens">Matched · sensitive</span>';
        } else {
            echo '<span class="resPill">Matched</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    $period = setting('premium_period', 'monthly');
    echo '<div class="mapOpts"><div><label class="label" for="premium_period">Premium amounts are</label><select class="select" id="premium_period" name="premium_period"><option value="monthly"' . ($period === 'monthly' ? ' selected' : '') . '>Monthly</option><option value="annual"' . ($period === 'annual' ? ' selected' : '') . '>Annual (divided by 12 for LTV)</option></select></div>';
    echo '<div><label class="label" for="defRegion">Region when blank</label><input class="input" id="defRegion" name="defaults[region]" maxlength="80" value="' . h((string)($stage['defaults']['region'] ?? 'Unknown')) . '"></div>';
    echo '<div><label class="label" for="defType">' . h(lbl('policy_type')) . ' when blank</label><input class="input" id="defType" name="defaults[policy_type]" maxlength="80" value="' . h((string)($stage['defaults']['policy_type'] ?? 'term')) . '"></div></div>';
    echo '</form>';

    // What will happen (quick scan with the current mapping)
    $recHdr = (string)array_search('record_id', $byHeader, true);
    $expHdr = (string)array_search('expiration_date', $byHeader, true);
    $effHdr = (string)array_search('effective_date', $byHeader, true);
    $willUpdate = $recHdr !== '' ? import_existing_count($pdo, (string)$stage['path'], ['record_id' => $recHdr]) : 0;
    $rows = (int)$scan['rows'];
    $noExp = $expHdr !== '' ? $rows - (int)($scan['filled'][$expHdr] ?? 0) : $rows;
    $noEff = $effHdr !== '' ? $rows - (int)($scan['filled'][$effHdr] ?? 0) : $rows;
    $consentMapped = count(array_intersect(['consent_email', 'consent_sms', 'consent_phone'], array_values($byHeader))) > 0;
    echo '<div class="stack"><div class="card"><div class="eyebrow">What will happen</div><div class="happen">';
    echo '<div class="item"><span class="ic">↻</span><div><b>' . h(number_format($willUpdate)) . '</b><span>policies updated in place (matched on ' . h($recHdr !== '' ? $recHdr : 'Record ID') . ')</span></div></div>';
    echo '<div class="item"><span class="ic ok">+</span><div><b>' . h(number_format(max(0, $rows - $willUpdate))) . '</b><span>new policies added' . ($recHdr === '' ? ' · Record IDs generated from row numbers' : '') . '</span></div></div>';
    echo '<div class="item"><span class="ic warn">!</span><div><b>' . h(number_format($noExp)) . '</b><span>missing an expiration date · ' . h($effHdr !== '' && $noEff < $rows ? 'filled from the effective date + term, then flagged' : 'sent to the Data health queue') . '</span></div></div>';
    echo '<div class="item"><span class="ic ok">✓</span><div><b>' . h(number_format(count($headers))) . '</b><span>columns read · nothing is written until you confirm on the next step</span></div></div>';
    echo '</div></div>';
    if (!$consentMapped) {
        echo '<div class="warnBox"><strong>No consent column mapped</strong>Phone and text exports will leave these records out until consent is set. Map a consent column, or record consent later in Settings or on each policy.</div>';
    }
    echo '<button class="btnDark btnBlock" type="submit" form="mapForm">Continue to review →</button>';
    echo '<div class="muted tiny">Re-importing next month’s export updates these records; renewal status, owner, contact history and opt-outs are kept.</div></div></div>';
}

function render_import_review(PDO $pdo, array $stage, array $scan): void
{
    $stats = (array)($stage['preview']['stats'] ?? []);
    $rows = (array)($stage['preview']['rows'] ?? []);
    $total = (int)($stats['rows_total'] ?? 0);
    $willUpdate = (int)($stats['will_update'] ?? 0);
    $rejects = (int)($stats['hard_rejects_preview'] ?? 0);
    echo '<div class="importGrid"><div class="card cardFlush"><div class="cardHeadPad"><div><div class="h2">Review</div><div class="muted">First ' . h((string)IMPORT_PREVIEW_ROWS) . ' rows after cleaning and imputation. Names are masked.</div></div><a class="btnSecondary" href="' . h(tab_url('import', ['remap' => '1'])) . '">← Back to mapping</a></div>';
    $cols = ['policy_number' => 'Record', 'name' => 'Insured', 'policy_type' => lbl('policy_type'), 'expiration_date' => 'Expires', 'contact' => 'Reachable by', 'premium_amount' => 'Premium', 'ltv_estimate' => 'LTV', 'risk_tier' => 'Risk', 'is_imputed' => 'Imputed'];
    echo '<div class="scrollX"><table class="t2 dense"><thead><tr>';
    foreach ($cols as $c => $label) {
        echo '<th' . (in_array($c, ['premium_amount', 'ltv_estimate', 'confidence_score'], true) ? ' class="r"' : '') . '>' . h($label) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($cols as $c => $label) {
            $v = $r[$c] ?? '';
            if ($c === 'premium_amount') {
                echo '<td class="r">' . h(($v === '' || $v === null) ? '—' : money_or_blank((float)$v)) . '</td>';
            } elseif ($c === 'ltv_estimate') {
                echo '<td class="r">' . h(($v === '' || $v === null) ? '—' : money_whole((float)$v)) . '</td>';
            } elseif ($c === 'risk_tier') {
                echo '<td>' . risk_pill((string)$v) . '</td>';
            } elseif ($c === 'is_imputed') {
                echo '<td>' . ((int)$v === 1 ? '<span class="resPill sens">Yes</span>' : '<span class="muted">No</span>') . '</td>';
            } elseif ($c === 'name') {
                echo '<td class="ins">' . h(mask_name((string)$v)) . '</td>';
            } elseif ($c === 'expiration_date') {
                echo '<td>' . h(date_dmy((string)$v)) . '</td>';
            } else {
                echo '<td' . ($c === 'confidence_score' ? ' class="r"' : ($c === 'policy_number' ? ' class="mono"' : '')) . '>' . h((string)$v) . '</td>';
            }
        }
        echo '</tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="' . h((string)count($cols)) . '" class="empty">No rows to preview.</td></tr>';
    }
    echo '</tbody></table></div></div>';
    echo '<div class="stack"><div class="card"><div class="eyebrow">What will happen</div><div class="happen">';
    echo '<div class="item"><span class="ic">↻</span><div><b>' . h(number_format($willUpdate)) . '</b><span>policies updated in place</span></div></div>';
    echo '<div class="item"><span class="ic ok">+</span><div><b>' . h(number_format(max(0, $total - $willUpdate))) . '</b><span>new policies added</span></div></div>';
    echo '<div class="item"><span class="ic ' . ($rejects ? 'warn' : 'ok') . '">' . ($rejects ? '!' : '✓') . '</span><div><b>' . h(number_format($rejects)) . '</b><span>hard errors in the preview · ' . h($rejects ? 'those rows are skipped and logged' : 'nothing will be skipped') . '</span></div></div>';
    echo '<div class="item"><span class="ic">$</span><div><b>' . h(money_or_blank((float)($stats['mean_premium'] ?? 0)) ?: '—') . '</b><span>mean premium after cleaning</span></div></div>';
    echo '</div></div>';
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="import_commit"><button class="btnDark btnBlock" type="submit">Import ' . h(number_format($total)) . ' rows →</button></form>';
    echo '<div class="muted tiny">' . h(number_format($willUpdate)) . ' existing record(s) are updated in place. The staged file is deleted as soon as the import commits.</div></div></div>';
}

function handle_import_preview(PDO $pdo, array $user): void
{
    $stage = $_SESSION['import_stage'] ?? null;
    if (!is_array($stage) || !isset($stage['path'], $stage['headers'])) {
        flash('error', 'No staged import found.');
        redirect_to(['tab' => 'import']);
    }
    if (isset($_POST['cmap']) && is_array($_POST['cmap'])) {
        // Column-centric form: each CSV column (by position) picks a field, "custom" (empty) or __ignore__.
        $mapping = array_fill_keys(core_import_field_keys(), '');
        $stageHeaders = array_map(fn($x) => trim(strip_bom((string)$x)), (array)$stage['headers']);
        foreach ((array)$_POST['cmap'] as $i => $field) {
            $hname = (string)($stageHeaders[(int)$i] ?? '');
            $field = (string)$field;
            if ($hname === '') {
                continue;
            }
            if ($field === '__ignore__') {
                $mapping['ignore:' . (int)$i] = $hname;
            } elseif (in_array($field, core_import_field_keys(), true) && $mapping[$field] === '') {
                $mapping[$field] = $hname;
            }
        }
        if ($mapping['record_id'] === '') {
            $mapping['record_id'] = '__generate__';
        }
    } else {
        $mapping = array_map('strval', (array)($_POST['map'] ?? []));
    }
    $defaults = (array)($_POST['defaults'] ?? []);
    $period = (string)($_POST['premium_period'] ?? '');
    if (in_array($period, ['monthly', 'annual'], true) && $period !== setting('premium_period', 'monthly')) {
        setting_set($pdo, 'premium_period', $period);
    }
    $stage['mapping'] = $mapping;
    $stage['defaults'] = ['region' => trim((string)($defaults['region'] ?? 'Unknown')), 'policy_type' => trim((string)($defaults['policy_type'] ?? 'term'))];
    $analysis = analyze_csv((string)$stage['path'], (array)$stage['headers'], $mapping);
    $preview = preview_csv((string)$stage['path'], (array)$stage['headers'], $mapping, $stage['defaults'], $analysis, $pdo);
    $preview['stats']['will_update'] = import_existing_count($pdo, (string)$stage['path'], $mapping);
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
    $mapping = (array)$stage['mapping'];
    $analysis = (array)$stage['preview']['analysis'];
    $customDefs = is_array($analysis['custom_fields'] ?? null) ? (array)$analysis['custom_fields'] : [];
    upsert_custom_field_defs($pdo, $customDefs);
    $householdCounts = csv_household_counts($path, $mapping);

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

    // Columns that this file is allowed to overwrite on re-import (re-import = update, never duplicate).
    $mappedCols = ['policy_number' => true];
    foreach ($mapping as $field => $hname) {
        if ((string)$hname !== '' && in_array((string)$field, core_import_field_keys(), true)) {
            $mappedCols[storage_field_for_import((string)$field)] = true;
        }
    }
    if (isset($mappedCols['dob'])) {
        $mappedCols['age'] = true;
    }
    if (isset($mappedCols['line_of_business'])) {
        $mappedCols['policy_type'] = true;
    }
    if (isset($mappedCols['effective_date'])) {
        $mappedCols['expiration_date'] = true;
    }

    $rowsTotal = 0;
    $rowsNew = 0;
    $rowsUpdated = 0;
    $errors = [];
    $seen = [];
    $cols = policy_storage_columns();
    $derivedCols = ['ltv_estimate', 'risk_tier', 'is_imputed', 'confidence_score', 'metadata', 'created_at', 'updated_at'];
    $ins = $pdo->prepare('INSERT INTO policyholders (' . implode(', ', array_merge($cols, $derivedCols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols) + count($derivedCols), '?')) . ')');
    $find = $pdo->prepare('SELECT id, premium_amount, expiration_date, metadata FROM policyholders WHERE policy_number = ? LIMIT 1');

    $pdo->beginTransaction();
    while (($row = fgetcsv($fh)) !== false) {
        $rowsTotal++;
        $rec = map_row($headers, $row, $mapping);
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
        $hid = (string)($validated['household_id'] ?? '');
        if ($hid !== '') {
            $validated['policies_in_household'] = max((int)($householdCounts[$hid] ?? 1), household_count($hid));
        }
        $scored = score_policy_record($validated, $imputed, $rules);
        $values = policy_column_values($validated);
        $now = now_iso();

        $find->execute([$policyNumber]);
        $existing = $find->fetch();
        if ($existing) {
            $oldMeta = safe_json((string)($existing['metadata'] ?? '{}'));
            $oldMeta = is_array($oldMeta) ? $oldMeta : [];
            $oldCustom = is_array($oldMeta['custom_fields'] ?? null) ? (array)$oldMeta['custom_fields'] : [];
            $meta = $oldMeta;
            $meta['imputed_fields'] = array_values($imputed);
            $meta['rule_hits'] = $scored['rule_hits'];
            $meta['tags'] = $scored['tags'];
            $meta['tier_rule_id'] = $scored['tier_rule_id'];
            $meta['custom_fields'] = array_merge($oldCustom, $customFields);
            $set = [];
            $args = [];
            foreach ($values as $col => $val) {
                if ($col === 'policy_number' || !isset($mappedCols[$col])) {
                    continue;
                }
                if (in_array($col, ['consent_email', 'consent_sms', 'consent_phone'], true) && $val === null) {
                    continue;
                }
                $set[] = $col . ' = ?';
                $args[] = $val;
            }
            $oldPremium = $existing['premium_amount'] !== null ? (float)$existing['premium_amount'] : null;
            if (isset($mappedCols['premium_amount']) && $oldPremium !== null && $values['premium_amount'] !== null && abs($oldPremium - (float)$values['premium_amount']) >= 0.01) {
                $set[] = 'prior_premium = ?';
                $args[] = $oldPremium;
            }
            $oldExp = (string)($existing['expiration_date'] ?? '');
            if (isset($mappedCols['expiration_date']) && $oldExp !== '' && (string)$values['expiration_date'] !== '' && (string)$values['expiration_date'] > add_months_iso($oldExp, 1)) {
                $set[] = 'renewal_status = NULL';
            }
            array_push($set, 'ltv_estimate = ?', 'risk_tier = ?', 'is_imputed = ?', 'confidence_score = ?', 'metadata = ?', 'updated_at = ?');
            array_push($args, $scored['ltv'], $scored['risk_tier'], count($imputed) > 0 ? 1 : 0, $scored['confidence'], json_encode($meta, JSON_UNESCAPED_SLASHES), $now);
            $args[] = (int)$existing['id'];
            $pdo->prepare('UPDATE policyholders SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
            $rowsUpdated++;
        } else {
            foreach (['consent_email', 'consent_sms', 'consent_phone'] as $c) {
                $values[$c] = (int)($values[$c] ?? 0);
            }
            $meta = ['imputed_fields' => array_values($imputed), 'rule_hits' => $scored['rule_hits'], 'tags' => $scored['tags'], 'tier_rule_id' => $scored['tier_rule_id'], 'custom_fields' => $customFields];
            $ins->execute(array_merge(array_values($values), [$scored['ltv'], $scored['risk_tier'], count($imputed) > 0 ? 1 : 0, $scored['confidence'], json_encode($meta, JSON_UNESCAPED_SLASHES), $now, $now]));
            $rowsNew++;
        }
        if ((($rowsNew + $rowsUpdated) % IMPORT_COMMIT_EVERY_ROWS) === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
        }
    }
    $pdo->commit();
    fclose($fh);

    $upd = $pdo->prepare('UPDATE imports SET rows_total = ?, rows_imported = ?, rows_updated = ?, errors_json = ? WHERE id = ?');
    $upd->execute([$rowsTotal, $rowsNew + $rowsUpdated, $rowsUpdated, json_encode($errors, JSON_UNESCAPED_SLASHES), $importId]);
    if ($filename === 'liteinsurance_sample.csv' && ($rowsNew + $rowsUpdated) > 0) {
        seed_sample_campaigns($pdo);
    }
    audit($pdo, (int)$user['id'], 'import.commit', ['filename' => $filename, 'rows_total' => $rowsTotal, 'rows_new' => $rowsNew, 'rows_updated' => $rowsUpdated, 'import_id' => $importId]);
    $_SESSION['import_stage'] = null;
    if (str_starts_with(realpath($path) ?: '', realpath(upload_dir()) ?: "\0")) {
        @unlink($path); // staged copy is no longer needed once rows are in SQLite
    }
    flash('success', 'Import completed: ' . $rowsNew . ' new, ' . $rowsUpdated . ' updated' . ($errors ? (', ' . count($errors) . ' skipped') : '') . '.');
    redirect_to(['tab' => 'import']);
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
    $out['name'] = trim(preg_replace('/\s+/', ' ', (string)($rec['name'] ?? '')) ?? '');
    if ($out['name'] === '') {
        $out['name'] = $out['policy_number'];
        $imputedFields[] = 'display_name';
    }
    $dobRaw = trim((string)($rec['dob'] ?? ''));
    $out['dob'] = $dobRaw !== '' ? (parse_date_any($dobRaw) ?? $dobRaw) : '';
    $out['gender'] = normalize_gender(trim((string)($rec['gender'] ?? '')));
    $out['age'] = parse_int((string)($rec['age'] ?? ''));
    if ($out['age'] === null && $out['dob'] !== '') {
        $age = age_from_dob($out['dob']);
        if ($age !== null) {
            $out['age'] = $age; // derived from DOB, not imputed
        }
    }
    $out['premium_amount'] = parse_number((string)($rec['premium_amount'] ?? ''));
    $out['tenure_months'] = parse_int((string)($rec['tenure_months'] ?? ''));
    $out['sum_assured'] = parse_number((string)($rec['sum_assured'] ?? ''));
    $out['line_of_business'] = normalize_label_case((string)($rec['line_of_business'] ?? ''));
    $out['policy_type'] = normalize_policy_type((string)($rec['policy_type'] ?? ''));
    if ($out['policy_type'] === '' && $out['line_of_business'] !== '') {
        $out['policy_type'] = policy_type_from_lob($out['line_of_business']);
    }
    $out['region'] = normalize_label_case((string)($rec['region'] ?? ''));
    $out['status'] = normalize_policy_status((string)($rec['status'] ?? ''));
    $out['carrier'] = normalize_label_case((string)($rec['carrier'] ?? ''));
    $out['producer'] = trim(preg_replace('/\s+/', ' ', (string)($rec['producer'] ?? '')) ?? '');
    $out['household_id'] = trim((string)($rec['household_id'] ?? ''));
    $out['email'] = normalize_email((string)($rec['email'] ?? ''));
    $out['phone'] = normalize_phone((string)($rec['phone'] ?? ''));
    $out['consent_email'] = parse_bool_loose((string)($rec['consent_email'] ?? ''));
    $out['consent_sms'] = parse_bool_loose((string)($rec['consent_sms'] ?? ''));
    $out['consent_phone'] = parse_bool_loose((string)($rec['consent_phone'] ?? ''));
    $out['effective_date'] = parse_date_any((string)($rec['effective_date'] ?? '')) ?? '';
    $out['expiration_date'] = parse_date_any((string)($rec['expiration_date'] ?? '')) ?? '';
    if ($out['expiration_date'] === '' && $out['effective_date'] !== '') {
        $term = $out['policy_type'] === 'auto' ? 6 : 12;
        $out['expiration_date'] = add_months_iso($out['effective_date'], $term);
        if ($out['expiration_date'] !== '') {
            $imputedFields[] = 'expiration_date';
        }
    }

    if ($out['premium_amount'] === null && field_was_mapped($analysis, 'premium_amount')) {
        $out['premium_amount'] = round((float)($analysis['means']['premium_amount'] ?? 0), 2);
        $imputedFields[] = 'premium_amount';
    }
    if ($out['tenure_months'] === null && field_was_mapped($analysis, 'tenure_months')) {
        $out['tenure_months'] = (int)round((float)($analysis['means']['tenure_months'] ?? 0));
        $imputedFields[] = 'tenure_months';
    }
    if ($out['sum_assured'] === null && field_was_mapped($analysis, 'sum_assured') && (float)($analysis['means']['sum_assured'] ?? 0) > 0) {
        $out['sum_assured'] = round((float)($analysis['means']['sum_assured'] ?? 0), 0);
        $imputedFields[] = 'sum_assured';
    }
    if ($out['policy_type'] === '' && field_was_mapped($analysis, 'policy_type')) {
        $mode = (string)($analysis['modes']['policy_type'] ?? '');
        $out['policy_type'] = normalize_policy_type($mode !== '' ? $mode : (string)($defaults['policy_type'] ?? 'term'));
        $imputedFields[] = 'policy_type';
    }
    if ($out['region'] === '' && field_was_mapped($analysis, 'region')) {
        $mode = (string)($analysis['modes']['region'] ?? '');
        $out['region'] = normalize_label_case($mode !== '' ? $mode : (string)($defaults['region'] ?? 'Unknown'));
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

function policyholder_record_from_row(array $ph): array
{
    $meta = safe_json((string)($ph['metadata'] ?? '{}'));
    if (!is_array($meta)) {
        $meta = [];
    }
    $customFields = is_array($meta['custom_fields'] ?? null) ? (array)$meta['custom_fields'] : [];
    $exp = (string)($ph['expiration_date'] ?? '');
    $hid = (string)($ph['household_id'] ?? '');
    return [
        'policy_number' => (string)($ph['policy_number'] ?? ''),
        'name' => (string)($ph['name'] ?? ''),
        'dob' => (string)($ph['dob'] ?? ''),
        'age' => (($ph['age'] ?? null) !== null) ? (int)$ph['age'] : null,
        'gender' => (string)($ph['gender'] ?? ''),
        'policy_type' => (string)($ph['policy_type'] ?? ''),
        'region' => (string)($ph['region'] ?? ''),
        'premium_amount' => (($ph['premium_amount'] ?? null) !== null) ? (float)$ph['premium_amount'] : null,
        'tenure_months' => (($ph['tenure_months'] ?? null) !== null) ? (int)$ph['tenure_months'] : null,
        'sum_assured' => (($ph['sum_assured'] ?? null) !== null) ? (float)$ph['sum_assured'] : null,
        'ltv_estimate' => (($ph['ltv_estimate'] ?? null) !== null) ? (float)$ph['ltv_estimate'] : null,
        'risk_tier' => (string)($ph['risk_tier'] ?? ''),
        'confidence_score' => (int)($ph['confidence_score'] ?? 0),
        'is_imputed' => (int)($ph['is_imputed'] ?? 0),
        'effective_date' => (string)($ph['effective_date'] ?? ''),
        'expiration_date' => $exp,
        'days_to_expiry' => days_until($exp),
        'status' => (string)($ph['status'] ?? ''),
        'line_of_business' => (string)($ph['line_of_business'] ?? ''),
        'carrier' => (string)($ph['carrier'] ?? ''),
        'producer' => (string)($ph['producer'] ?? ''),
        'household_id' => $hid,
        'policies_in_household' => household_count($hid),
        'email' => (string)($ph['email'] ?? ''),
        'phone' => (string)($ph['phone'] ?? ''),
        'consent_email' => (int)($ph['consent_email'] ?? 0),
        'consent_sms' => (int)($ph['consent_sms'] ?? 0),
        'consent_phone' => (int)($ph['consent_phone'] ?? 0),
        'opted_out' => trim((string)($ph['opt_out_at'] ?? '')) !== '' ? 1 : 0,
        'renewal_status' => (string)($ph['renewal_status'] ?? '') !== '' ? (string)$ph['renewal_status'] : 'not_started',
        'renewal_owner' => (string)($ph['renewal_owner'] ?? ''),
        'prior_premium' => (($ph['prior_premium'] ?? null) !== null) ? (float)$ph['prior_premium'] : null,
        'custom' => $customFields,
    ];
}

function load_rules(PDO $pdo): array
{
    // Highest priority first; the first rule that sets a tier wins (same outcome as the v1.0 "last writer in ascending order").
    $rows = $pdo->query('SELECT id, name, conditions_json, actions_json, priority FROM rules WHERE enabled = 1 ORDER BY priority DESC, id DESC')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'priority' => (int)$r['priority'], 'conditions' => safe_json((string)$r['conditions_json']), 'actions' => safe_json((string)$r['actions_json'])];
    }
    return $out;
}

function evaluate_rules_for_record(array $record, array $rules): array
{
    $riskTier = 'Unknown';
    $tierRuleId = 0;
    $tierSet = false;
    $tags = [];
    $hits = [];
    $scoreDelta = 0;
    $stoppedAt = 0;
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
        if (!$tierSet && isset($actions['set_risk_tier']) && is_string($actions['set_risk_tier']) && $actions['set_risk_tier'] !== '') {
            $riskTier = (string)$actions['set_risk_tier'];
            $tierRuleId = (int)($rule['id'] ?? 0);
            $tierSet = true;
        }
        if (isset($actions['score_delta'])) {
            $scoreDelta += (int)$actions['score_delta'];
        }
        if (isset($actions['add_tag']) && is_string($actions['add_tag']) && $actions['add_tag'] !== '') {
            $tags[] = (string)$actions['add_tag'];
        }
        if (!empty($actions['stop'])) {
            $stoppedAt = (int)($rule['id'] ?? 0);
            break;
        }
    }
    $tags = array_values(array_unique($tags));
    return ['risk_tier' => $riskTier, 'score_delta' => $scoreDelta, 'tags' => $tags, 'rule_hits' => $hits, 'tier_rule_id' => $tierRuleId, 'stopped_at' => $stoppedAt];
}

function value_for_rule_field(array $record, string $field)
{
    $field = trim($field);
    if (str_starts_with($field, 'custom.')) {
        $key = substr($field, 7);
        $custom = is_array($record['custom'] ?? null) ? (array)$record['custom'] : [];
        return $custom[$key] ?? null;
    }
    if (array_key_exists($field, $record)) {
        return $record[$field];
    }
    if ($field === 'days_to_expiry') {
        return days_until((string)($record['expiration_date'] ?? ''));
    }
    if ($field === 'policies_in_household') {
        return household_count((string)($record['household_id'] ?? ''));
    }
    if ($field === 'opted_out') {
        return 0;
    }
    if ($field === 'renewal_status') {
        return 'not_started';
    }
    return null;
}

function compute_ltv(array $r): ?float
{
    $b = ltv_breakdown($r);
    return $b === null ? null : (float)$b['ltv'];
}

/* ==========================================================================
   v1.2 — two-step sign-in (TOTP), user management, retention and erasure
   ========================================================================== */

function migrate_v12(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= 12) {
        return;
    }
    $cols = table_columns($pdo, 'users');
    $add = [
        'totp_secret' => 'TEXT',
        'totp_enabled' => 'INTEGER NOT NULL DEFAULT 0',
        'totp_enabled_at' => 'TEXT',
        'totp_last_step' => 'INTEGER NOT NULL DEFAULT 0',
        'recovery_codes' => "TEXT NOT NULL DEFAULT '[]'",
        'password_changed_at' => 'TEXT',
    ];
    foreach ($add as $col => $ddl) {
        if (!isset($cols[$col])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN ' . $col . ' ' . $ddl);
        }
    }
    $pdo->exec('PRAGMA user_version = 12');
}

/* ---------- TOTP (RFC 6238, SHA-1, 6 digits, 30 s) ---------- */

function base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    if ($data === '') {
        return '';
    }
    $bits = '';
    foreach (str_split($data) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '');
    if ($b32 === '') {
        return '';
    }
    $bits = '';
    foreach (str_split($b32) as $c) {
        $v = strpos($alphabet, $c);
        if ($v === false) {
            return '';
        }
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr((int)bindec($byte));
        }
    }
    return $out;
}

function totp_new_secret(): string
{
    return base32_encode(random_bytes(20));
}

function totp_at(string $secretB32, int $step, int $digits = 6): string
{
    $key = base32_decode($secretB32);
    $msg = pack('N', ($step >> 32) & 0xFFFFFFFF) . pack('N', $step & 0xFFFFFFFF);
    $hash = hash_hmac('sha1', $msg, $key, true);
    $off = ord($hash[19]) & 0x0F;
    $bin = ((ord($hash[$off]) & 0x7F) << 24) | (ord($hash[$off + 1]) << 16) | (ord($hash[$off + 2]) << 8) | ord($hash[$off + 3]);
    return str_pad((string)($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

function totp_match(string $secretB32, string $code, int $lastStep, ?int $now = null): ?int
{
    $code = preg_replace('/\D+/', '', $code) ?? '';
    if (strlen($code) !== 6 || $secretB32 === '') {
        return null;
    }
    $step = intdiv($now ?? time(), 30);
    foreach ([0, -1, 1] as $drift) {
        $s = $step + $drift;
        if ($s <= $lastStep) {
            continue;
        }
        if (hash_equals(totp_at($secretB32, $s), $code)) {
            return $s;
        }
    }
    return null;
}

function totp_uri(string $secretB32, string $email): string
{
    $label = rawurlencode(APP_NAME . ':' . $email);
    return 'otpauth://totp/' . $label . '?secret=' . $secretB32 . '&issuer=' . rawurlencode(APP_NAME) . '&algorithm=SHA1&digits=6&period=30';
}

function recovery_codes_generate(): array
{
    $plain = [];
    for ($i = 0; $i < 8; $i++) {
        $hex = strtoupper(bin2hex(random_bytes(5)));
        $plain[] = substr($hex, 0, 5) . '-' . substr($hex, 5, 5);
    }
    return $plain;
}

function recovery_code_hash(string $code): string
{
    return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''));
}

function user_row(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    return $u ?: null;
}

function registration_open(PDO $pdo): bool
{
    $hasUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    return !$hasUsers || setting('allow_registration', '0') === '1';
}

function totp_required_for(array $user): bool
{
    return setting('require_totp', '0') === '1' && (int)($user['totp_enabled'] ?? 0) !== 1;
}

function complete_login(PDO $pdo, array $u, string $method): void
{
    unset($_SESSION['pending_2fa']);
    $_SESSION['user_id'] = (int)$u['id'];
    session_regenerate_id(true);
    audit($pdo, (int)$u['id'], 'auth.login', ['email' => (string)$u['email'], 'role' => (string)$u['role'], 'ip' => client_ip(), 'mfa' => $method]);
    flash('success', 'Signed in.');
    redirect_to(['action' => null, 'tab' => 'dashboard']);
}

function handle_totp_verify(PDO $pdo): void
{
    require_csrf();
    $pending = $_SESSION['pending_2fa'] ?? null;
    if (!is_array($pending) || (time() - (int)($pending['ts'] ?? 0)) > 300) {
        unset($_SESSION['pending_2fa']);
        flash('error', 'That sign-in expired. Enter your password again.');
        redirect_to(['action' => 'login_form']);
    }
    if (!rate_limit_allow($pdo, 'auth.totp', client_ip() . '|' . (int)$pending['uid'], 8, 15 * 60)) {
        http_response_code(429);
        flash('error', 'Too many codes tried. Wait a few minutes and sign in again.');
        unset($_SESSION['pending_2fa']);
        redirect_to(['action' => 'login_form']);
    }
    $u = user_row($pdo, (int)$pending['uid']);
    if (!$u || (int)$u['totp_enabled'] !== 1) {
        unset($_SESSION['pending_2fa']);
        redirect_to(['action' => 'login_form']);
    }
    $code = trim((string)($_POST['code'] ?? ''));
    $step = totp_match((string)$u['totp_secret'], $code, (int)$u['totp_last_step']);
    if ($step !== null) {
        $pdo->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?')->execute([$step, (int)$u['id']]);
        complete_login($pdo, $u, 'totp');
    }
    $hashes = safe_json((string)($u['recovery_codes'] ?? '[]'));
    $hashes = is_array($hashes) ? array_values(array_map('strval', $hashes)) : [];
    $h = recovery_code_hash($code);
    $idx = array_search($h, $hashes, true);
    if (strlen(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '') === 10 && $idx !== false) {
        array_splice($hashes, (int)$idx, 1);
        $pdo->prepare('UPDATE users SET recovery_codes = ? WHERE id = ?')->execute([json_encode($hashes), (int)$u['id']]);
        audit($pdo, (int)$u['id'], 'auth.recovery_code_used', ['remaining' => count($hashes)]);
        flash('info', 'Signed in with a recovery code. ' . count($hashes) . ' left — regenerate them under Account.');
        complete_login($pdo, $u, 'recovery_code');
    }
    audit($pdo, (int)$u['id'], 'auth.totp_failed', ['ip' => client_ip()]);
    flash('error', 'That code did not match. Use the current 6-digit code or a recovery code.');
    redirect_to(['action' => 'totp_form']);
}

/* ---------- account page ---------- */

function render_tab_account(PDO $pdo, array $user): void
{
    $u = user_row($pdo, (int)$user['id']) ?? $user;
    $enabled = (int)($u['totp_enabled'] ?? 0) === 1;
    $required = setting('require_totp', '0') === '1';
    if ($required && !$enabled) {
        echo '<div class="flash">Your workspace requires two-step verification. Set it up below to continue.</div>';
    }
    $codes = $_SESSION['recovery_show'] ?? null;
    unset($_SESSION['recovery_show']);

    echo '<div class="grid2">';
    echo '<div class="card"><div class="cardHead"><div><div class="h2">Two-step verification</div><div class="muted">A 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password, Authy…) after your password.</div></div>' . ($enabled ? '<span class="pill st-renewed">On</span>' : '<span class="pill">Off</span>') . '</div>';
    if (is_array($codes)) {
        echo '<div class="flash flashOk"><strong>Save these recovery codes now.</strong> Each works once if you lose your phone. They will not be shown again.</div><pre class="codeBlock recoveryCodes">' . h(implode("\n", array_map('strval', $codes))) . '</pre>';
    }
    $setup = is_string($_SESSION['totp_setup'] ?? null) ? (string)$_SESSION['totp_setup'] : '';
    if ($enabled) {
        $left = safe_json((string)($u['recovery_codes'] ?? '[]'));
        $left = is_array($left) ? count($left) : 0;
        echo '<dl class="kv"><dt>Turned on</dt><dd>' . h(fmt_date((string)($u['totp_enabled_at'] ?? ''))) . '</dd><dt>Recovery codes left</dt><dd>' . h((string)$left) . '</dd></dl>';
        echo '<form method="post" class="form formTight">' . csrf_field() . '<input type="hidden" name="action" value="totp_recovery_regen"><label class="label" for="rcCode">Current code</label><input class="input" id="rcCode" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required><button class="btnSecondary btnSm" type="submit">New recovery codes</button></form>';
        if (!$required) {
            echo '<div class="divider"></div><form method="post" class="form formTight" data-confirm="Turn off two-step verification?">' . csrf_field() . '<input type="hidden" name="action" value="totp_disable"><label class="label" for="offPw">Password</label><input class="input" id="offPw" type="password" name="password" autocomplete="current-password" required><label class="label" for="offCode">Current code</label><input class="input" id="offCode" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required><button class="btnGhost btnSm" type="submit">Turn off</button></form>';
        } else {
            echo '<div class="muted tiny">Required by your workspace, so it can’t be turned off here. An admin can reset it if you lose your device.</div>';
        }
    } elseif ($setup !== '') {
        $grouped = trim(chunk_split($setup, 4, ' '));
        echo '<ol class="setupSteps"><li>In your authenticator app, add an account and enter this key (time-based):<div class="secretKey mono">' . h($grouped) . '</div><a class="link" href="' . h(totp_uri($setup, (string)$u['email'])) . '">On this phone? Open in your authenticator</a></li>';
        echo '<li>Type the 6-digit code it shows:';
        echo '<form method="post" class="actionsRow">' . csrf_field() . '<input type="hidden" name="action" value="totp_enable"><input class="input inputCode" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required aria-label="6-digit code"><button class="btn" type="submit">Turn on</button></form></li></ol>';
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="totp_cancel"><button class="btnGhost btnSm" type="submit">Cancel setup</button></form>';
    } else {
        echo '<form method="post" class="actionsRow">' . csrf_field() . '<input type="hidden" name="action" value="totp_begin"><button class="btn" type="submit">Set up two-step verification</button></form>';
    }
    echo '</div>';

    echo '<div class="card"><div class="h2">Password</div><div class="muted">Signed in as ' . h((string)$u['email']) . ' (' . h((string)$u['role']) . ').</div>';
    echo '<form method="post" class="form">' . csrf_field() . '<input type="hidden" name="action" value="password_change">';
    echo '<label class="label" for="pwCur">Current password</label><input class="input" id="pwCur" type="password" name="current" autocomplete="current-password" required>';
    echo '<label class="label" for="pwNew">New password</label><input class="input" id="pwNew" type="password" name="password" minlength="10" maxlength="200" autocomplete="new-password" required>';
    echo '<label class="label" for="pwNew2">Confirm new password</label><input class="input" id="pwNew2" type="password" name="password2" minlength="10" maxlength="200" autocomplete="new-password" required>';
    echo '<button class="btnSecondary" type="submit">Change password</button></form></div>';
    echo '</div>';
}

function handle_account_action(PDO $pdo, array $user, string $action): void
{
    $u = user_row($pdo, (int)$user['id']);
    if (!$u) {
        redirect_to(['tab' => 'account']);
    }
    $uid = (int)$u['id'];
    if ($action === 'totp_begin') {
        $_SESSION['totp_setup'] = totp_new_secret();
    } elseif ($action === 'totp_cancel') {
        unset($_SESSION['totp_setup']);
    } elseif ($action === 'totp_enable') {
        $secret = is_string($_SESSION['totp_setup'] ?? null) ? (string)$_SESSION['totp_setup'] : '';
        $step = totp_match($secret, (string)($_POST['code'] ?? ''), 0);
        if ($step === null) {
            flash('error', 'That code did not match. Check the phone clock and try the newest code.');
            redirect_to(['tab' => 'account']);
        }
        $codes = recovery_codes_generate();
        $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_enabled_at = ?, totp_last_step = ?, recovery_codes = ? WHERE id = ?')
            ->execute([$secret, now_iso(), $step, json_encode(array_map('recovery_code_hash', $codes)), $uid]);
        unset($_SESSION['totp_setup']);
        $_SESSION['recovery_show'] = $codes;
        audit($pdo, $uid, 'auth.totp_enabled', []);
        flash('success', 'Two-step verification is on.');
    } elseif ($action === 'totp_recovery_regen') {
        $step = totp_match((string)$u['totp_secret'], (string)($_POST['code'] ?? ''), (int)$u['totp_last_step']);
        if ((int)$u['totp_enabled'] !== 1 || $step === null) {
            flash('error', 'That code did not match.');
            redirect_to(['tab' => 'account']);
        }
        $codes = recovery_codes_generate();
        $pdo->prepare('UPDATE users SET recovery_codes = ?, totp_last_step = ? WHERE id = ?')->execute([json_encode(array_map('recovery_code_hash', $codes)), $step, $uid]);
        $_SESSION['recovery_show'] = $codes;
        audit($pdo, $uid, 'auth.recovery_codes_regenerated', []);
        flash('success', 'New recovery codes created; the old ones no longer work.');
    } elseif ($action === 'totp_disable') {
        if (setting('require_totp', '0') === '1') {
            flash('error', 'Two-step verification is required in this workspace.');
            redirect_to(['tab' => 'account']);
        }
        $step = totp_match((string)$u['totp_secret'], (string)($_POST['code'] ?? ''), (int)$u['totp_last_step']);
        if (!password_verify((string)($_POST['password'] ?? ''), (string)$u['password_hash']) || $step === null) {
            flash('error', 'Password or code did not match.');
            redirect_to(['tab' => 'account']);
        }
        $pdo->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_enabled_at = NULL, totp_last_step = 0, recovery_codes = '[]' WHERE id = ?")->execute([$uid]);
        audit($pdo, $uid, 'auth.totp_disabled', []);
        flash('success', 'Two-step verification is off.');
    } elseif ($action === 'password_change') {
        $new = (string)($_POST['password'] ?? '');
        if (!password_verify((string)($_POST['current'] ?? ''), (string)$u['password_hash'])) {
            flash('error', 'Current password is wrong.');
        } elseif (strlen($new) < 10) {
            flash('error', 'Use at least 10 characters.');
        } elseif (!hash_equals($new, (string)($_POST['password2'] ?? ''))) {
            flash('error', 'New passwords do not match.');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), now_iso(), $uid]);
            session_regenerate_id(true);
            audit($pdo, $uid, 'auth.password_changed', []);
            flash('success', 'Password changed.');
        }
    }
    redirect_to(['tab' => 'account']);
}

/* ---------- users & access (admin) ---------- */

function render_users_card(PDO $pdo, array $me): void
{
    $users = $pdo->query('SELECT id, email, role, created_at, totp_enabled FROM users ORDER BY id ASC')->fetchAll();
    echo '<div class="card"><div class="cardHead"><div><div class="h2">Users &amp; access</div><div class="muted">Who can sign in, what they can do, and how.</div></div></div>';
    echo '<form method="post" class="form formTight">' . csrf_field() . '<input type="hidden" name="action" value="settings_save"><input type="hidden" name="section" value="access">';
    echo '<label class="check"><input type="checkbox" name="allow_registration" value="1"' . (setting('allow_registration', '0') === '1' ? ' checked' : '') . '> Anyone with the link can create a (non-admin) account</label>';
    echo '<label class="check"><input type="checkbox" name="require_totp" value="1"' . (setting('require_totp', '0') === '1' ? ' checked' : '') . '> Require two-step verification for everyone</label>';
    echo '<div><button class="btnSecondary btnSm" type="submit">Save access policy</button></div></form>';
    echo '<table class="table compact"><thead><tr><th>User</th><th>Role</th><th>Two-step</th><th></th></tr></thead><tbody>';
    foreach ($users as $u) {
        $isMe = (int)$u['id'] === (int)$me['id'];
        echo '<tr><td>' . h((string)$u['email']) . ($isMe ? ' <span class="muted tiny">(you)</span>' : '') . '<div class="muted tiny">since ' . h(substr((string)$u['created_at'], 0, 10)) . '</div></td><td>' . h((string)$u['role']) . '</td><td>' . ((int)$u['totp_enabled'] === 1 ? '<span class="pill st-renewed">On</span>' : '<span class="pill">Off</span>') . '</td><td class="nowrap">';
        if (!$isMe) {
            $ops = [((string)$u['role'] === 'admin' ? 'make_user' : 'make_admin') => ((string)$u['role'] === 'admin' ? 'Make user' : 'Make admin')];
            if ((int)$u['totp_enabled'] === 1) {
                $ops['reset_2fa'] = 'Reset two-step';
            }
            $ops['delete'] = 'Remove';
            foreach ($ops as $op => $label) {
                echo '<form method="post" class="inline"' . ($op === 'delete' || $op === 'reset_2fa' ? ' data-confirm="' . h($label . ' for ' . (string)$u['email'] . '?') . '"' : '') . '>' . csrf_field() . '<input type="hidden" name="action" value="user_update"><input type="hidden" name="id" value="' . h((string)$u['id']) . '"><input type="hidden" name="op" value="' . h($op) . '"><button class="linkBtn" type="submit">' . h($label) . '</button></form> ';
            }
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function handle_user_update(PDO $pdo, array $me): void
{
    $id = (int)($_POST['id'] ?? 0);
    $op = (string)($_POST['op'] ?? '');
    $u = $id > 0 ? user_row($pdo, $id) : null;
    if (!$u || $id === (int)$me['id']) {
        flash('error', 'You can’t change your own account here.');
        redirect_to(['tab' => 'settings']);
    }
    if ($op === 'make_admin' || $op === 'make_user') {
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$op === 'make_admin' ? 'admin' : 'user', $id]);
        flash('success', (string)$u['email'] . ' is now ' . ($op === 'make_admin' ? 'an admin' : 'a user') . '.');
    } elseif ($op === 'reset_2fa') {
        $pdo->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_enabled_at = NULL, totp_last_step = 0, recovery_codes = '[]' WHERE id = ?")->execute([$id]);
        flash('success', 'Two-step verification reset for ' . (string)$u['email'] . '.');
    } elseif ($op === 'delete') {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        flash('success', (string)$u['email'] . ' removed.');
    } else {
        redirect_to(['tab' => 'settings']);
    }
    audit($pdo, (int)$me['id'], 'users.' . $op, ['id' => $id]);
    redirect_to(['tab' => 'settings']);
}

/* ---------- retention, erasure, wipe ---------- */

function retention_settings(): array
{
    return [
        'uploads' => max(0, (int)setting('retention_uploads_days', '7')),
        'audit' => max(0, (int)setting('retention_audit_days', '0')),
        'contacts' => max(0, (int)setting('retention_contact_days', '0')),
        'policies' => max(0, (int)setting('retention_inactive_policy_days', '0')),
    ];
}

function stale_upload_files(int $days): array
{
    if ($days <= 0 || !is_dir(upload_dir())) {
        return [];
    }
    $cutoff = time() - $days * 86400;
    $out = [];
    foreach (glob(upload_dir() . DIRECTORY_SEPARATOR . '*.csv') ?: [] as $f) {
        if (is_file($f) && (int)filemtime($f) < $cutoff) {
            $out[] = $f;
        }
    }
    return $out;
}

function retention_policy_where(int $days): string
{
    $d = (int)$days;
    return "((COALESCE(status,'') IN ('cancelled','expired','lapsed','non-renewed') AND COALESCE(updated_at, created_at) < datetime('now', '-$d days'))"
        . " OR (expiration_date IS NOT NULL AND expiration_date <> '' AND expiration_date < date('now', '-$d days')))";
}

function run_retention(PDO $pdo, bool $apply): array
{
    $r = retention_settings();
    $out = ['uploads' => 0, 'audit' => 0, 'contacts' => 0, 'policies' => 0];
    $files = stale_upload_files($r['uploads']);
    $out['uploads'] = count($files);
    if ($r['audit'] > 0) {
        $out['audit'] = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE ts < datetime('now', '-" . $r['audit'] . " days')")->fetchColumn();
    }
    if ($r['contacts'] > 0) {
        $out['contacts'] = (int)$pdo->query("SELECT COUNT(*) FROM contact_log WHERE ts < datetime('now', '-" . $r['contacts'] . " days')")->fetchColumn();
    }
    if ($r['policies'] > 0) {
        $out['policies'] = (int)$pdo->query('SELECT COUNT(*) FROM policyholders WHERE ' . retention_policy_where($r['policies']))->fetchColumn();
    }
    if (!$apply) {
        return $out;
    }
    foreach ($files as $f) {
        @unlink($f);
    }
    $pdo->beginTransaction();
    if ($r['audit'] > 0) {
        $pdo->exec("DELETE FROM audit_log WHERE ts < datetime('now', '-" . $r['audit'] . " days')");
    }
    if ($r['contacts'] > 0) {
        $pdo->exec("DELETE FROM contact_log WHERE ts < datetime('now', '-" . $r['contacts'] . " days')");
    }
    if ($r['policies'] > 0) {
        $where = retention_policy_where($r['policies']);
        $pdo->exec('DELETE FROM contact_log WHERE policyholder_id IN (SELECT id FROM policyholders WHERE ' . $where . ')');
        $pdo->exec('DELETE FROM policyholders WHERE ' . $where);
    }
    $pdo->commit();
    if (array_sum($out) > 0) {
        audit($pdo, 0, 'retention.run', $out);
    }
    return $out;
}

function render_retention_card(PDO $pdo): void
{
    $r = retention_settings();
    $preview = run_retention($pdo, false);
    echo '<div class="card"><div class="h2">Data retention</div><div class="muted">Delete what you no longer need. Runs with the daily cron, or now. 0 keeps data forever.</div>';
    echo '<form method="post" class="form">' . csrf_field() . '<input type="hidden" name="action" value="settings_save"><input type="hidden" name="section" value="retention"><div class="grid2">';
    foreach ([['retention_uploads_days', 'Staged CSV uploads (days)', $r['uploads'], $preview['uploads'], 'files'], ['retention_audit_days', 'Audit log (days)', $r['audit'], $preview['audit'], 'events'], ['retention_contact_days', 'Contact history (days)', $r['contacts'], $preview['contacts'], 'entries'], ['retention_inactive_policy_days', 'Inactive or long-expired policies (days)', $r['policies'], $preview['policies'], 'policies']] as [$key, $label, $val, $due, $unit]) {
        echo '<div><label class="label" for="' . h($key) . '">' . h($label) . '</label><input class="input" id="' . h($key) . '" name="' . h($key) . '" type="number" min="0" max="3650" value="' . h((string)$val) . '"><div class="muted tiny">' . h($val > 0 ? number_format($due) . ' ' . $unit . ' due for deletion' : 'Kept forever') . '</div></div>';
    }
    echo '</div><div class="actionsRow"><button class="btnSecondary" type="submit">Save retention</button></div></form>';
    echo '<form method="post" data-confirm="Delete everything listed as due now?">' . csrf_field() . '<input type="hidden" name="action" value="retention_run"><button class="btnSecondary btnSm" type="submit"' . (array_sum($preview) === 0 ? ' disabled' : '') . '>Run retention now</button></form>';
    echo '<div class="divider"></div><div class="h3">Erase a person</div><div class="muted">For deletion requests. Removes every policy matching a policy number, email, phone or household ID, plus its contact history. The audit log records that an erasure happened, not who.</div>';
    echo '<form method="post" class="actionsRow" data-confirm="Permanently erase every matching record?">' . csrf_field() . '<input type="hidden" name="action" value="privacy_erase"><input class="input" name="identifier" maxlength="190" required placeholder="P-3017, jane@example.com, +1 555…" aria-label="Identifier"><button class="btnSecondary btnSm" type="submit">Erase</button></form>';
    echo '<div class="divider"></div><div class="h3">Delete all policy data</div><div class="muted">Removes every policy, contact log, import record, scenario and custom field definition. Users, rules, lists and settings stay.</div>';
    echo '<form method="post" class="actionsRow">' . csrf_field() . '<input type="hidden" name="action" value="data_wipe"><input class="input" name="confirm" maxlength="10" placeholder="Type DELETE" aria-label="Type DELETE to confirm" autocomplete="off"><button class="btnGhost btnSm dangerBtn" type="submit">Delete all policy data</button></form>';
    echo '</div>';
}

function handle_retention_action(PDO $pdo, array $user, string $action): void
{
    if ($action === 'retention_run') {
        $out = run_retention($pdo, true);
        audit($pdo, (int)$user['id'], 'retention.manual', $out);
        flash('success', 'Retention ran: ' . $out['uploads'] . ' upload(s), ' . $out['audit'] . ' audit event(s), ' . $out['contacts'] . ' contact entr' . ($out['contacts'] === 1 ? 'y' : 'ies') . ', ' . $out['policies'] . ' polic' . ($out['policies'] === 1 ? 'y' : 'ies') . ' deleted.');
    } elseif ($action === 'privacy_erase') {
        $id = trim((string)($_POST['identifier'] ?? ''));
        if ($id === '') {
            redirect_to(['tab' => 'settings']);
        }
        $email = normalize_email($id);
        $phone = strlen(phone_key($id)) >= 7 && preg_match('/^[\d\s()+.\-]+$/', $id) ? phone_key($id) : '';
        $ids = [];
        $offset = 0;
        while (true) {
            $st = $pdo->prepare('SELECT id, policy_number, email, phone, household_id FROM policyholders WHERE id > ? ORDER BY id ASC LIMIT 1000');
            $st->execute([$offset]);
            $rows = $st->fetchAll();
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                $offset = (int)$r['id'];
                if ((string)$r['policy_number'] === $id || (string)($r['household_id'] ?? '') === $id
                    || ($email !== '' && strtolower((string)($r['email'] ?? '')) === $email)
                    || ($phone !== '' && (string)($r['phone'] ?? '') !== '' && phone_key((string)$r['phone']) === $phone)) {
                    $ids[] = (int)$r['id'];
                }
            }
        }
        if ($ids) {
            $pdo->beginTransaction();
            foreach (array_chunk($ids, 400) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $pdo->prepare('DELETE FROM contact_log WHERE policyholder_id IN (' . $in . ')')->execute($chunk);
                $pdo->prepare('DELETE FROM policyholders WHERE id IN (' . $in . ')')->execute($chunk);
            }
            $pdo->commit();
            foreach ($ids as $pid) {
                unset($_SESSION['revealed'][$pid]);
            }
        }
        audit($pdo, (int)$user['id'], 'privacy.erase', ['records' => count($ids), 'identifier_sha256' => substr(hash('sha256', strtolower($id)), 0, 16)]);
        flash($ids ? 'success' : 'info', $ids ? ('Erased ' . count($ids) . ' record(s) and their contact history.') : 'No records matched.');
    } elseif ($action === 'data_wipe') {
        if (trim((string)($_POST['confirm'] ?? '')) !== 'DELETE') {
            flash('error', 'Type DELETE to confirm.');
            redirect_to(['tab' => 'settings']);
        }
        $n = (int)$pdo->query('SELECT COUNT(*) FROM policyholders')->fetchColumn();
        $pdo->beginTransaction();
        foreach (['contact_log', 'policyholders', 'imports', 'campaigns', 'custom_field_defs'] as $t) {
            $pdo->exec('DELETE FROM ' . $t);
        }
        foreach ($pdo->query('SELECT id, rule_ids FROM segments')->fetchAll() as $seg) {
            $c = safe_json((string)$seg['rule_ids']);
            if (is_array($c)) {
                unset($c['stats']);
                $pdo->prepare('UPDATE segments SET rule_ids = ? WHERE id = ?')->execute([json_encode($c, JSON_UNESCAPED_SLASHES), (int)$seg['id']]);
            }
        }
        $pdo->commit();
        foreach (glob(upload_dir() . DIRECTORY_SEPARATOR . '*.csv') ?: [] as $f) {
            @unlink($f);
        }
        $_SESSION['import_stage'] = null;
        $_SESSION['revealed'] = [];
        audit($pdo, (int)$user['id'], 'privacy.wipe_all', ['policies' => $n]);
        flash('success', 'All policy data deleted (' . $n . ' policies).');
    }
    redirect_to(['tab' => 'settings']);
}

/* ---------- demo parity: score the sample book exactly as an import would (CLI only, no database) ---------- */

function parity_vectors(string $today = ''): array
{
    if ($today !== '') {
        $GLOBALS['LI_SAMPLE_TODAY'] = $today;
    }
    $csv = sample_csv();
    $tmp = tempnam(sys_get_temp_dir(), 'li_parity_');
    file_put_contents($tmp, $csv);
    $fh = fopen($tmp, 'rb');
    $headers = array_map(fn($x) => trim(strip_bom((string)$x)), (array)fgetcsv($fh));
    fclose($fh);
    $mapping = guess_import_mapping($headers);
    $analysis = analyze_csv($tmp, $headers, $mapping);
    $customDefs = (array)($analysis['custom_fields'] ?? []);
    $households = csv_household_counts($tmp, $mapping);
    $rules = [];
    foreach (sample_rules_seed() as $i => $r) {
        $def = (array)$r['definition'];
        $rules[] = ['id' => $i + 1, 'name' => (string)$r['name'], 'priority' => (int)$r['priority'], 'conditions' => ['logic' => (string)($def['logic'] ?? 'AND'), 'conditions' => (array)($def['conditions'] ?? [])], 'actions' => (array)($def['actions'] ?? [])];
    }
    usort($rules, fn($a, $b) => [$b['priority'], $b['id']] <=> [$a['priority'], $a['id']]);
    $out = [];
    $fh = fopen($tmp, 'rb');
    fgetcsv($fh);
    $n = 0;
    while (($row = fgetcsv($fh)) !== false) {
        $n++;
        $imputed = [];
        $v = normalize_and_impute(map_row($headers, $row, $mapping), [], $analysis, $imputed, $n, 'liteinsurance_sample.csv');
        $v['custom'] = extract_custom_fields($headers, $row, $customDefs);
        if ((string)$v['household_id'] !== '') {
            $v['policies_in_household'] = (int)($households[(string)$v['household_id']] ?? 1);
        }
        $s = score_policy_record($v, $imputed, $rules);
        $out[] = ['policy_number' => $v['policy_number'], 'risk_tier' => $s['risk_tier'], 'ltv_estimate' => $s['ltv'], 'confidence_score' => $s['confidence'], 'rule_hits' => $s['rule_hits'], 'tags' => $s['tags'], 'imputed_fields' => array_values($imputed), 'expiration_date' => $v['expiration_date'], 'policy_type' => $v['policy_type'], 'region' => $v['region'], 'premium_amount' => $v['premium_amount']];
    }
    fclose($fh);
    @unlink($tmp);
    return ['today' => $today !== '' ? $today : gmdate('Y-m-d'), 'mapping' => $mapping, 'policies' => $out];
}

/*
Summary:
- Risk tiers: rules run highest priority first; the first match that sets a tier wins, tags/score deltas accumulate, `stop` ends evaluation. Hits are stored so the Book panel can explain every tier.
- Renewals: expiration dates drive Today/Renewals; cadence 120/90/75/45/30/15 days (Settings). Stage, owner and contact log live on each policy.
- Consent: per-channel consent + opt-out; list exports and campaign reach only count consented, not-opted-out records.
- Re-import: matching Record IDs are updated in place (premium change kept as prior_premium); workflow fields are preserved.
- Access: optional TOTP two-step sign-in with recovery codes; admins can require it, reset it, and open/close self-registration (closed by default).
- Privacy: retention rules run with cron (uploads, audit log, contact history, expired policies); erase-a-person; full wipe; fonts self-hosted so the CSP is 'self' only.
- Demo parity: parity_vectors (CLI) scores the sample book without a database; demo.html's engine must match it (test/demo-parity.test.mjs).
- Missing data: numeric mean + categorical mode imputation; imputed fields tracked in metadata.
- Confidence score: starts at 100, reduced for imputed/missing optional fields, boosted by rule hits.
- LTV: premium * remaining_months * cross_sell_multiplier(policy_type) * retention_adjustment.
- Segments ("Lists"): plain-language builder compiles to the validated WHERE grammar (days_to_expiry, policies_in_household, consent fields, …) + optional rule IDs.
- Campaign simulator: segment_size * rate * avg_offer_value * (1+lift) minus contact costs.
*/

