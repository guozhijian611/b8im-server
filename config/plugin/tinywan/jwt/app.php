<?php

$jwtSecret = static function (string $environmentKey, string $purpose): string {
    $secret = (string) env($environmentKey, '');
    if ($secret !== '') {
        return $secret;
    }

    $debug = filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL);
    if ($debug) {
        // 仅供本机开发；不把可共享的固定密钥提交到 Git。
        return hash('sha256', base_path() . '|' . php_uname('n') . '|' . $purpose);
    }

    throw new RuntimeException($environmentKey . ' must be configured when APP_DEBUG=false');
};

return [
    'enable' => true,
    'jwt' => [
        /** 算法类型 HS256、HS384、HS512、RS256、RS384、RS512、ES256、ES384、ES512、PS256、PS384、PS512 */
        'algorithms' => 'HS256',

        /** access令牌秘钥（安装时自动生成64位随机值） */
        'access_secret_key' => $jwtSecret('JWT_ACCESS_SECRET_KEY', 'access'),

        /** access令牌过期时间，单位：秒。默认 2 小时 */
        'access_exp' => 7200,

        /** refresh令牌秘钥（安装时自动生成64位随机值） */
        'refresh_secret_key' => $jwtSecret('JWT_REFRESH_SECRET_KEY', 'refresh'),

        /** refresh令牌过期时间，单位：秒。默认 7 天 */
        'refresh_exp' => 604800,

        /** refresh 令牌是否禁用，默认不禁用 false */
        'refresh_disable' => false,

        /** 令牌签发者 */
        'iss' => env('DEPLOYMENT_ID', 'b8im-local'),

        /** 某个时间点后才能访问，单位秒。（如：30 表示当前时间30秒后才能使用） */
        'nbf' => 0,

        /** 时钟偏差冗余时间，单位秒。建议这个余地应该不大于几分钟 */
        'leeway' => 60,

        /** 是否允许单设备登录，默认不允许 false */
        'is_single_device' => false,

        /** 缓存令牌时间，单位：秒。默认 7 天 */
        'cache_token_ttl' => 604800,

        /** 缓存令牌前缀，默认 JWT:TOKEN: */
        'cache_token_pre' => 'JWT:TOKEN:',

        /** 缓存刷新令牌前缀，默认 JWT:REFRESH_TOKEN: */
        'cache_refresh_token_pre' => 'JWT:REFRESH_TOKEN:',

        /** 用户信息模型 */
        'user_model' => function ($uid) {
            return [];
        },

        /** 是否支持 get 请求获取令牌 */
        'is_support_get_token' => false,
        /** GET 请求获取令牌请求key */
        'is_support_get_token_key' => 'authorization',

        /** access令牌私钥 */
        'access_private_key' => <<<EOD
-----BEGIN RSA PRIVATE KEY-----
...
-----END RSA PRIVATE KEY-----
EOD,

        /** access令牌公钥 */
        'access_public_key' => <<<EOD
-----BEGIN PUBLIC KEY-----
...
-----END PUBLIC KEY-----
EOD,

        /** refresh令牌私钥 */
        'refresh_private_key' => <<<EOD
-----BEGIN RSA PRIVATE KEY-----
...
-----END RSA PRIVATE KEY-----
EOD,

        /** refresh令牌公钥 */
        'refresh_public_key' => <<<EOD
-----BEGIN PUBLIC KEY-----
...
-----END PUBLIC KEY-----
EOD,
    ],
];
