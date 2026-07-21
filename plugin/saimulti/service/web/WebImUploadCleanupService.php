<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Throwable;

final class WebImUploadCleanupService
{
    public function __construct(
        private readonly WebImUploadReservationServiceInterface $reservations,
        private readonly WebImUploadStorageInterface $storage,
    ) {
    }

    /**
     * @return array{
     *   scanned:int,
     *   claimed:int,
     *   released:int,
     *   failed:int,
     *   errors:list<array{reservation_id:int,phase:string,code:int,message:string}>
     * }
     */
    public function run(int $limit = 25): array
    {
        $batch = $this->reservations->claimCleanupBatch($limit);
        $rows = $batch['rows'];
        $errors = $batch['errors'];
        $released = 0;
        foreach ($rows as $row) {
            $reservationId = (int) $row['id'];
            try {
                $authorized = $this->reservations->authorizeCleanupDelete(
                    $reservationId,
                    (string) $row['cleanup_lease_token'],
                    (int) $row['cleanup_claimed_version'],
                    (int) $row['organization'],
                    (string) $row['storage_path'],
                );
                if (!$authorized) {
                    $errors[] = $this->error(
                        $reservationId,
                        'authorize',
                        409,
                        '上传孤儿清理删除授权已失效。',
                    );
                    continue;
                }
                $this->storage->delete(
                    (int) $row['organization'],
                    (string) $row['storage_path'],
                );
                $succeeded = $this->reservations->cleanupSucceeded(
                    $reservationId,
                    (string) $row['cleanup_lease_token'],
                    (int) $row['cleanup_claimed_version'],
                );
                if ($succeeded) {
                    $released++;
                } else {
                    $errors[] = $this->error(
                        $reservationId,
                        'complete',
                        409,
                        '上传孤儿清理完成状态已失效。',
                    );
                }
            } catch (Throwable $exception) {
                error_log(sprintf(
                    'Upload cleanup execution failed [reservation=%d class=%s code=%d]',
                    $reservationId,
                    $exception::class,
                    $exception->getCode(),
                ));
                try {
                    $this->reservations->cleanupFailed(
                        $reservationId,
                        (string) $row['cleanup_lease_token'],
                        (int) $row['cleanup_claimed_version'],
                        $exception->getMessage(),
                    );
                } catch (Throwable $markFailedException) {
                    error_log(sprintf(
                        'Upload cleanup failure mark failed [reservation=%d class=%s code=%d]',
                        $reservationId,
                        $markFailedException::class,
                        $markFailedException->getCode(),
                    ));
                }
                $errors[] = $this->error(
                    $reservationId,
                    'execute',
                    503,
                    '上传孤儿清理执行失败。',
                );
            }
        }

        return [
            'scanned' => (int) $batch['scanned'],
            'claimed' => count($rows),
            'released' => $released,
            'failed' => count($errors),
            'errors' => $errors,
        ];
    }

    /** @return array{reservation_id:int,phase:string,code:int,message:string} */
    private function error(
        int $reservationId,
        string $phase,
        int $code,
        string $message,
    ): array {
        return [
            'reservation_id' => $reservationId,
            'phase' => $phase,
            'code' => $code,
            'message' => $message,
        ];
    }
}
