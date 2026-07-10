<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\Manifest\Manifest;
use B8im\ModuleSdk\Manifest\ManifestLoader;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class ManifestCatalog
{
    /** @var list<string> */
    private array $roots;

    /** @var array<string, array{manifest: Manifest, path: string, root: string}>|null */
    private ?array $entries = null;

    /**
     * @param list<string>|null $roots
     */
    public function __construct(?array $roots = null, private readonly ?ManifestLoader $loader = null)
    {
        $this->roots = $roots ?? $this->configuredRoots();
        if ($this->roots === []) {
            throw new RuntimeException('未配置受控模块目录。');
        }
    }

    /** @return array<string, array{manifest: Manifest, path: string, root: string}> */
    public function all(bool $refresh = false): array
    {
        if ($this->entries !== null && !$refresh) {
            return $this->entries;
        }

        $entries = [];
        foreach ($this->roots as $configuredRoot) {
            $root = realpath($configuredRoot);
            if ($root === false) {
                throw new RuntimeException(sprintf('受控模块路径不存在: %s', $configuredRoot));
            }

            foreach ($this->manifestPaths($root) as $manifestPath) {
                $realManifest = realpath($manifestPath);
                if ($realManifest === false || !$this->within($realManifest, $root)) {
                    throw new RuntimeException(sprintf('manifest 越出受控目录: %s', $manifestPath));
                }

                $manifest = ($this->loader ?? new ManifestLoader())->load($realManifest);
                $moduleKey = $manifest->moduleKey();
                if (isset($entries[$moduleKey]) && $entries[$moduleKey]['path'] !== $realManifest) {
                    throw new RuntimeException(sprintf(
                        '模块 %s 存在重复 manifest: %s, %s',
                        $moduleKey,
                        $entries[$moduleKey]['path'],
                        $realManifest,
                    ));
                }

                $entries[$moduleKey] = [
                    'manifest' => $manifest,
                    'path' => $realManifest,
                    'root' => $root,
                ];
            }
        }

        ksort($entries);

        return $this->entries = $entries;
    }

    /** @return array{manifest: Manifest, path: string, root: string} */
    public function get(string $moduleKey, bool $refresh = false): array
    {
        $entries = $this->all($refresh);
        if (!isset($entries[$moduleKey])) {
            throw new RuntimeException(sprintf('受控目录中未发现模块: %s', $moduleKey));
        }

        return $entries[$moduleKey];
    }

    /** @return list<string> */
    private function configuredRoots(): array
    {
        $roots = config('plugin.saimulti.module.manifest_roots', []);
        $extra = trim((string) config('plugin.saimulti.module.server_module_paths', ''));
        if ($extra !== '') {
            $parts = preg_split('/[,\r\n' . preg_quote(PATH_SEPARATOR, '/') . ']+/', $extra) ?: [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $roots[] = $part;
                }
            }
        }

        return array_values(array_unique(array_map(
            static fn (string $path): string => rtrim($path, DIRECTORY_SEPARATOR),
            array_filter($roots, 'is_string'),
        )));
    }

    /** @return list<string> */
    private function manifestPaths(string $root): array
    {
        if (is_file($root)) {
            if (basename($root) !== 'module.json') {
                throw new RuntimeException(sprintf('受控文件必须名为 module.json: %s', $root));
            }

            return [$root];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink() && $file->getFilename() === 'module.json') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    private function within(string $path, string $root): bool
    {
        if (is_file($root)) {
            return $path === $root;
        }

        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix);
    }
}
