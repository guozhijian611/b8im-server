<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/functions.php';
require_once dirname(__DIR__) . '/support/Request.php';
require_once dirname(__DIR__) . '/support/Response.php';
require_once dirname(__DIR__) . '/plugin/saimulti/app/functions.php';

use plugin\saimulti\app\controller\tenant\TenantAccountPolicyController;
use plugin\saimulti\app\middleware\CheckTenantAuth;
use plugin\saimulti\app\middleware\CheckTenantLogin;
use plugin\saimulti\app\middleware\TenantLog;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\Permission;
use support\Request;
use Webman\Route;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) throw new RuntimeException($message);
    ++$assertions;
};
$expect422 = static function (callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === 422, 'Controller validation did not return 422.');
        return;
    }
    throw new RuntimeException('Controller validation unexpectedly passed.');
};

Route::load([dirname(__DIR__) . '/plugin/saimulti/config']);
$routes = [];
foreach (Route::getRoutes() as $route) {
    foreach ($route->getMethods() as $method) {
        $routes[$method . ' ' . $route->getPath()][] = $route;
    }
}
$expected = [
    'GET /saimulti/tenant/account/policy/read' => ['read', 'saimulti:tenant:account:policy:read'],
    'PUT /saimulti/tenant/account/policy/update' => ['update', 'saimulti:tenant:account:policy:update'],
];
$middleware = [CheckTenantLogin::class, CheckTenantAuth::class, TenantLog::class];
sort($middleware);
foreach ($expected as $key => [$action, $slug]) {
    $assert(count($routes[$key] ?? []) === 1, "{$key} is missing or duplicated.");
    $route = $routes[$key][0];
    $assert($route->getCallback() === [TenantAccountPolicyController::class, $action], "{$key} callback drifted.");
    $actualMiddleware = $route->getMiddleware();
    sort($actualMiddleware);
    $assert($actualMiddleware === $middleware, "{$key} middleware drifted.");
    $attributes = (new ReflectionMethod(TenantAccountPolicyController::class, $action))
        ->getAttributes(Permission::class);
    $permission = $attributes[0]->newInstance();
    $assert(count($attributes) === 1 && $permission->getSlug() === $slug, "{$key} permission drifted.");
}

$request = static function (string $body): Request {
    return new Request(
        "PUT / HTTP/1.1\r\nHost: localhost\r\n"
        . "Content-Type: application/x-www-form-urlencoded\r\n"
        . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body,
    );
};
$controller = (new ReflectionClass(TenantAccountPolicyController::class))->newInstanceWithoutConstructor();
$expect422(static fn () => $controller->update($request('register_enabled=1')));
$expect422(static fn () => $controller->update($request('register_enabled=not-bool&version=1')));

fwrite(STDOUT, sprintf("Tenant account policy routes: %d assertions passed.\n", $assertions));
