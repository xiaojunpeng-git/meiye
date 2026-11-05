<?php
namespace app\services\organization;

use mohe\exceptions\AdminException;

/**
 * 门店表单归属保存的范围校验。继承现有选择器的唯一范围解析，
 * 不修改选择器页面/查询，不给门店编辑叠加组织维护或超管功能权限。
 */
class StoreOrganizationEditAccessServices extends OrganizationResourceSelectorServices
{
    /**
     * 门店编辑已由原保存路由授权；归属调整只复核同一选择器的数据范围，
     * 不另要求超管或组织编辑功能。前端可选节点不能成为绕过后端范围的凭据。
     */
    public function assertStoreOrganizationSelection(int $storeId, int $orgId, array $adminInfo): void
    {
        if ((int)($adminInfo['id'] ?? 0) <= 0) {
            throw new AdminException('无法确认登录身份，禁止写入');
        }
        // 同一进程可能先读过选择器，写入复核必须读取当前绑定/排除范围。
        OrganizationScopeService::clearCache();
        $stores = $this->resolveAllowedStoreIds($adminInfo);
        if ($stores === null) return;
        if ($storeId > 0 && !in_array($storeId, $stores, true)) {
            throw new AdminException('门店超出当前账号的数据范围');
        }
        $orgs = $this->resolveAllowedOrgIds($stores);
        if (!in_array($orgId, $orgs ?? [], true)) {
            throw new AdminException('所属组织超出当前账号的数据范围');
        }
    }

}
