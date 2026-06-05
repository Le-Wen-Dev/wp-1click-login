<?php
declare(strict_types=1);

namespace WP1C;

use RuntimeException;

final class DirectAdminClient
{
    private string $username;
    private Logger $logger;

    public function __construct(string $username, Logger $logger)
    {
        $this->username = $username;
        $this->logger = $logger;
    }

    public function getDomains(): array
    {
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
        $baseUrl = trim((string) shell_exec('da api-url --user=' . escapeshellarg($this->username) . ' 2>/dev/null'));
        if ($baseUrl === '') {
            throw new RuntimeException('Unable to create temporary DirectAdmin API login key for the current user.');
        }

        $cmd = [
            'curl',
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
}
