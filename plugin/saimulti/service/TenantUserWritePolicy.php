<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | B8IM 租户管理员写入策略
// +----------------------------------------------------------------------
namespace plugin\saimulti\service;

/**
 * 租户管理员可写字段白名单。
 *
 * organization 和 user_type 绝不从客户端输入中继承。
 */
final class TenantUserWritePolicy
{
    private const COMMON_FIELDS = [
        'username',
        'nickname',
        'gender',
        'phone',
        'email',
        'avatar',
        'signed',
        'dashboard',
        'dept_id',
        'status',
        'remark',
        'role_ids',
        'post_ids',
    ];

    public static function forCreate(array $input): array
    {
        $data = self::only($input, array_merge(self::COMMON_FIELDS, ['password']));
        $data['user_type'] = '200';
        return $data;
    }

    public static function forUpdate(array $input): array
    {
        return self::only($input, array_merge(['id'], self::COMMON_FIELDS));
    }

    private static function only(array $input, array $fields): array
    {
        return array_intersect_key($input, array_flip($fields));
    }
}
