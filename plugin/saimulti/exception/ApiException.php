<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\exception;

use plugin\saimulti\app\middleware\HttpTrace;
use Webman\Http\Request;
use Webman\Http\Response;
use support\exception\BusinessException;

/**
 * 常规操作异常-只返回json数据,不记录异常日志
 */
class ApiException extends BusinessException
{
    public function render(Request $request): ?Response
    {
        $response = json([
            'code' => $this->getCode() ?: 500,
            'message' => $this->getMessage(),
            'type' => 'failed',
        ]);
        $traceId = $request->properties[HttpTrace::REQUEST_TRACE_ID] ?? null;

        return is_string($traceId) && preg_match('/^[0-9a-f]{32}$/', $traceId) === 1
            ? $response->withHeader('X-Trace-Id', $traceId)
            : $response;
    }
}
