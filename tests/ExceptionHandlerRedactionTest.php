<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\app\exception\Handler;

$redacted = Handler::redact([
    'account' => 'alice',
    'password' => 'plain-password',
    'nested' => [
        'access_token' => 'token-value',
        'profile' => ['privateKey' => 'private-value', 'nickname' => 'Alice'],
    ],
]);
if (
    $redacted['account'] !== 'alice'
    || $redacted['password'] !== '******'
    || $redacted['nested']['access_token'] !== '******'
    || $redacted['nested']['profile']['privateKey'] !== '******'
    || $redacted['nested']['profile']['nickname'] !== 'Alice'
) {
    throw new RuntimeException('异常参数递归脱敏失败。');
}

echo "ExceptionHandlerRedactionTest passed\n";
