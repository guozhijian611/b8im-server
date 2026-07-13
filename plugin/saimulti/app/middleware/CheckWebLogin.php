<?php

declare(strict_types=1);

namespace plugin\saimulti\app\middleware;

use ReflectionClass;
use ReflectionMethod;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\ModuleServiceFactory;
use plugin\saimulti\service\WebOrganizationResolver;
use plugin\saimulti\service\WebTokenService;
use plugin\saimulti\service\web\WebImAccessSessionGuard;
use plugin\saimulti\service\web\WebImPolicyGuard;
use support\think\Db;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use OpenTelemetry\API\Trace\Span;
use plugin\saimulti\service\trace\Telemetry;

final class CheckWebLogin implements MiddlewareInterface
{
    public function __construct(
        private readonly ?WebImAccessSessionGuard $accessSessions = null,
        private readonly ?WebImPolicyGuard $policies = null,
    ) {
    }

    public function process(Request $request, callable $handler): Response
    {
        Telemetry::inSpan(
            'b8im.auth.web.session',
            'auth.web.session',
            ['b8im.auth.scope' => 'web'],
            fn () => $this->authenticate($request),
        );

        return $handler($request);
    }

    private function authenticate(Request $request): void
    {
        if ($request->method() === 'OPTIONS') {
            return;
        }

        $organization = (new WebOrganizationResolver())->fromRequest($request);
        $organizationId = (int) $organization['id'];
        Span::getCurrent()->setAttribute('b8im.organization', $organizationId);
        $deploymentId = (string) $organization['deployment_id'];
        $tokens = new WebTokenService();
        $claims = $tokens->verifyAccess(
            $tokens->extractBearer($request->header('Authorization')),
            $organizationId,
            $deploymentId,
        );
        ($this->accessSessions ?? new WebImAccessSessionGuard())->assertActive($claims, $organizationId);

        if (WebImPolicyGuard::appliesToPath((string) $request->path())) {
            ($this->policies ?? new WebImPolicyGuard())->assertWebAllowed($organizationId);
        }

        $user = Db::table('im_user')
            ->where('id', (int) $claims['id'])
            ->where('organization', $organizationId)
            ->where('user_id', (string) $claims['user_id'])
            ->where('status', 1)
            ->where('is_system', 2)
            ->whereNull('delete_time')
            ->find();
        if (!$user) {
            throw new ApiException('Web 用户已停用或不存在。', 401);
        }

        $identity = [
            'id' => (int) $user['id'],
            'organization' => $organizationId,
            'deployment_id' => $deploymentId,
            'user_id' => (string) $user['user_id'],
            'account' => (string) $user['account'],
            'nickname' => (string) $user['nickname'],
            'device_id' => (string) $claims['device_id'],
            'client_family' => 'web',
            'token_exp' => (int) $claims['exp'],
            'web_access_jti' => (string) $claims['jti'],
        ];
        $request->setHeader('check_saimulti_web', $identity);

        $required = $this->moduleRequired($request->controller, $request->action);
        if ($required !== null) {
            ModuleServiceFactory::access()->assertAvailable(
                $organizationId,
                $required->moduleKey(),
                $required->platform(),
                $required->capability(),
            );
        }

    }

    private function moduleRequired(string $controller, string $action): ?ModuleRequired
    {
        $class = new ReflectionClass($controller);
        if (method_exists($controller, $action)) {
            $attributes = (new ReflectionMethod($controller, $action))->getAttributes(ModuleRequired::class);
            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }
        }
        $attributes = $class->getAttributes(ModuleRequired::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
