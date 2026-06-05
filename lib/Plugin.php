<?php
declare(strict_types=1);

namespace WP1C;

use RuntimeException;

final class Plugin
{
    private const PLUGIN_NAME = 'wp_oneclick_installer';

    private string $pluginRoot;
    private string $dataRoot;
    private string $userDataRoot;
    private string $username;
    private string $sessionId;
    private Logger $logger;
    private array $flash = [];

    public function __construct()
    {
        $this->pluginRoot = dirname(__DIR__);
        $this->dataRoot = $this->pluginRoot . '/data';
        $this->username = $this->requireEnv('USERNAME');
        $this->sessionId = (string) getenv('SESSION_ID');
        $this->userDataRoot = $this->dataRoot . '/users/' . $this->username;
        $this->ensureDirectory($this->userDataRoot, 0700);
        $this->ensureDirectory($this->userDataRoot . '/tokens', 0700);
        $this->ensureDirectory($this->userDataRoot . '/csrf', 0700);
        $this->ensureDirectory($this->userDataRoot . '/bin', 0700);
        $this->logger = new Logger($this->userDataRoot . '/activity.log');
    }

    public function handleIndex(): void
    {
        $defaultPassword = $this->generatePassword();
        $sites = $this->loadSites();
        $domains = [];

        try {
            $domains = $this->getDomains();
            if ($this->requestMethod() === 'POST') {
                $action = (string) ($_POST['action'] ?? '');
                if ($action === 'install') {
                    $defaultPassword = (string) ($_POST['admin_password'] ?? $defaultPassword);
                    $this->assertCsrfToken((string) ($_POST['csrf_token'] ?? ''));
                    $site = $this->installWordPress();
                    $sites = $this->loadSites();
                    $this->flash[] = [
                        'type' => 'success',
                        'message' => 'WordPress installed successfully.',
                        'site' => $site,
                    ];
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->log('install_failed', [
                'username' => $this->username,
                'error' => $exception->getMessage(),
            ]);
            $this->flash[] = [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ];
        }

        $csrfToken = $this->issueCsrfToken();
        $this->renderPage($domains, $sites, $defaultPassword, $csrfToken);
    }

    public function handleOneClick(): void
    {
        try {
            if ($this->requestMethod() !== 'POST') {
                $this->sendRawResponse(405, 'Method Not Allowed');
                return;
            }

            $this->assertCsrfToken((string) ($_POST['csrf_token'] ?? ''));
            $siteId = (string) ($_POST['site_id'] ?? '');
            $sites = $this->loadSites();
            if (!isset($sites[$siteId])) {
                throw new RuntimeException('Installed site was not found for this user.');
            }

            $site = $sites[$siteId];
            if (($site['owner'] ?? '') !== $this->username) {
                throw new RuntimeException('Cross-user access is not allowed.');
            }

            $tokenManager = new TokenManager($this->logger);
            $tokenUrl = $tokenManager->createOneTimeUrl($site);
            $this->logger->log('login_token_created', [
                'username' => $this->username,
                'domain' => $site['domain'],
                'site_id' => $site['site_id'],
            ]);

            echo "Status: 302 Found\r\n";
            echo 'Location: ' . $tokenUrl . "\r\n\r\n";
        } catch (\Throwable $exception) {
            $this->logger->log('login_token_failed', [
                'username' => $this->username,
                'error' => $exception->getMessage(),
            ]);
            $this->sendRawResponse(400, $exception->getMessage());
        }
    }

    private function installWordPress(): array
    {
        $domain = $this->validateDomain((string) ($_POST['domain'] ?? ''));
        $domains = $this->getDomains();
        if (!in_array($domain, $domains, true)) {
            throw new RuntimeException('Selected domain does not belong to the current DirectAdmin user.');
        }

        $installDirInput = (string) ($_POST['install_dir'] ?? 'public_html');
        $installDir = $this->validateInstallDir($installDirInput);
        $siteTitle = $this->validateSiteTitle((string) ($_POST['site_title'] ?? ''));
        $adminUser = $this->validateWpUsername((string) ($_POST['admin_username'] ?? ''));
        $adminEmail = $this->validateEmail((string) ($_POST['admin_email'] ?? ''));
        $adminPassword = $this->validatePassword((string) ($_POST['admin_password'] ?? ''));

        $domainRoot = '/home/' . $this->username . '/domains/' . $domain;
        if (!is_dir($domainRoot)) {
            throw new RuntimeException('Domain home directory was not found on disk.');
        }

        $installPath = $domainRoot . '/' . $installDir;
        $this->ensureInstallPathAllowed($domainRoot, $installPath);
        $this->prepareInstallDirectory($installPath);

        $dbPassword = $this->generatePassword(24);
        $dbNameSuffix = substr(bin2hex(random_bytes(6)), 0, 10);
        $dbBase = $this->username . '_wp' . $dbNameSuffix;
        $dbBase = substr(preg_replace('/[^a-z0-9_]/i', '', $dbBase) ?? '', 0, 48);
        if ($dbBase === '') {
            throw new RuntimeException('Failed to generate database name.');
        }

        $da = new DirectAdminClient($this->username, $this->logger);
        $da->createDatabase($dbBase, $dbBase, $dbPassword);

        $siteUrl = 'https://' . $domain . $this->relativeUrlFromInstallDir($installDir);
        $wpCli = $this->resolveWpCli();

        $this->runWpCli($wpCli, ['core', 'download', '--path=' . $installPath, '--force']);
        $this->runWpCli($wpCli, [
            'config',
            'create',
            '--path=' . $installPath,
            '--dbname=' . $dbBase,
            '--dbuser=' . $dbBase,
            '--dbpass=' . $dbPassword,
            '--dbhost=' . $this->getDbHost(),
            '--skip-check',
            '--force',
        ]);
        $this->runWpCli($wpCli, [
            'core',
            'install',
            '--path=' . $installPath,
            '--url=' . $siteUrl,
            '--title=' . $siteTitle,
            '--admin_user=' . $adminUser,
            '--admin_password=' . $adminPassword,
            '--admin_email=' . $adminEmail,
            '--skip-email',
        ]);

        $adminUserId = trim($this->runWpCli($wpCli, [
            'user',
            'get',
            $adminUser,
            '--path=' . $installPath,
            '--field=ID',
        ]));

        if (!ctype_digit($adminUserId)) {
            throw new RuntimeException('Failed to determine WordPress admin user ID after installation.');
        }

        $tokenFile = $this->userDataRoot . '/tokens/' . hash('sha256', $domain . '|' . $installPath) . '.json';
        $this->writeMuPlugin($installPath, $tokenFile);

        $siteId = hash('sha256', $domain . '|' . $installPath . '|' . $this->username);
        $site = [
            'site_id' => $siteId,
            'owner' => $this->username,
            'domain' => $domain,
            'install_dir' => $installDir,
            'install_path' => $installPath,
            'site_url' => $siteUrl,
            'wp_admin_url' => rtrim($siteUrl, '/') . '/wp-admin/',
            'db_name' => $dbBase,
            'db_user' => $dbBase,
            'admin_user' => $adminUser,
            'admin_email' => $adminEmail,
            'admin_user_id' => (int) $adminUserId,
            'token_file' => $tokenFile,
            'log_file' => $this->userDataRoot . '/activity.log',
            'created_at' => gmdate('c'),
        ];

        $sites = $this->loadSites();
        $sites[$siteId] = $site;
        $this->saveSites($sites);

        $this->logger->log('install', [
            'username' => $this->username,
            'domain' => $domain,
            'site_id' => $siteId,
            'install_path' => $installPath,
        ]);

        return $site;
    }

    private function renderPage(array $domains, array $sites, string $defaultPassword, string $csrfToken): void
    {
        $selectedDomain = $this->h((string) ($_POST['domain'] ?? ($domains[0] ?? '')));
        $installDir = $this->h((string) ($_POST['install_dir'] ?? 'public_html'));
        $siteTitle = $this->h((string) ($_POST['site_title'] ?? 'My WordPress Site'));
        $adminUser = $this->h((string) ($_POST['admin_username'] ?? $this->username . '_wpadmin'));
        $adminEmail = $this->h((string) ($_POST['admin_email'] ?? 'admin@' . ($domains[0] ?? 'example.com')));
        $defaultPassword = $this->h($defaultPassword);
        $pluginBase = '/CMD_PLUGINS/' . self::PLUGIN_NAME;

        echo '<!doctype html><html><head><meta charset="utf-8"><title>WP OneClick Installer</title>';
        echo '<style>';
        echo 'body{font-family:Arial,sans-serif;color:#0f172a;margin:24px;}';
        echo '.wrap{max-width:1100px;margin:0 auto;}';
        echo '.hero{background:linear-gradient(135deg,#dbeafe,#f8fafc);border:1px solid #bfdbfe;border-radius:16px;padding:24px;margin-bottom:24px;}';
        echo '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;}';
        echo '.card{background:#fff;border:1px solid #cbd5e1;border-radius:14px;padding:20px;box-shadow:0 8px 24px rgba(15,23,42,.06);}';
        echo 'label{display:block;font-weight:700;margin:0 0 6px;}';
        echo 'input,select{width:100%;padding:10px 12px;border:1px solid #94a3b8;border-radius:10px;margin-bottom:14px;box-sizing:border-box;}';
        echo 'button,.button{display:inline-block;background:#0f766e;color:#fff;border:none;border-radius:10px;padding:11px 16px;text-decoration:none;cursor:pointer;font-weight:700;}';
        echo '.button.secondary{background:#1d4ed8;}';
        echo '.notice{padding:14px 16px;border-radius:12px;margin:0 0 16px;}';
        echo '.notice.success{background:#dcfce7;border:1px solid #86efac;}';
        echo '.notice.error{background:#fee2e2;border:1px solid #fca5a5;}';
        echo 'table{width:100%;border-collapse:collapse;}';
        echo 'th,td{padding:12px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;}';
        echo 'code{background:#f1f5f9;padding:2px 6px;border-radius:6px;}';
        echo '.actions{display:flex;gap:8px;flex-wrap:wrap;}';
        echo '.muted{color:#475569;font-size:14px;}';
        echo '</style></head><body><div class="wrap">';
        echo '<div class="hero"><h1>WP OneClick Installer</h1>';
        echo '<p>Install WordPress quickly for your DirectAdmin domains and sign in with a short-lived One-click Login token.</p></div>';

        foreach ($this->flash as $item) {
            echo '<div class="notice ' . $this->h($item['type']) . '">';
            echo '<strong>' . $this->h($item['message']) . '</strong>';
            if (!empty($item['site'])) {
                echo '<div class="muted">Website: <a href="' . $this->h($item['site']['site_url']) . '">' . $this->h($item['site']['site_url']) . '</a></div>';
                echo '<div class="muted">wp-admin: <a href="' . $this->h($item['site']['wp_admin_url']) . '">' . $this->h($item['site']['wp_admin_url']) . '</a></div>';
            }
            echo '</div>';
        }

        echo '<div class="grid">';
        echo '<div class="card"><h2>Install WordPress</h2>';
        echo '<form method="post" action="' . $pluginBase . '/index.html">';
        echo '<input type="hidden" name="action" value="install">';
        echo '<input type="hidden" name="csrf_token" value="' . $this->h($csrfToken) . '">';

        echo '<label for="domain">Domain</label><select id="domain" name="domain">';
        foreach ($domains as $domain) {
            $escaped = $this->h($domain);
            $selected = $selectedDomain === $escaped ? ' selected' : '';
            echo '<option value="' . $escaped . '"' . $selected . '>' . $escaped . '</option>';
        }
        echo '</select>';

        echo '<label for="install_dir">Install Directory</label>';
        echo '<input id="install_dir" name="install_dir" value="' . $installDir . '" placeholder="public_html">';

        echo '<label for="site_title">Site Title</label>';
        echo '<input id="site_title" name="site_title" value="' . $siteTitle . '">';

        echo '<label for="admin_username">Admin Username</label>';
        echo '<input id="admin_username" name="admin_username" value="' . $adminUser . '">';

        echo '<label for="admin_email">Admin Email</label>';
        echo '<input id="admin_email" name="admin_email" type="email" value="' . $adminEmail . '">';

        echo '<label for="admin_password">Generated Password</label>';
        echo '<input id="admin_password" name="admin_password" value="' . $defaultPassword . '" autocomplete="new-password">';
        echo '<p class="muted">This password is generated for setup only and is not stored by the plugin.</p>';
        echo '<button type="submit">Install WordPress</button></form></div>';

        echo '<div class="card"><h2>Installed Sites</h2>';
        if ($sites === []) {
            echo '<p class="muted">No WordPress sites have been installed by this plugin yet.</p>';
        } else {
            echo '<table><thead><tr><th>Domain</th><th>Path</th><th>Links</th><th>Actions</th></tr></thead><tbody>';
            foreach ($sites as $site) {
                echo '<tr>';
                echo '<td>' . $this->h($site['domain']) . '<div class="muted">' . $this->h($site['admin_email']) . '</div></td>';
                echo '<td><code>' . $this->h($site['install_dir']) . '</code></td>';
                echo '<td><a href="' . $this->h($site['site_url']) . '">Website</a><br><a href="' . $this->h($site['wp_admin_url']) . '">wp-admin</a></td>';
                echo '<td><div class="actions">';
                echo '<form method="post" action="' . $pluginBase . '/oneclick.raw" style="margin:0;">';
                echo '<input type="hidden" name="csrf_token" value="' . $this->h($csrfToken) . '">';
                echo '<input type="hidden" name="site_id" value="' . $this->h($site['site_id']) . '">';
                echo '<button type="submit" class="button secondary">Login WordPress</button>';
                echo '</form></div></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div></div></div></body></html>';
    }

    private function getDomains(): array
    {
        $client = new DirectAdminClient($this->username, $this->logger);
        $domains = $client->getDomains();
        if ($domains === []) {
            $fallback = '/usr/local/directadmin/data/users/' . $this->username . '/domains.list';
            if (is_file($fallback)) {
                $domains = array_values(array_filter(array_map('trim', file($fallback, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])));
            }
        }

        if ($domains === []) {
            throw new RuntimeException('No DirectAdmin domains were found for the current user.');
        }

        sort($domains);
        return $domains;
    }

    private function loadSites(): array
    {
        $path = $this->userDataRoot . '/sites.json';
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function saveSites(array $sites): void
    {
        $path = $this->userDataRoot . '/sites.json';
        $payload = json_encode($sites, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('Failed to encode site metadata.');
        }

        $this->writeFileSecurely($path, $payload . PHP_EOL, 0600);
    }

    private function issueCsrfToken(): string
    {
        if ($this->sessionId === '') {
            return bin2hex(random_bytes(32));
        }

        $token = bin2hex(random_bytes(32));
        $record = [
            'hash' => hash('sha256', $token),
            'expires_at' => time() + 7200,
        ];
        $path = $this->userDataRoot . '/csrf/' . hash('sha256', $this->sessionId) . '.json';
        $this->writeFileSecurely($path, json_encode($record, JSON_PRETTY_PRINT) . PHP_EOL, 0600);

        return $token;
    }

    private function assertCsrfToken(string $token): void
    {
        if ($this->sessionId === '') {
            throw new RuntimeException('CSRF validation is unavailable because DirectAdmin session context is missing.');
        }

        $path = $this->userDataRoot . '/csrf/' . hash('sha256', $this->sessionId) . '.json';
        if (!is_file($path)) {
            throw new RuntimeException('CSRF token is missing. Please reload the plugin page and try again.');
        }

        $record = json_decode((string) file_get_contents($path), true);
        if (!is_array($record) || !isset($record['hash'], $record['expires_at'])) {
            throw new RuntimeException('CSRF token data is invalid.');
        }

        if ((int) $record['expires_at'] < time()) {
            @unlink($path);
            throw new RuntimeException('CSRF token has expired. Please reload the plugin page and try again.');
        }

        if (!hash_equals((string) $record['hash'], hash('sha256', $token))) {
            throw new RuntimeException('CSRF token verification failed.');
        }
    }

    private function validateDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if (!preg_match('/^(?=.{1,253}$)(?!-)[a-z0-9.-]+(?<!-)$/', $domain)) {
            throw new RuntimeException('Please select a valid domain.');
        }

        return $domain;
    }

    private function validateInstallDir(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            $path = 'public_html';
        }

        $path = preg_replace('#/+#', '/', $path) ?? '';
        $path = trim($path, '/');

        if ($path === '' || str_contains($path, '..')) {
            throw new RuntimeException('Install directory is invalid.');
        }

        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $part)) {
                throw new RuntimeException('Install directory contains unsupported characters.');
            }
        }

        if ($parts[0] !== 'public_html') {
            throw new RuntimeException('Install directory must stay inside public_html.');
        }

        return implode('/', $parts);
    }

