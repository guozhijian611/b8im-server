<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\Manifest\Manifest;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Phinx\Util\Util;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ModuleMigrationRunner
{
    /** @return list<string> */
    public function migrationFiles(Manifest $manifest, string $manifestPath): array
    {
        $moduleRoot = realpath(dirname($manifestPath));
        if ($moduleRoot === false) {
            throw new RuntimeException(sprintf('manifest 目录不存在: %s', dirname($manifestPath)));
        }

        $files = [];
        foreach ($manifest->migrations() as $migration) {
            if ($migration['platform'] !== 'server') {
                continue;
            }

            $connection = $migration['connection'] ?? 'default';
            if ($connection !== 'default') {
                throw new RuntimeException(sprintf('模块迁移连接未受支持: %s', $connection));
            }

            $candidate = $moduleRoot . DIRECTORY_SEPARATOR . ltrim($migration['path'], '/\\');
            $real = realpath($candidate);
            if ($real === false || !$this->within($real, $moduleRoot)) {
                throw new RuntimeException(sprintf('模块迁移文件不存在或越界: %s', $migration['path']));
            }
            if (!is_file($real) || !Util::isValidMigrationFileName(basename($real))) {
                throw new RuntimeException(sprintf('模块迁移文件名不符合 Phinx 规范: %s', $real));
            }
            $files[] = $real;
        }

        $files = array_values(array_unique($files));
        $this->assertDirectoriesContainOnlyDeclaredMigrations($files);

        return $files;
    }

    public function migrate(Manifest $manifest, string $manifestPath): string
    {
        $files = $this->migrationFiles($manifest, $manifestPath);
        if ($files === []) {
            return '';
        }

        [$manager, $environment, $output] = $this->manager($files, $manifest->moduleKey());
        $manager->migrate($environment);

        return $output->fetch();
    }

    public function rollback(Manifest $manifest, string $manifestPath): string
    {
        $files = $this->migrationFiles($manifest, $manifestPath);
        if ($files === []) {
            return '';
        }

        [$manager, $environment, $output] = $this->manager($files, $manifest->moduleKey());
        $manager->rollback($environment, 0, true);

        return $output->fetch();
    }

    /**
     * @param list<string> $files
     * @return array{Manager, string, BufferedOutput}
     */
    private function manager(array $files, string $moduleKey): array
    {
        $configPath = base_path() . '/phinx.php';
        $configValues = require $configPath;
        if (!is_array($configValues)) {
            throw new RuntimeException('phinx.php 未返回配置数组。');
        }

        $configValues['paths']['migrations'] = array_values(array_unique(array_map('dirname', $files)));
        $environment = (string) config('plugin.saimulti.module.migration_environment', 'default');
        if (!isset($configValues['environments'][$environment]) || !is_array($configValues['environments'][$environment])) {
            throw new RuntimeException(sprintf('Phinx 环境不存在: %s', $environment));
        }
        $configValues['environments'][$environment]['migration_table'] = $this->migrationTable($moduleKey);
        $config = new Config($configValues, $configPath);
        if (!$config->hasEnvironment($environment)) {
            throw new RuntimeException(sprintf('Phinx 环境不存在: %s', $environment));
        }

        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        return [new Manager($config, $input, $output), $environment, $output];
    }

    public function migrationTable(string $moduleKey): string
    {
        if (!preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $moduleKey)) {
            throw new RuntimeException('module_key 必须为 snake_case。');
        }

        $prefix = 'phinxlog_module_';
        $table = $prefix . $moduleKey;
        if (strlen($table) <= 64) {
            return $table;
        }

        $suffix = '_' . substr(hash('sha256', $moduleKey), 0, 10);
        return $prefix . substr($moduleKey, 0, 64 - strlen($prefix) - strlen($suffix)) . $suffix;
    }

    /** @param list<string> $declaredFiles */
    private function assertDirectoriesContainOnlyDeclaredMigrations(array $declaredFiles): void
    {
        $declared = array_fill_keys($declaredFiles, true);
        foreach (array_unique(array_map('dirname', $declaredFiles)) as $directory) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [] as $candidate) {
                $real = realpath($candidate);
                if ($real !== false && Util::isValidMigrationFileName(basename($real)) && !isset($declared[$real])) {
                    throw new RuntimeException(sprintf(
                        '迁移目录包含 manifest 未声明文件，拒绝执行: %s',
                        $real,
                    ));
                }
            }
        }
    }

    private function within(string $path, string $root): bool
    {
        return str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
}
