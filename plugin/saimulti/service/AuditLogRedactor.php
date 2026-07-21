<?php

declare(strict_types=1);

namespace plugin\saimulti\service;

final class AuditLogRedactor
{
    public const REDACTED = '******';
    public const ENCODING_ERROR = '{"_audit_redaction_error":"encoding_failed"}';

    /** @param array<string|int, mixed> $params */
    public function encode(array $params): string
    {
        $json = json_encode(
            $this->redact($params),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return is_string($json) ? $json : self::ENCODING_ERROR;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string|int, mixed>
     */
    private function redact(array $params): array
    {
        if (
            array_key_exists('key', $params)
            && is_string($params['key'])
            && array_key_exists('value', $params)
            && $this->isSensitiveKey($params['key'])
        ) {
            $params['value'] = self::REDACTED;
        }

        foreach ($params as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $params[$key] = self::REDACTED;
                continue;
            }
            if (is_array($value)) {
                $params[$key] = $this->redact($value);
            }
        }

        return $params;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $key);
        $key = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key);
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($key), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $compact = implode('', $tokens);
        if (in_array($compact, [
            'content', 'authorization', 'proxyauthorization', 'cookie', 'setcookie',
            'apikey', 'privatekey', 'xapikey', 'clientsecret', 'signingsecret',
            'secretkey', 'passwordconfirm', 'passwordconfirmation', 'confirmpassword',
            'oldpassword', 'newpassword', 'passwordhash', 'passwordsalt',
            'accesstoken', 'refreshtoken', 'idtoken', 'authtoken', 'sessiontoken',
            'csrftoken', 'bearertoken',
        ], true)) {
            return true;
        }

        $last = $tokens[count($tokens) - 1] ?? '';
        if (in_array($last, [
            'password', 'passwd', 'pwd', 'token', 'authorization', 'cookie', 'secret',
        ], true)) {
            return true;
        }

        return $this->hasSensitivePair($tokens);
    }

    /** @param list<string> $tokens */
    private function hasSensitivePair(array $tokens): bool
    {
        $pairs = [
            'api:key', 'private:key', 'secret:key', 'access:key', 'secret:id', 's3:key',
            'client:secret', 'signing:secret',
            'access:token', 'refresh:token', 'id:token', 'auth:token',
            'session:token', 'csrf:token', 'bearer:token',
            'password:confirm', 'password:confirmation', 'confirm:password',
            'old:password', 'new:password', 'password:hash', 'password:salt',
        ];
        for ($index = 0, $last = count($tokens) - 1; $index < $last; $index++) {
            if (in_array($tokens[$index] . ':' . $tokens[$index + 1], $pairs, true)) {
                return true;
            }
        }

        return false;
    }
}