    private function validateSiteTitle(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 200) {
            throw new RuntimeException('Site title is required and must be shorter than 200 characters.');
        }

        return $value;
    }

    private function validateWpUsername(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9_.-]{4,60}$/', $value)) {
            throw new RuntimeException('Admin username must be 4-60 characters using letters, numbers, dot, dash, or underscore.');
        }

        return $value;
    }

    private function validateEmail(string $value): string
    {
        $value = trim($value);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please provide a valid admin email address.');
        }

        return $value;
    }

    private function validatePassword(string $value): string
    {
        if (strlen($value) < 12) {
            throw new RuntimeException('Generated password must contain at least 12 characters.');
        }

        return $value;
    }

    private function prepareInstallDirectory(string $installPath): void
    {
        if (!is_dir($installPath) && !mkdir($installPath, 0755, true) && !is_dir($installPath)) {
            throw new RuntimeException('Failed to create installation directory.');
        }

        $entries = array_diff(scandir($installPath) ?: [], ['.', '..']);
        if ($entries !== []) {
            throw new RuntimeException('Installation directory is not empty.');
        }
    }

    private function ensureInstallPathAllowed(string $domainRoot, string $installPath): void
    {
        $normalizedRoot = rtrim($domainRoot, '/');
        $normalizedPath = preg_replace('#/+#', '/', $installPath) ?? $installPath;
        if (!str_starts_with($normalizedPath, $normalizedRoot . '/public_html')) {
            throw new RuntimeException('Installation path is outside the allowed public_html area.');
        }
    }

    private function relativeUrlFromInstallDir(string $installDir): string
    {
        $relative = preg_replace('#^public_html#', '', $installDir) ?? '';
        $relative = trim($relative, '/');
        return $relative === '' ? '/' : '/' . $relative . '/';
    }

    private function resolveWpCli(): array
    {
        $paths = [
            trim((string) shell_exec('command -v wp 2>/dev/null')),
            '/usr/local/bin/wp',
            '/usr/bin/wp',
        ];

        foreach ($paths as $path) {
            if ($path !== '' && is_executable($path)) {
                return [$path];
            }
        }

        $phar = $this->userDataRoot . '/bin/wp-cli.phar';
        if (!is_file($phar)) {
            $this->downloadFile('https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar', $phar);
            chmod($phar, 0700);
        }

        return ['php', $phar];
    }

    private function runWpCli(array $command, array $arguments): string
    {
        $cmd = [];
        foreach (array_merge($command, $arguments) as $part) {
            $cmd[] = escapeshellarg($part);
        }
        $cmd[] = '2>&1';
        exec(implode(' ', $cmd), $output, $exitCode);
        $body = trim(implode("\n", $output));
        if ($exitCode !== 0) {
            throw new RuntimeException('WP-CLI command failed: ' . ($body !== '' ? $body : 'Unknown error'));
        }

        return $body;
    }

    private function writeMuPlugin(string $installPath, string $tokenFile): void
    {
        $muDir = $installPath . '/wp-content/mu-plugins';
        if (!is_dir($muDir) && !mkdir($muDir, 0755, true) && !is_dir($muDir)) {
            throw new RuntimeException('Failed to create mu-plugins directory.');
        }

        $pluginPath = $muDir . '/da-oneclick-login.php';
        $code = <<<'PHP'
<?php
/**
 * Plugin Name: DA One-click Login
 * Description: Handles short-lived DirectAdmin login tokens.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', static function (): void {
    if (!isset($_GET['da_oneclick_login'])) {
        return;
    }

    $token = (string) $_GET['da_oneclick_login'];
    $tokenFile = '__TOKEN_FILE__';

    if ($token === '' || !is_file($tokenFile)) {
        status_header(403);
        exit('Forbidden');
    }

    $payload = json_decode((string) file_get_contents($tokenFile), true);
    if (!is_array($payload)) {
        da_oneclick_log('', 'login_failed', 'invalid_payload');
        @unlink($tokenFile);
        status_header(403);
        exit('Forbidden');
    }

    $expectedHash = (string) ($payload['token_hash'] ?? '');
    $expiresAt = (int) ($payload['expires_at'] ?? 0);
    $userId = (int) ($payload['user_id'] ?? 0);
    $used = (bool) ($payload['used'] ?? false);
    $owner = (string) ($payload['owner'] ?? '');
    $logFile = (string) ($payload['log_file'] ?? '');

    if ($owner === '' || $used || $expiresAt < time() || !hash_equals($expectedHash, hash('sha256', $token)) || $userId < 1) {
        da_oneclick_log($logFile, 'login_failed', 'token_invalid_or_expired');
        @unlink($tokenFile);
        status_header(403);
        exit('Forbidden');
    }

    @unlink($tokenFile);

    wp_set_current_user($userId);
    wp_set_auth_cookie($userId, false, is_ssl());

    if (function_exists('do_action')) {
        do_action('wp_login', wp_get_current_user()->user_login, wp_get_current_user());
    }

    da_oneclick_log($logFile, 'login_success', (string) $userId);
    wp_safe_redirect(admin_url());
    exit;
}, 1);

function da_oneclick_log(string $path, string $action, string $details): void
{
    if ($path === '') {
        return;
    }

    $record = wp_json_encode([
        'timestamp' => gmdate('c'),
        'action' => $action,
        'details' => $details,
    ]);

    if (!is_string($record)) {
        return;
    }

    $handle = @fopen($path, 'ab');
    if ($handle === false) {
        return;
    }

    @chmod($path, 0600);
    if (flock($handle, LOCK_EX)) {
        fwrite($handle, $record . PHP_EOL);
        fflush($handle);
        flock($handle, LOCK_UN);
    }

    fclose($handle);
}
PHP;

        $code = str_replace('__TOKEN_FILE__', addslashes($tokenFile), $code);
        $this->writeFileSecurely($pluginPath, $code . PHP_EOL, 0644);
    }

    private function getDbHost(): string
    {
        $host = trim((string) getenv('DA_WP_DB_HOST'));
        return $host !== '' ? $host : 'localhost';
    }

    private function requestMethod(): string
    {
        $method = (string) getenv('REQUEST_METHOD');
        return strtoupper($method !== '' ? $method : ($_POST === [] ? 'GET' : 'POST'));
    }

    private function sendRawResponse(int $statusCode, string $message): void
    {
        $statusText = $statusCode === 405 ? 'Method Not Allowed' : ($statusCode === 403 ? 'Forbidden' : 'Bad Request');
        echo 'Status: ' . $statusCode . ' ' . $statusText . "\r\n";
        echo "Content-Type: text/plain; charset=utf-8\r\n\r\n";
        echo $message;
    }

    private function downloadFile(string $url, string $destination): void
    {
        $context = stream_context_create([
            'http' => ['follow_location' => 1, 'timeout' => 60],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $contents = @file_get_contents($url, false, $context);
        if ($contents === false) {
            throw new RuntimeException('Failed to download required file: ' . $url);
        }

        $this->writeFileSecurely($destination, $contents, 0600);
    }

    private function generatePassword(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
        $max = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }

    private function writeFileSecurely(string $path, string $contents, int $mode): void
    {
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write file: ' . basename($path));
        }
        chmod($tmp, $mode);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to finalize file write: ' . basename($path));
        }
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create directory: ' . $path);
        }
        @chmod($path, $mode);
    }

    private function requireEnv(string $name): string
    {
        $value = (string) getenv($name);
        if ($value === '') {
            throw new RuntimeException('Missing required DirectAdmin environment variable: ' . $name);
        }

        return $value;
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
