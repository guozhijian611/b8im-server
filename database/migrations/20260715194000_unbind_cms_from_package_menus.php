<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * CMS article menus (数据管理/文章/轮播/分类) are legacy saimulti demo features.
 * They must not be package-default tenant menus for b8im; tenant menus only come
 * from sm_tenant_group_menu (package) + licensed module menus.
 *
 * This migration unbinds the CMS tree from every package. Platform admins can
 * still re-grant the catalog entries via package menu permission if needed.
 */
final class UnbindCmsFromPackageMenus extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('sm_tenant_group_menu') || !$this->hasTable('sm_tenant_menu')) {
            return;
        }

        // Match by stable codes so id drift across environments is safe.
        $this->execute(<<<'SQL'
DELETE gm FROM `sm_tenant_group_menu` gm
INNER JOIN `sm_tenant_menu` m ON m.id = gm.menu_id
WHERE m.organization = 0
  AND (
    m.code IN ('data', 'cms/article', 'cms/banner', 'cms/category')
    OR m.code LIKE 'cms/%'
    OR m.slug LIKE 'data:cms:%'
    OR m.parent_id IN (
      SELECT id FROM (
        SELECT id FROM `sm_tenant_menu`
        WHERE organization = 0
          AND code IN ('data', 'cms/article', 'cms/banner', 'cms/category')
      ) cms_roots
    )
  )
SQL);

        // Role grants for unbound menus can remain; runtime filters them out via
        // TenantAssignableMenuService. Clean them so role UI stays accurate.
        if ($this->hasTable('sm_tenant_role_menu')) {
            $this->execute(<<<'SQL'
DELETE rm FROM `sm_tenant_role_menu` rm
INNER JOIN `sm_tenant_menu` m ON m.id = rm.menu_id
WHERE m.organization = 0
  AND (
    m.code IN ('data', 'cms/article', 'cms/banner', 'cms/category')
    OR m.code LIKE 'cms/%'
    OR m.slug LIKE 'data:cms:%'
    OR m.parent_id IN (
      SELECT id FROM (
        SELECT id FROM `sm_tenant_menu`
        WHERE organization = 0
          AND code IN ('data', 'cms/article', 'cms/banner', 'cms/category')
      ) cms_roots
    )
  )
SQL);
        }
    }

    public function down(): void
    {
        // Do not re-bind CMS to packages; product default is package-without-CMS.
    }
}
