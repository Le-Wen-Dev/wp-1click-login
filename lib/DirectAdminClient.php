<?php
declare(strict_types=1);

namespace WP1C;

use RuntimeException;

final class DirectAdminClient
{
    private string $username;
    private string $pluginRoot;
    private Logger $logger;
    private ?string $daBinary = null;
    private ?string $curlBinary = null;
    private ?string $sudoBinary = null;

    public function __construct(string $username, Logger $logger, ?string $pluginRoot = null)
    {
        $this->username = $username;
        $this->pluginRoot = $pluginRoot ?? dirname(__DIR__);
        $this->logger = $logger;
    }

    public function getDomains(): array
    {
        $domains = $this->getDomainsFromFilesystem();
        if ($domains !== []) {
            return $domains;
        }

        $domains = $this->getDomainsFromHelper();
        if ($domains !== []) {
            return $domains;
        }

        $response = $this->apiGet('/CMD_API_SHOW_DOMAINS');
        if (is_array($response) && isset($response['list']) && is_array($response['list'])) {
            return array_values(array_filter(array_map('strval', $response['list'])));
        }

        if (is_array($response) && isset($response['list[]'])) {
            $list = $response['list[]'];
            return is_array($list) ? array_values(array_map('strval', $list)) : [(string) $list];
        }

        return [];
    }

    public function createDatabase(string $database, string $dbUser, string $dbPassword): void
    {
        if ($this->helperAvailable()) {
            $this->createDatabaseViaHelper($database, $dbUser, $dbPassword);
            return;
        }

        $response = $this->apiPost('/CMD_API_DATABASES', [
            'action' => 'create',
            'name' => $database,
            'user' => $dbUser,
            'passwd' => $dbPassword,
            'passwd2' => $dbPassword,
        ]);

        if (($response['error'] ?? '0') !== '0') {
            $details = (string) ($response['details'] ?? $response['text'] ?? 'Unknown DirectAdmin error');
            throw new RuntimeException('DirectAdmin database creation failed: ' . $details);
        }
    }

    private function apiGet(string $path): array
    {
        return $this->runCurl($path, []);
    }

    private function apiPost(string $path, array $postFields): array
    {
        return $this->runCurl($path, $postFields);
    }

    private function runCurl(string $path, array $postFields): array
    {
        $daBinary = $this->resolveDaBinary();
        $baseUrl = trim((string) shell_exec(escapeshellarg($daBinary) . ' api-url --user=' . escapeshellarg($this->username) . ' 2>/dev/null'));
        if ($baseUrl === '') {
            throw new RuntimeException('Unable to create temporary DirectAdmin API login key for the current user.');
        }

        $cmd = [
            $this->resolveCurlBinary(),
            '-fsSk',
        ];

        if ($postFields !== []) {
            foreach ($postFields as $key => $value) {
                $cmd[] = '--data-urlencode';
                $cmd[] = $key . '=' . $value;
            }
        }

        $cmd[] = $baseUrl . $path;

        $parts = array_map('escapeshellarg', $cmd);
        $parts[] = '2>&1';

        exec(implode(' ', $parts), $output, $exitCode);
        $body = trim(implode("\n", $output));
        if ($exitCode !== 0 || $body === '') {
            throw new RuntimeException('DirectAdmin API request failed: ' . ($body !== '' ? $body : 'Empty response'));
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        parse_str(html_entity_decode($body), $parsed);
        if (is_array($parsed)) {
            return $parsed;
        }

        throw new RuntimeException('DirectAdmin API returned an unreadable response.');
    }

    private function getDomainsFromFilesystem(): array
    {
        $paths = [
            '/usr/local/directadmin/data/users/' . $this->username . '/domains.list',
            '/usr/local/directadmin/data/users/' . $this->username . '/domains/',
        ];

        foreach ($paths as $path) {
            if (is_file($path) && is_readable($path)) {
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $domains = array_values(array_filter(array_map('trim', $lines), static function (string $domain): bool {
                    return $domain !== '';
                }));
                if ($domains !== []) {
                    return $domains;
                }
            }
        }

        return [];
    }

    private function getDomainsFromHelper(): array
    {
        try {
            $body = $this->runHelper(['list-domains', $this->username]);
        } catch (RuntimeException $exception) {
            $this->logger->log('domain_helper_failed', [
                'username' => $this->username,
                'error' => $exception->getMessage(),
            ]);
            return [];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['domains']) || !is_array($decoded['domains'])) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded['domains'])));
    }

    private function createDatabaseViaHelper(string $database, string $dbUser, string $dbPassword): void
    {
        try {
            $body = $this->runHelper(['create-database', $this->username, $database, $dbUser, $dbPassword]);
        } catch (RuntimeException $exception) {
            $this->logger->log('db_helper_failed', [
                'username' => $this->username,
                'database' => $database,
                'error' => $exception->getMessage(),
            ]);
            throw new RuntimeException('DirectAdmin database helper failed: ' . $exception->getMessage());
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Database helper returned an unreadable response.');
        }

        if (($decoded['ok'] ?? false) !== true) {
            $message = (string) ($decoded['error'] ?? 'Unknown helper failure');
            throw new RuntimeException('DirectAdmin database helper failed: ' . $message);
        }
    }

    private function runHelper(array $arguments): string
    {
        $helper = $this->pluginRoot . '/scripts/da_helper.sh';
        if (!is_file($helper)) {
            throw new RuntimeException('DirectAdmin helper script is missing.');
        }

        $cmd = [$this->resolveSudoBinary(), '-n', $helper];
        foreach ($arguments as $argument) {
            $cmd[] = $argument;
        }

        $parts = array_map('escapeshellarg', $cmd);
        $parts[] = '2>&1';
        exec(implode(' ', $parts), $output, $exitCode);
        $body = trim(implode("\n", $output));
        if ($exitCode !== 0) {
            throw new RuntimeException($body !== '' ? $body : 'Helper exited with a non-zero status.');
        }

        return $body;
    }

    private function helperAvailable(): bool
    {
        return is_file($this->pluginRoot . '/scripts/da_helper.sh');
    }

    private function resolveDaBinary(): string
    {
        if ($this->daBinary !== null) {
            return $this->daBinary;
        }

        $candidates = [
            trim((string) shell_exec('command -v da 2>/dev/null')),
            '/usr/local/bin/da',
            '/usr/local/directadmin/directadmin',
            '/usr/local/directadmin/scripts/directadmin',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
                $this->daBinary = $candidate;
                return $candidate;
            }
        }

        throw new RuntimeException('DirectAdmin CLI binary was not found. Expected one of: da, /usr/local/bin/da, or /usr/local/directadmin/directadmin');
    }

    private function resolveCurlBinary(): string
    {
        if ($this->curlBinary !== null) {
            return $this->curlBinary;
        }

        $candidates = [
            trim((string) shell_exec('command -v curl 2>/dev/null')),
            '/usr/bin/curl',
            '/bin/curl',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
                $this->curlBinary = $candidate;
                return $candidate;
            }
        }

        throw new RuntimeException('curl binary was not found in the plugin runtime environment.');
    }

    private function resolveSudoBinary(): string
    {
        if ($this->sudoBinary !== null) {
            return $this->sudoBinary;
        }

        $candidates = [
            trim((string) shell_exec('command -v sudo 2>/dev/null')),
            '/usr/bin/sudo',
            '/bin/sudo',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
                $this->sudoBinary = $candidate;
                return $candidate;
            }
        }

        throw new RuntimeException('sudo binary was not found in the plugin runtime environment.');
    }
}
