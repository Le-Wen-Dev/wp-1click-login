<?php
declare(strict_types=1);

namespace WP1C;

final class Logger
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function log(string $action, array $context = []): void
    {
        $record = array_merge([
            'timestamp' => gmdate('c'),
            'action' => $action,
        ], $context);

        $json = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $handle = @fopen($this->path, 'ab');
        if ($handle === false) {
            return;
        }

        @chmod($this->path, 0600);
        if (flock($handle, LOCK_EX)) {
            fwrite($handle, $json . PHP_EOL);
            fflush($handle);
            flock($handle, LOCK_UN);
        }

        fclose($handle);
    }
}
