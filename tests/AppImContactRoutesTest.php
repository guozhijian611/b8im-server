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
use plugin\saimulti\service\web\WebImControlService;
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
    'POST /saimulti/app/im/createGroup' => 'createGroup',
    'GET /saimulti/app/im/groupMembers' => 'groupMembers',
    'POST /saimulti/app/im/addGroupMembers' => 'addGroupMembers',
    'POST /saimulti/app/im/removeGroupMember' => 'removeGroupMember',
    'POST /saimulti/app/im/leaveGroup' => 'leaveGroup',
    'POST /saimulti/app/im/suspendGroupMember' => 'suspendGroupMember',
    'POST /saimulti/app/im/restoreGroupMember' => 'restoreGroupMember',
    'POST /saimulti/app/im/revokeGroupMemberHistory' => 'revokeGroupMemberHistory',
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

$parameterNames = static fn (string $method): array => array_map(
    static fn (ReflectionParameter $parameter): string => $parameter->getName(),
    (new ReflectionMethod(WebImControlService::class, $method))->getParameters(),
);
$assert(
    $parameterNames('sendFriendRequest')
        === ['identity', 'toOrganization', 'toUserId', 'message'],
    'Friend request contract must require the target organization.',
);
$assert(
    $parameterNames('messages')
        === [
            'identity',
            'conversationId',
            'peerOrganization',
            'peerUserId',
            'afterSeq',
            'beforeSeq',
            'limit',
        ],
    'Message history peer lookup must use a composite identity.',
);
$assert(
    $parameterNames('updateFriendRemark')
        === ['identity', 'friendOrganization', 'friendUserId', 'remark'],
    'Friend remark contract must use a composite identity.',
);

$controlReflection = new ReflectionClass(WebImControlService::class);
$controlWithoutDependencies = $controlReflection->newInstanceWithoutConstructor();
$identifierMethod = $controlReflection->getMethod('identifier');
$optionalIdentifierMethod = $controlReflection->getMethod('optionalIdentifier');
$assert(
    $identifierMethod->invoke($controlWithoutDependencies, 'User_1', 'user_id', 64) === 'User_1',
    'Canonical identifier bytes changed during validation.',
);
foreach ([" User_1", "User_1 ", "User\0_1", 'User|1'] as $invalidIdentifier) {
    $expectApiCode(422, static fn () => $identifierMethod->invoke(
        $controlWithoutDependencies,
        $invalidIdentifier,
        'user_id',
        64,
    ));
}
$expectApiCode(422, static fn () => $optionalIdentifierMethod->invoke(
    $controlWithoutDependencies,
    ' ',
    'conversation_id',
    64,
));

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
