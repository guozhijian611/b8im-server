<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\Module\Search\Lifecycle;
use B8im\ModuleSdk\Lifecycle\LifecycleOperation;
use B8im\ModuleSdk\Manifest\Manifest;
use RuntimeException;

final class SearchLifecycleContextOptionsEnricher implements
    ModuleLifecycleContextOptionsEnricherInterface,
    ModuleExpiryHookContextOptionsEnricherInterface
{
    public function __construct(private readonly SearchLifecycleFence $fence)
    {
    }

    public function enrich(
        Manifest $manifest,
        LifecycleOperation $operation,
        ?int $organization,
        ?string $fromVersion,
        bool $preserveData,
        array $options,
    ): array {
        if ($manifest->moduleKey() !== 'search' || $operation === LifecycleOperation::INSTALL) {
            return $options;
        }
        if (array_key_exists(Lifecycle::FENCE_OPTION, $options)) {
            throw new RuntimeException('Search lifecycle fence option is Server-owned.');
        }

        return $options + [Lifecycle::FENCE_OPTION => $this->fence];
    }

    public function supportsExpiry(Manifest $manifest, LifecycleOperation $operation): bool
    {
        return $manifest->moduleKey() === 'search' && $operation === LifecycleOperation::DISABLE;
    }

    public function enrichExpiry(
        Manifest $manifest,
        LifecycleOperation $operation,
        int $organization,
        array $task,
        array $options,
    ): array {
        if (!$this->supportsExpiry($manifest, $operation)
            || array_key_exists(Lifecycle::FENCE_OPTION, $options)) {
            throw new RuntimeException('Search expiry lifecycle receipt contract is unavailable.');
        }
        $credential = ModuleExpiryHookContract::credential(
            $options[ModuleLicenseExpiryScanner::HOOK_CREDENTIAL_OPTION] ?? [],
        );
        $taskCredential = ModuleExpiryHookContract::credential($task);
        $stableKey = ModuleExpiryHookContract::stableKey($credential);
        if ($credential['organization'] !== $organization
            || $credential['module_key'] !== 'search'
            || $credential !== $taskCredential
            || ($task['idempotency_key'] ?? null) !== $stableKey
            || ($options[ModuleLicenseExpiryScanner::HOOK_IDEMPOTENCY_OPTION] ?? null) !== $stableKey
            || ($options[ModuleLicenseExpiryScanner::HOOK_REQUEST_DIGEST_OPTION] ?? null)
                !== ($task['request_digest'] ?? null)
            || ($task['hook_kind'] ?? null) !== ModuleExpiryHookContract::KIND_TRANSACTIONAL) {
            throw new RuntimeException('Search expiry lifecycle credential is invalid.');
        }

        return $options + [
            Lifecycle::FENCE_OPTION => new SearchExpiryCredentialFence(
                $this->fence->inCurrentTransaction(),
                $credential,
            ),
        ];
    }
}
