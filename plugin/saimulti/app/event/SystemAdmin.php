<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\event;

use ReflectionClass;
use ReflectionMethod;
use plugin\saimulti\service\Permission;
use plugin\saimulti\app\model\tool\OperLog;
use plugin\saimulti\app\model\tool\LoginLog;

class SystemAdmin
{
    /**
     * 登录日志
     * @param $item
     */
    public function login($item)
    {
        $ip = request()->getRealIp();
        $http_user_agent = request()->header('user-agent');
        $data['username'] = $item['username'];
        $data['organization'] = 0;
        $data['ip'] = $ip;
        $data['ip_location'] = self::getIpLocation($ip);
        $data['os'] = self::getOs($http_user_agent);
        $data['browser'] = self::getBrowser($http_user_agent);
        $data['status'] = $item['status'];
        $data['message'] = $item['message'];
        $data['login_time'] = date('Y-m-d H:i:s');
        LoginLog::create($data);
    }

    /**
     * 记录操作日志
     */
    public function operateLog($flag): bool
    {
        if (request()->method() === 'GET') {
            return false;
        }
        $info = getAdminInfo();
        $ip = request()->getRealIp();
        $module = request()->plugin;
        $rule = trim(strtolower(request()->uri()));
        $data['username'] = $info['username'];
        $data['organization'] = 0;
        $data['method'] = request()->method();
        $data['router'] = $rule;
        $data['service_name'] = self::getServiceName();
        $data['app'] = $module;
        $data['ip'] = $ip;
        $data['ip_location'] = self::getIpLocation($ip);
        $data['request_data'] = $this->filterParams(request()->all());
        OperLog::create($data);
        return true;
    }

    /**
     * 获取业务名称
     */
    protected function getServiceName(): string
    {
        $request = request();
        if (!$request) {
            return '未命名业务';
        }
        $controller = $request->controller;
        $action = $request->action;
        $data = [];
        if (method_exists($controller, $action)) {
            $refMethod = new ReflectionMethod($controller, $action);
            $attributes = $refMethod->getAttributes(Permission::class);
            if (!empty($attributes)) {
                $attr = $attributes[0]->newInstance();
                $data = [
                    'title' => $attr->getTitle(),
                    'slug'  => $attr->getSlug(),
                ];
            }
        }
        if (!empty($data)) {
            return $data['title'] ?? '未命名业务';
        } else {
            return '未命名业务';
        }
    }

    /**
     * 过滤字段
     */
    protected function filterParams($params): string
    {
        $blackList = ['password', 'oldPassword', 'newPassword', 'content'];
        foreach ($params as $key => $value) {
            if (in_array($key, $blackList)) {
                $params[$key] = '******';
            }
        }
        return json_encode($params, JSON_UNESCAPED_UNICODE);
    }

    protected function getIpLocation($ip)
    {
        $ip2region = new \Ip2Region();
        try {
            $region = $ip2region->memorySearch($ip);
        } catch (\Exception $e) {
            return '未知';
        }
        list($country, $province, $city, $network) = explode('|', $region['region']);
        if ($network === '内网IP') {
            return $network;
        }
        if ($country == '中国') {
            return $province.'-'.$city.':'.$network;
        } else if ($country == '0') {
            return '未知';
        } else {
            return $country;
        }
    }

    protected function getBrowser($user_agent): string
    {
        $br = 'Unknown';
        if (preg_match('/MSIE/i', $user_agent)) {
            $br = 'MSIE';
        } elseif (preg_match('/Firefox/i', $user_agent)) {
            $br = 'Firefox';
        } elseif (preg_match('/Chrome/i', $user_agent)) {
            $br = 'Chrome';
        } elseif (preg_match('/Safari/i', $user_agent)) {
            $br = 'Safari';
        } elseif (preg_match('/Opera/i', $user_agent)) {
            $br = 'Opera';
        } else {
            $br = 'Other';
        }
        return $br;
    }

    protected function getOs($user_agent): string
    {
        $os = 'Unknown';
        if (preg_match('/win/i', $user_agent)) {
            $os = 'Windows';
        } elseif (preg_match('/mac/i', $user_agent)) {
            $os = 'Mac';
        } elseif (preg_match('/linux/i', $user_agent)) {
            $os = 'Linux';
        } else {
            $os = 'Other';
        }
        return $os;
    }

}