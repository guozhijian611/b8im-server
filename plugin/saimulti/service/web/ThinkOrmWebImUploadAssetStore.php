<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use support\think\Db;

final class ThinkOrmWebImUploadAssetStore implements WebImUploadAssetStoreInterface
{
    public function create(array $asset): void
    {
        Db::table('im_upload_asset')->insert($asset);
    }

    public function findActiveImage(int $organization, string $fileId, ?string $ownerUserId = null): ?array
    {
        $query = Db::table('im_upload_asset')
            ->field('file_id,user_id,kind,name,storage_path,size_byte,mime_type,extension')
            ->where('organization', $organization)
            ->where('file_id', $fileId)
            ->where('kind', 'image')
            ->where('status', 1)
            ->whereNull('delete_time');
        if ($ownerUserId !== null) {
            $query->where('user_id', $ownerUserId);
        }
        $asset = $query->find();

        return $asset ? (array) $asset : null;
    }
}
