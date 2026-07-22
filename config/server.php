<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

$expectedMaxPackageSize = 2148532224;
$configuredMaxPackageSize = env('WEBMAN_MAX_PACKAGE_SIZE_BYTES');
if ($configuredMaxPackageSize === null) {
    $maxPackageSize = $expectedMaxPackageSize;
} elseif (($configuredMaxPackageSize !== $expectedMaxPackageSize)
    && ($configuredMaxPackageSize !== (string) $expectedMaxPackageSize)) {
    throw new RuntimeException(
        'WEBMAN_MAX_PACKAGE_SIZE_BYTES must equal canonical value 2148532224.',
    );
} else {
    $maxPackageSize = $expectedMaxPackageSize;
}

return [
    'event_loop' => '',
    'stop_timeout' => 2,
    'pid_file' => runtime_path() . '/webman.pid',
    'status_file' => runtime_path() . '/webman.status',
    'stdout_file' => runtime_path() . '/logs/stdout.log',
    'log_file' => runtime_path() . '/logs/workerman.log',
    'max_package_size' => $maxPackageSize
];
