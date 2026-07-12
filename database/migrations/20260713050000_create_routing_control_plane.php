<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateRoutingControlPlane extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE `sm_server_deployment` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `deployment_id` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `status` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_server_deployment_id` (`deployment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='服务部署信任域';

CREATE TABLE `sm_server_route` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `deployment_id` varchar(64) NOT NULL,
  `route_id` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `draft_version` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `admin_status` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_server_route_identity` (`deployment_id`, `route_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='服务线路稳定身份';

CREATE TABLE `sm_server_route_version` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `deployment_id` varchar(64) NOT NULL,
  `route_id` varchar(64) NOT NULL,
  `route_version` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(128) NOT NULL,
  `api_server_url` varchar(512) NOT NULL,
  `im_server_url` varchar(512) NOT NULL,
  `upload_server_url` varchar(512) NOT NULL,
  `web_server_url` varchar(512) NOT NULL,
  `region` varchar(64) NOT NULL DEFAULT '',
  `carrier` varchar(64) NOT NULL DEFAULT '',
  `failure_domain` varchar(128) NOT NULL DEFAULT '',
  `probe_json` json NOT NULL,
  `content_hash` char(64) NOT NULL,
  `publish_time` datetime NOT NULL,
  `audit_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_server_route_version` (`deployment_id`, `route_id`, `route_version`),
  KEY `idx_server_route_content` (`deployment_id`, `route_id`, `content_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='不可变服务线路版本';

CREATE TABLE `sm_server_route_pool` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `deployment_id` varchar(64) NOT NULL,
  `route_pool_id` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `draft_version` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `status` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_server_route_pool_identity` (`deployment_id`, `route_pool_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='服务线路池稳定身份';

CREATE TABLE `sm_server_route_pool_version` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `deployment_id` varchar(64) NOT NULL,
  `route_pool_id` varchar(64) NOT NULL,
  `pool_version` bigint(20) UNSIGNED NOT NULL,
  `content_hash` char(64) NOT NULL,
  `publish_time` datetime NOT NULL,
  `rollback_from` bigint(20) UNSIGNED DEFAULT NULL,
  `audit_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_server_route_pool_version` (`deployment_id`, `route_pool_id`, `pool_version`),
  KEY `idx_server_route_pool_content` (`deployment_id`, `route_pool_id`, `content_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='不可变服务线路池版本';

CREATE TABLE `sm_server_route_pool_item` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `deployment_id` varchar(64) NOT NULL,
  `route_pool_id` varchar(64) NOT NULL,
  `pool_version` bigint(20) UNSIGNED NOT NULL,
  `route_id` varchar(64) NOT NULL,
  `route_version` bigint(20) UNSIGNED NOT NULL,
  `priority` int(10) UNSIGNED NOT NULL DEFAULT 100,
  `weight` int(10) UNSIGNED NOT NULL DEFAULT 100,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_server_route_pool_item` (`deployment_id`, `route_pool_id`, `pool_version`, `route_id`),
  KEY `idx_server_route_pool_item_route` (`deployment_id`, `route_id`, `route_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='线路池不可变成员';

CREATE TABLE `sm_organization_route_policy` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `deployment_id` varchar(64) NOT NULL,
  `organization` int(11) UNSIGNED NOT NULL,
  `client_family` varchar(16) NOT NULL,
  `route_pool_id` varchar(64) NOT NULL,
  `pool_version` bigint(20) UNSIGNED NOT NULL,
  `mode` varchar(32) NOT NULL,
  `policy_json` json NOT NULL,
  `current_routing_version` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_organization_route_policy` (`deployment_id`, `organization`, `client_family`),
  KEY `idx_organization_route_pool` (`deployment_id`, `route_pool_id`, `pool_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='机构客户端线路策略';

CREATE TABLE `sm_organization_route_publish` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `deployment_id` varchar(64) NOT NULL,
  `organization` int(11) UNSIGNED NOT NULL,
  `client_family` varchar(16) NOT NULL,
  `routing_version` bigint(20) UNSIGNED NOT NULL,
  `route_pool_id` varchar(64) NOT NULL,
  `pool_version` bigint(20) UNSIGNED NOT NULL,
  `snapshot_json` json NOT NULL,
  `signature_kid` varchar(64) NOT NULL,
  `signature` varchar(128) NOT NULL,
  `publish_time` datetime NOT NULL,
  `audit_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_organization_route_publish` (`deployment_id`, `organization`, `client_family`, `routing_version`),
  KEY `idx_organization_route_latest` (`organization`, `client_family`, `routing_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='机构不可变线路发布快照';
SQL);

        $now = date('Y-m-d H:i:s');
        $organizations = $this->fetchAll(
            'SELECT `id`, `deployment_id`, `api_server_url`, `im_server_url`, '
            . '`upload_server_url`, `web_server_url` FROM `sm_system_organization` '
            . 'WHERE `delete_time` IS NULL ORDER BY `id` ASC',
        );

        foreach ($organizations as $organization) {
            $this->seedOrganizationDraft($organization, $now);
        }

        $this->execute(<<<'SQL'
ALTER TABLE `sm_system_organization`
  DROP COLUMN `api_server_url`,
  DROP COLUMN `im_server_url`,
  DROP COLUMN `upload_server_url`,
  DROP COLUMN `web_server_url`;
SQL);
    }

    /** @param array<string, mixed> $organization */
    private function seedOrganizationDraft(array $organization, string $now): void
    {
        $id = (int) $organization['id'];
        $deploymentId = (string) $organization['deployment_id'];
        $routeId = 'org-' . $id . '-primary';
        $poolId = 'org-' . $id . '-default';
        $routeContent = [
            'name' => '默认线路',
            'api_server_url' => (string) $organization['api_server_url'],
            'im_server_url' => (string) $organization['im_server_url'],
            'upload_server_url' => (string) $organization['upload_server_url'],
            'web_server_url' => (string) $organization['web_server_url'],
            'region' => 'local',
            'carrier' => 'local',
            'failure_domain' => 'local',
            'probes' => [],
        ];
        $routeHash = hash('sha256', $this->canonicalJson($routeContent));
        $poolHash = hash('sha256', $this->canonicalJson([[
            'route_id' => $routeId,
            'route_version' => 1,
            'priority' => 10,
            'weight' => 100,
        ]]));

        $this->execute(sprintf(
            'INSERT IGNORE INTO `sm_server_deployment` (`deployment_id`,`name`,`status`,`create_time`,`update_time`) VALUES (%s,%s,1,%s,%s)',
            $this->quote($deploymentId),
            $this->quote($deploymentId),
            $this->quote($now),
            $this->quote($now),
        ));
        $this->table('sm_server_route')->insert([
            'deployment_id' => $deploymentId,
            'route_id' => $routeId,
            'name' => '默认线路',
            'draft_version' => 1,
            'admin_status' => 1,
            'create_time' => $now,
            'update_time' => $now,
        ])->saveData();
        $this->table('sm_server_route_version')->insert([
            'deployment_id' => $deploymentId,
            'route_id' => $routeId,
            'route_version' => 1,
            'name' => '默认线路',
            'api_server_url' => $routeContent['api_server_url'],
            'im_server_url' => $routeContent['im_server_url'],
            'upload_server_url' => $routeContent['upload_server_url'],
            'web_server_url' => $routeContent['web_server_url'],
            'region' => 'local',
            'carrier' => 'local',
            'failure_domain' => 'local',
            'probe_json' => json_encode([], JSON_THROW_ON_ERROR),
            'content_hash' => $routeHash,
            'publish_time' => $now,
            'audit_id' => 0,
        ])->saveData();
        $this->table('sm_server_route_pool')->insert([
            'deployment_id' => $deploymentId,
            'route_pool_id' => $poolId,
            'name' => '机构 ' . $id . ' 默认线路池',
            'draft_version' => 1,
            'status' => 1,
            'create_time' => $now,
            'update_time' => $now,
        ])->saveData();
        $this->table('sm_server_route_pool_version')->insert([
            'deployment_id' => $deploymentId,
            'route_pool_id' => $poolId,
            'pool_version' => 1,
            'content_hash' => $poolHash,
            'publish_time' => $now,
            'audit_id' => 0,
        ])->saveData();
        $this->table('sm_server_route_pool_item')->insert([
            'deployment_id' => $deploymentId,
            'route_pool_id' => $poolId,
            'pool_version' => 1,
            'route_id' => $routeId,
            'route_version' => 1,
            'priority' => 10,
            'weight' => 100,
        ])->saveData();

        foreach (['web', 'app', 'desktop'] as $clientFamily) {
            $policy = [
                'mode' => 'single',
                'primary_route_id' => $routeId,
                'backup_route_ids' => [],
            ];
            $this->table('sm_organization_route_policy')->insert([
                'deployment_id' => $deploymentId,
                'organization' => $id,
                'client_family' => $clientFamily,
                'route_pool_id' => $poolId,
                'pool_version' => 1,
                'mode' => 'single',
                'policy_json' => json_encode($policy, JSON_THROW_ON_ERROR),
                'current_routing_version' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ])->saveData();
        }
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE `sm_system_organization`
  ADD COLUMN `api_server_url` varchar(512) NOT NULL DEFAULT '' AFTER `ios_download_url`,
  ADD COLUMN `im_server_url` varchar(512) NOT NULL DEFAULT '' AFTER `api_server_url`,
  ADD COLUMN `upload_server_url` varchar(512) NOT NULL DEFAULT '' AFTER `im_server_url`,
  ADD COLUMN `web_server_url` varchar(512) NOT NULL DEFAULT '' AFTER `upload_server_url`;

UPDATE `sm_system_organization` o
JOIN `sm_organization_route_policy` p
  ON p.organization = o.id AND p.client_family = 'web'
JOIN `sm_server_route_pool_item` i
  ON i.deployment_id = p.deployment_id AND i.route_pool_id = p.route_pool_id
  AND i.pool_version = p.pool_version AND i.priority = 10
JOIN `sm_server_route_version` r
  ON r.deployment_id = i.deployment_id AND r.route_id = i.route_id AND r.route_version = i.route_version
SET o.api_server_url = r.api_server_url,
    o.im_server_url = r.im_server_url,
    o.upload_server_url = r.upload_server_url,
    o.web_server_url = r.web_server_url;

DROP TABLE `sm_organization_route_publish`;
DROP TABLE `sm_organization_route_policy`;
DROP TABLE `sm_server_route_pool_item`;
DROP TABLE `sm_server_route_pool_version`;
DROP TABLE `sm_server_route_pool`;
DROP TABLE `sm_server_route_version`;
DROP TABLE `sm_server_route`;
DROP TABLE `sm_server_deployment`;
SQL);
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = json_decode($this->canonicalJson($item), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
