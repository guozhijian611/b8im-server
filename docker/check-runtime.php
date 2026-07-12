<?php

declare(strict_types=1);

$errors = [];

if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
    $errors[] = sprintf('PHP 8.3.x is required, current version is %s', PHP_VERSION);
}

$requiredExtensions = [
    'curl',
    'dom',
    'fileinfo',
    'gd',
    'mbstring',
    'openssl',
    'pcntl',
    'pdo',
    'pdo_mysql',
    'posix',
    'redis',
    'simplexml',
    'sockets',
    'xml',
    'xmlreader',
    'zip',
];

foreach ($requiredExtensions as $extension) {
    if (!extension_loaded($extension)) {
        $errors[] = sprintf('Required PHP extension is missing: %s', $extension);
    }
}

// https://www.workerman.net/doc/webman/others/disable-function-check.html
$requiredFunctions = [
    'stream_socket_server',
    'stream_socket_client',
    'pcntl_signal_dispatch',
    'pcntl_signal',
    'pcntl_alarm',
    'pcntl_fork',
    'pcntl_wait',
    'posix_getuid',
    'posix_getpwuid',
    'posix_kill',
    'posix_setsid',
    'posix_getpid',
    'posix_getpwnam',
    'posix_getgrnam',
    'posix_getgid',
    'posix_setgid',
    'posix_initgroups',
    'posix_setuid',
    'posix_isatty',
    'proc_open',
    'proc_get_status',
    'proc_close',
    'shell_exec',
    'exec',
    'putenv',
    'getenv',
];

foreach ($requiredFunctions as $function) {
    if (!function_exists($function)) {
        $errors[] = sprintf(
            'Required function is unavailable or disabled: %s (disable_functions=%s)',
            $function,
            (string) ini_get('disable_functions'),
        );
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

printf(
    "PHP runtime check passed: version=%s extensions=%d required_functions=%d disable_functions=%s\n",
    PHP_VERSION,
    count($requiredExtensions),
    count($requiredFunctions),
    (string) ini_get('disable_functions'),
);
