<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/functions.php';
require_once dirname(__DIR__) . '/support/Request.php';
require_once dirname(__DIR__) . '/support/Response.php';
require_once dirname(__DIR__) . '/plugin/saimulti/app/functions.php';

use B8im\ModuleSdk\Manifest\ManifestLoader;
use plugin\saimulti\app\middleware\AppClientRequest;
use plugin\saimulti\app\middleware\ClientConfigRequest;
use plugin\saimulti\app\middleware\CheckWebLogin;
use plugin\saimulti\service\ModuleRequired;
use Webman\Route;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
};

$contracts = [
    'announcement' => ['module-sdk/examples/announcement/module.json', 'announcement.app.page', 'announcement.app.read', 'saimulti:app:announcement:index'],
    'customer_service' => ['module-customer-service/module.json', 'customer_service.app.page', 'customer_service.app.use', 'saimulti:app:customer_service:conversation'],
    'favorite' => ['module-favorite/module.json', 'favorite.app.page', 'favorite.app.manage', 'saimulti:app:favorite:index'],
    'file_media' => ['module-file-media/module.json', 'file_media.app.page', 'file_media.app.use', 'saimulti:app:file_media:use'],
    'i18n' => ['module-i18n/module.json', 'i18n.app.page', 'i18n.app.read', 'saimulti:app:i18n:read'],
    'moments' => ['module-moments/module.json', 'moments.app.page', 'moments.app.use', 'saimulti:app:moments:use'],
    'robot_single' => ['module-robot-single/module.json', 'robot_single.app.page', 'robot_single.app.use', 'saimulti:app:robot_single:use'],
    'search' => ['module-search/module.json', 'search.app.page', 'search.app.use', 'saimulti:app:search:use'],
    'sticker' => ['module-sticker/module.json', 'sticker.app.page', 'sticker.app.read', 'saimulti:app:sticker:read'],
];

Route::load([dirname(__DIR__) . '/plugin/saimulti/config']);
$registered = [];
foreach (Route::getRoutes() as $route) {
    foreach ($route->getMethods() as $method) {
        $registered[$method . ' ' . $route->getPath()][] = $route;
    }
}
$loader = new ManifestLoader();
$expectedMiddleware = [AppClientRequest::class, CheckWebLogin::class];
sort($expectedMiddleware);

foreach ($contracts as $moduleKey => [$relative, $pageCapability, $serverCapability, $permission]) {
    $manifest = $loader->load(dirname(__DIR__) . '/vendor/b8im/' . $relative);
    $assert($manifest->moduleKey() === $moduleKey, $moduleKey . ' manifest key mismatch.');
    $slugs = array_column($manifest->permissions(), 'slug');
    $assert(in_array($permission, $slugs, true), $moduleKey . ' App permission missing.');
    foreach (['android', 'ios'] as $platform) {
        $assert(in_array($platform, $manifest->platforms(), true), $moduleKey . ' ' . $platform . ' platform missing.');
        $assert(in_array($pageCapability, $manifest->capabilities()[$platform] ?? [], true), $moduleKey . ' ' . $platform . ' capability missing.');
        $menus = array_values(array_filter($manifest->menus(), static fn (array $menu): bool => $menu['platform'] === $platform && $menu['type'] === 'menu'));
        $assert(count($menus) === 1 && ($menus[0]['permission'] ?? null) === $permission, $moduleKey . ' ' . $platform . ' menu contract invalid.');
    }
    $assert(in_array($serverCapability, $manifest->capabilities()['server'] ?? [], true), $moduleKey . ' Server App capability missing.');

    $appRoutes = array_values(array_filter($manifest->routes(), static fn (array $route): bool => str_starts_with($route['path'], '/saimulti/app/')));
    $assert($appRoutes !== [], $moduleKey . ' App API routes missing.');
    foreach ($appRoutes as $contractRoute) {
        $assert(($contractRoute['capability'] ?? null) === $serverCapability, $contractRoute['path'] . ' capability mismatch.');
        foreach ($contractRoute['methods'] as $method) {
            $key = $method . ' ' . $contractRoute['path'];
            $assert(count($registered[$key] ?? []) === 1, $key . ' missing or duplicated.');
            $route = $registered[$key][0];
            $callback = $route->getCallback();
            $assert(is_array($callback) && str_starts_with($callback[0], 'plugin\\saimulti\\app\\controller\\app\\'), $key . ' must use an App controller.');
            $middleware = $route->getMiddleware();
            sort($middleware);
            $assert($middleware === $expectedMiddleware, $key . ' escaped App authentication middleware.');
            $attributes = (new ReflectionClass($callback[0]))->getAttributes(ModuleRequired::class);
            $required = $attributes[0]->newInstance();
            $assert($required->moduleKey() === $moduleKey && $required->platform() === 'server' && $required->capability() === $serverCapability, $key . ' ModuleRequired mismatch.');
        }
    }
}

$configRoutes = $registered['GET /saimulti/client/config'] ?? [];
$assert(count($configRoutes) === 1, 'Shared client config route must be registered exactly once.');
$configMiddleware = $configRoutes[0]->getMiddleware();
sort($configMiddleware);
$expectedConfigMiddleware = [ClientConfigRequest::class, CheckWebLogin::class];
sort($expectedConfigMiddleware);
$assert($configMiddleware === $expectedConfigMiddleware, 'Shared client config route middleware mismatch.');

fwrite(STDOUT, sprintf("Module App contract: %d assertions passed.\n", $assertions));
