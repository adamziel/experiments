<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'WpSync\\';
    if (!str_starts_with($class, $prefix)) return;
    $rel = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
