<?php
declare(strict_types=1);

$_GET = [];
$queryString = (string) getenv('QUERY_STRING');
if ($queryString !== '') {
    parse_str(html_entity_decode($queryString), $getArray);
    foreach ($getArray as $key => $value) {
        $_GET[urldecode((string) $key)] = is_string($value) ? urldecode($value) : $value;
    }
}

$_POST = [];
$postString = (string) getenv('POST');
if ($postString !== '') {
    parse_str(html_entity_decode($postString), $postArray);
    foreach ($postArray as $key => $value) {
        $_POST[urldecode((string) $key)] = is_string($value) ? urldecode($value) : $value;
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'WP1C\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
