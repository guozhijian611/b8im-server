<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use B8im\Module\FileMedia\Policy\UploadDecision;
use B8im\Module\FileMedia\Policy\UploadPolicy;
use B8im\Module\FileMedia\Policy\UploadPolicyEvaluator;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\ModuleAccessDecision;
use plugin\saimulti\service\module\ModuleAccessService;
use plugin\saimulti\service\module\FileMediaPolicyService;
use plugin\saimulti\service\module\ModuleServiceFactory;
use Throwable;

final class AuthoritativeWebImUploadPolicy implements WebImUploadPolicyInterface
{
    public function __construct(private readonly ?ModuleAccessService $access = null)
    {
    }

    public function assertAllowed(int $organization, int $sizeBytes): void
    {
        if ($organization <= 0 || $sizeBytes <= 0) {
            throw new ApiException('上传参数无效。', 422);
        }
        $decision = ($this->access ?? ModuleServiceFactory::access())
            ->decideAuthoritatively($organization, 'file_media', 'server');
        if ($decision === ModuleAccessDecision::UNAVAILABLE) {
            throw new ApiException('文件媒体授权状态暂时不可用。', 503);
        }

        try {
            $policy = $decision === ModuleAccessDecision::AVAILABLE
                ? $this->enhancedPolicy($organization)
                : new UploadPolicy(0, UploadPolicyEvaluator::BASE_MAX_FILE_BYTES, 0, 0);
            $evaluated = (new UploadPolicyEvaluator())->evaluate(
                $policy,
                $sizeBytes,
                $decision === ModuleAccessDecision::AVAILABLE ? 1 : 0,
            );
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ApiException('文件媒体增强策略暂时不可用。', 503, $exception);
        }
        if ($evaluated->allowed) {
            return;
        }
        $message = match ($evaluated->reason) {
            UploadDecision::ABSOLUTE_LIMIT_EXCEEDED => '文件大小不能超过 2GiB。',
            UploadDecision::POLICY_LIMIT_EXCEEDED => '超过文件媒体策略允许的单文件大小。',
            UploadDecision::ENHANCED_POLICY_UNAVAILABLE => '当前未开启大文件上传。',
            default => '上传不符合文件媒体策略。',
        };
        throw new ApiException($message, 422);
    }

    private function enhancedPolicy(int $organization): UploadPolicy
    {
        $policyService = new FileMediaPolicyService();
        $row = $policyService->project($policyService->ensureDefault($organization));

        return new UploadPolicy(
            $row['status'],
            (int) $row['max_file_bytes'],
            $row['large_file_enabled'],
            $row['preview_enabled'],
        );
    }
}
