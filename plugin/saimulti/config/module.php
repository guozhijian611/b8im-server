<?php

$moduleSdkRoot = \Composer\InstalledVersions::getInstallPath('b8im/module-sdk');
if (!is_string($moduleSdkRoot) || $moduleSdkRoot === '') {
    throw new RuntimeException('Composer 未安装 b8im/module-sdk，无法发现内置模块。');
}

$announcementModuleRoot = rtrim($moduleSdkRoot, DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'examples'
    . DIRECTORY_SEPARATOR . 'announcement';
if (!is_file($announcementModuleRoot . DIRECTORY_SEPARATOR . 'module.json')) {
    throw new RuntimeException('b8im/module-sdk 安装包缺少 examples/announcement/module.json。');
}

$i18nModuleRoot = null;
$i18nInstallPath = \Composer\InstalledVersions::isInstalled('b8im/module-i18n')
    ? \Composer\InstalledVersions::getInstallPath('b8im/module-i18n')
    : false;
if (is_string($i18nInstallPath) && $i18nInstallPath !== '') {
    $candidate = rtrim($i18nInstallPath, DIRECTORY_SEPARATOR);
    if (is_file($candidate . DIRECTORY_SEPARATOR . 'module.json')) {
        $i18nModuleRoot = $candidate;
    }
}
if ($i18nModuleRoot === null) {
    $workspaceSibling = dirname(base_path()) . DIRECTORY_SEPARATOR . 'b8im-module-i18n';
    if (is_file($workspaceSibling . DIRECTORY_SEPARATOR . 'module.json')) {
        $i18nModuleRoot = $workspaceSibling;
    }
}

$manifestRoots = [$announcementModuleRoot];
if (is_string($i18nModuleRoot) && $i18nModuleRoot !== '') {
    $manifestRoots[] = $i18nModuleRoot;
}

return [
    'system_version' => env('B8IM_SYSTEM_VERSION', '0.1.0'),
    'manifest_roots' => $manifestRoots,
    'server_module_paths' => (string) env('SERVER_MODULE_PATHS', ''),
    'migration_environment' => env('MODULE_MIGRATION_ENVIRONMENT', 'default'),
    'lifecycle_lock_ttl_seconds' => max(600, (int) env('MODULE_LIFECYCLE_LOCK_TTL_SECONDS', 900)),
    'access_cache_ttl_seconds' => max(1, min(300, (int) env('MODULE_ACCESS_CACHE_TTL_SECONDS', 60))),
    'config_encryption_key' => (string) env('MODULE_CONFIG_ENCRYPTION_KEY', ''),
    'expiry_scan_interval_seconds' => max(10, (int) env('MODULE_EXPIRY_SCAN_INTERVAL_SECONDS', 60)),
    'expiry_scan_batch_size' => max(1, min(1000, (int) env('MODULE_EXPIRY_SCAN_BATCH_SIZE', 200))),
    'expiry_lock_ttl_seconds' => max(10, (int) env('MODULE_EXPIRY_LOCK_TTL_SECONDS', 55)),
];
