<?php
declare(strict_types=1);

namespace WP1C;

use RuntimeException;

final class TokenManager
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function createOneTimeUrl(array $site): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + 60;
        $file = (string) ($site['token_file'] ?? '');
        $userId = (int) ($site['admin_user_id'] ?? 0);
        $siteUrl = (string) ($site['site_url'] ?? '');

        if ($file === '' || $userId < 1 || $siteUrl === '') {
            throw new RuntimeException('Installed site metadata is incomplete.');
        }

        $payload = [
            'owner' => (string) ($site['owner'] ?? ''),
            'domain' => (string) ($site['domain'] ?? ''),
            'site_id' => (string) ($site['site_id'] ?? ''),
            'user_id' => $userId,
            'log_file' => (string) ($site['log_file'] ?? ''),
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'used' => false,
            'created_at' => gmdate('c'),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode one-click token.');
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to prepare token directory.');
        }

        if (file_put_contents($file, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Failed to store login token.');
        }

        chmod($file, 0600);

        return rtrim($siteUrl, '/') . '/?da_oneclick_login=' . rawurlencode($token);
    }
}
