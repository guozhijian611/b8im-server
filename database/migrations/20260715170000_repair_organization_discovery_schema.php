<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RepairOrganizationDiscoverySchema extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('sm_system_organization');
        $columns = [
            'enterprise_code' => ['string', ['limit' => 64, 'null' => true, 'comment' => '公开企业码', 'after' => 'domain']],
            'deployment_id' => ['string', ['limit' => 64, 'null' => true, 'comment' => '目标部署信任域', 'after' => 'enterprise_code']],
            'config_version' => ['biginteger', ['signed' => false, 'null' => false, 'default' => 1, 'comment' => '公开配置版本', 'after' => 'deployment_id']],
            'favicon' => ['string', ['limit' => 512, 'null' => false, 'default' => '', 'comment' => '站点图标', 'after' => 'logo']],
            'icp' => ['string', ['limit' => 128, 'null' => false, 'default' => '', 'comment' => 'ICP 备案号', 'after' => 'favicon']],
            'public_security_record_no' => ['string', ['limit' => 128, 'null' => false, 'default' => '', 'comment' => '公安备案号', 'after' => 'icp']],
            'public_security_record_url' => ['string', ['limit' => 512, 'null' => false, 'default' => '', 'comment' => '公安备案链接', 'after' => 'public_security_record_no']],
            'copyright' => ['string', ['limit' => 255, 'null' => false, 'default' => '', 'comment' => '版权信息', 'after' => 'public_security_record_url']],
            'android_download_url' => ['string', ['limit' => 512, 'null' => false, 'default' => '', 'comment' => 'Android 下载地址', 'after' => 'copyright']],
            'ios_download_url' => ['string', ['limit' => 512, 'null' => false, 'default' => '', 'comment' => 'iOS 下载地址', 'after' => 'android_download_url']],
            'api_server_url' => ['string', ['limit' => 512, 'null' => false, 'default' => '', 'comment' => 'API 服务地址', 'after' => 'ios_download_url']],
            'im_server_url' => ['string', ['limit' => 512, 'null' => false, 'default' => '', 'comment' => 'IM 服务地址', 'after' => 'api_server_url']],
            'upload_server_url' => ['string', ['limit' => 512, 'null' => false, 'default' => '', 'comment' => '上传服务地址', 'after' => 'im_server_url']],
            'web_server_url' => ['string', ['limit' => 512, 'null' => false, 'default' => '', 'comment' => 'Web 服务地址', 'after' => 'upload_server_url']],
            'user_agreement_title' => ['string', ['limit' => 128, 'null' => false, 'default' => '用户协议', 'comment' => '用户协议标题', 'after' => 'web_server_url']],
            'user_agreement_content' => ['text', ['null' => true, 'comment' => '用户协议内容', 'after' => 'user_agreement_title']],
            'privacy_policy_title' => ['string', ['limit' => 128, 'null' => false, 'default' => '隐私政策', 'comment' => '隐私政策标题', 'after' => 'user_agreement_content']],
            'privacy_policy_content' => ['text', ['null' => true, 'comment' => '隐私政策内容', 'after' => 'privacy_policy_title']],
        ];

        foreach ($columns as $name => [$type, $options]) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, $type, $options);
            }
        }
        $table->update();

        $deploymentId = getenv('DEPLOYMENT_ID');
        $deploymentId = is_string($deploymentId) && preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $deploymentId) === 1
            ? $deploymentId
            : 'b8im-local';
        $this->execute(<<<SQL
UPDATE `sm_system_organization`
SET
  `domain` = NULLIF(LOWER(TRIM(`domain`)), ''),
  `enterprise_code` = COALESCE(NULLIF(LOWER(TRIM(`enterprise_code`)), ''), CONCAT('org_', `id`)),
  `deployment_id` = COALESCE(NULLIF(TRIM(`deployment_id`), ''), '{$deploymentId}'),
  `config_version` = GREATEST(COALESCE(`config_version`, 1), 1)
SQL);

        $table = $this->table('sm_system_organization');
        $table
            ->changeColumn('enterprise_code', 'string', ['limit' => 64, 'null' => false, 'comment' => '公开企业码'])
            ->changeColumn('deployment_id', 'string', ['limit' => 64, 'null' => false, 'comment' => '目标部署信任域']);
        if (!$table->hasIndex(['enterprise_code'])) {
            $table->addIndex(['enterprise_code'], ['unique' => true, 'name' => 'uk_sm_org_enterprise_code']);
        }
        if (!$table->hasIndex(['domain'])) {
            $table->addIndex(['domain'], ['unique' => true, 'name' => 'uk_sm_org_domain']);
        }
        $table->update();
    }

    public function down(): void
    {
        // This migration repairs drift against the existing discovery contract.
        // Removing these columns would also remove schema owned by the original migration.
    }
}
