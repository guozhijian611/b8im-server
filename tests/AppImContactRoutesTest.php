<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/functions.php';
require_once dirname(__DIR__) . '/support/Request.php';
require_once dirname(__DIR__) . '/support/Response.php';
require_once dirname(__DIR__) . '/plugin/saimulti/app/functions.php';

use plugin\saimulti\app\controller\web\ImController;
use plugin\saimulti\app\middleware\AppClientRequest;
use plugin\saimulti\app\middleware\CheckWebLogin;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\WebTokenService;
use Webman\Route;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
};
$expectApiCode = static function (int $code, callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, 'ApiException code mismatch.');
        return;
    }
    throw new RuntimeException('Expected ApiException was not thrown.');
};

Route::load([dirname(__DIR__) . '/plugin/saimulti/config']);
$routes = [];
foreach (Route::getRoutes() as $route) {
    foreach ($route->getMethods() as $method) {
        $routes[$method . ' ' . $route->getPath()][] = $route;
    }
}

$expectedRoutes = [
    'GET /saimulti/app/im/contacts' => 'contacts',
    'GET /saimulti/app/im/requests' => 'requests',
    'GET /saimulti/app/im/searchUsers' => 'searchUsers',
    'POST /saimulti/app/im/sendFriendRequest' => 'sendFriendRequest',
    'POST /saimulti/app/im/handleFriendRequest' => 'handleFriendRequest',
];
$expectedMiddleware = [AppClientRequest::class, CheckWebLogin::class];
sort($expectedMiddleware);

foreach ($expectedRoutes as $routeKey => $action) {
    $assert(count($routes[$routeKey] ?? []) === 1, $routeKey . ' route is missing or duplicated.');
    $route = $routes[$routeKey][0];
    $assert($route->getCallback() === [ImController::class, $action], $routeKey . ' must reuse ImController::' . $action . '.');
    $middleware = $route->getMiddleware();
    sort($middleware);
    $assert($middleware === $expectedMiddleware, $routeKey . ' escaped the App authentication middleware group.');

    [, $path] = explode(' ', $routeKey, 2);
    $optionsKey = 'OPTIONS ' . $path;
    $assert(count($routes[$optionsKey] ?? []) === 1, $optionsKey . ' route is missing or duplicated.');
    $optionsMiddleware = $routes[$optionsKey][0]->getMiddleware();
    sort($optionsMiddleware);
    $assert($optionsMiddleware === $expectedMiddleware, $optionsKey . ' escaped the App middleware group.');
}

$tokens = new WebTokenService(str_repeat('app-contact-route-secret-', 2), 'HS256');
$user = ['id' => 9, 'user_id' => 'app_user_9', 'account' => 'alice'];
$appToken = $tokens->issueAccess($user, 701, 'deployment-app', 'ios-device-9', 'app', 'ios');
$appClaims = $tokens->verifyAccess($appToken['access_token'], 701, 'deployment-app', 'app');
$assert(
    $appClaims['organization'] === 701 && $appClaims['client_family'] === 'app',
    'App access token lost its organization or client family boundary.',
);

$webToken = $tokens->issueAccess($user, 701, 'deployment-app', 'web-device-9', 'web', 'browser');
$expectApiCode(401, static fn () => $tokens->verifyAccess(
    $webToken['access_token'],
    701,
    'deployment-app',
    'app',
));
$expectApiCode(401, static fn () => $tokens->verifyAccess(
    $appToken['access_token'],
    702,
    'deployment-app',
    'app',
));
$expectApiCode(422, static fn () => $tokens->issueAccess(
    $user,
    701,
    'deployment-app',
    'invalid-device-9',
    'mobile',
    'ios',
));

fwrite(STDOUT, sprintf("App IM contact routes: %d assertions passed.\n", $assertions));
