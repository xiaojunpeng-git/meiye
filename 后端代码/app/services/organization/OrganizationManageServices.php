<?php
namespace app\services\organization;

use app\dao\organization\OrganizationAdminDao;
use app\dao\organization\OrganizationAdminStoreExcludeDao;
use app\dao\organization\OrganizationChangeLogDao;
use app\dao\organization\OrganizationDao;
use app\dao\organization\OrganizationStoreDao;
use app\services\BaseServices;
use think\facade\Db;

/**
 * 组织架构管理（树、门店归属、管理员、排除、审计）
 */
class OrganizationManageServices extends BaseServices
{
    public function __construct(
        OrganizationDao $dao,
        OrganizationStoreDao $orgStoreDao,
        OrganizationAdminDao $adminDao,
        OrganizationAdminStoreExcludeDao $excludeDao,
        OrganizationChangeLogDao $logDao,
        OrganizationScopeService $scopeService
    ) {
        $this->dao = $dao;
        $this->orgStoreDao = $orgStoreDao;
        $this->adminDao = $adminDao;
        $this->excludeDao = $excludeDao;
        $this->logDao = $logDao;
        $this->scopeService = $scopeService;
    }

    /** @var OrganizationStoreDao */
    protected $orgStoreDao;
    /** @var OrganizationAdminDao */
    protected $adminDao;
    /** @var OrganizationAdminStoreExcludeDao */
    protected $excludeDao;
    /** @var OrganizationChangeLogDao */
    protected $logDao;
    /** @var OrganizationScopeService */
    protected $scopeService;

    public function getTree(): array
    {
        $list = $this->dao->getList(['is_del' => 0], 'id,pid,name,sort');
        return $this->buildTreeNodes($list, 0);
    }

    public function getRegionCountsMap(): array
    {
        $list = $this->dao->getList(['is_del' => 0], 'id');
        $map = [];
        foreach ($list as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = [
                'store_count' => count($this->scopeService->getOrgStoreIds($id, false)),
                'agent_count' => (int)$this->adminDao->count(['org_id' => $id, 'is_del' => 0]),
            ];
        }
        return $map;
    }

    public function saveOrganization(int $id, array $data, int $operatorId = 0, string $operatorName = ''): int
    {
        $pid = (int)($data['pid'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \Exception('请输入组织名称');
        }
        if ($id && $id === $pid) {
            throw new \Exception('上级组织不能是自己');
        }
        $time = time();
        $save = [
            'pid' => $pid,
            'name' => $name,
            'sort' => (int)($data['sort'] ?? 0),
            'update_time' => $time,
        ];
        if ($id) {
            $before = $this->dao->get($id);
            $this->dao->update($id, $save);
            $this->writeLog($id, 'update', 'organization', $id, $before ? (array)$before : [], $save, '编辑组织', $operatorId, $operatorName);
            OrganizationScopeService::clearCache();
            return $id;
        }
        $save['add_time'] = $time;
        $saved = $this->dao->save($save);
        $newId = is_object($saved) ? (int)$saved->id : (int)$saved;
        $this->writeLog($newId, 'create', 'organization', $newId, [], $save, '新增组织', $operatorId, $operatorName);
        OrganizationScopeService::clearCache();
        return $newId;
    }

    public function deleteOrganization(int $id, int $operatorId = 0, string $operatorName = ''): bool
    {
        if ($this->dao->count(['pid' => $id, 'is_del' => 0]) > 0) {
            throw new \Exception('请先删除下级组织');
        }
        if ($this->orgStoreDao->count(['org_id' => $id]) > 0) {
            throw new \Exception('组织下仍有门店，无法删除');
        }
        if ($this->adminDao->count(['org_id' => $id, 'is_del' => 0]) > 0) {
            throw new \Exception('组织下仍有管理员，无法删除');
        }
        $before = $this->dao->get($id);
        $this->dao->update($id, ['is_del' => 1, 'update_time' => time()]);
        $this->writeLog($id, 'delete', 'organization', $id, $before ? (array)$before : [], ['is_del' => 1], '删除组织', $operatorId, $operatorName);
        OrganizationScopeService::clearCache();
        return true;
    }

    /**
     * 门店绑定组织（一店一组织，事务）
     */
    public function bindStoreToOrg(int $storeId, int $orgId, int $operatorId = 0, string $operatorName = ''): bool
    {
        if ($storeId <= 0 || $orgId <= 0) {
            throw new \Exception('门店或组织无效');
        }
        $org = $this->dao->get($orgId, ['id', 'is_del']);
        if (!$org || (int)($org['is_del'] ?? 0) === 1) {
            throw new \Exception('组织不存在');
        }
        Db::startTrans();
        try {
            $existing = $this->orgStoreDao->getOne(['store_id' => $storeId]);
            $oldOrgId = $existing ? (int)($existing['org_id'] ?? 0) : 0;
            if ($existing) {
                if ($oldOrgId === $orgId) {
                    Db::commit();
                    return true;
                }
                $this->orgStoreDao->update((int)$existing['id'], [
                    'org_id' => $orgId,
                ]);
            } else {
                $this->orgStoreDao->save([
                    'org_id' => $orgId,
                    'store_id' => $storeId,
                    'add_time' => time(),
                ]);
            }
            if ($oldOrgId > 0 && $oldOrgId !== $orgId) {
                $this->onStoreLeaveOrg($storeId, $oldOrgId);
            }
            $this->onStoreJoinOrg($storeId, $orgId);
            $this->writeLog($orgId, 'bind_store', 'store', $storeId, ['org_id' => $oldOrgId], ['org_id' => $orgId], '门店迁移组织', $operatorId, $operatorName);
            Db::commit();
            OrganizationScopeService::clearCache();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 新门店加入组织：默认授予全部管理员（不自动恢复曾明确排除的门店）
     */
    protected function onStoreJoinOrg(int $storeId, int $orgId): void
    {
        // 排除列表不做自动删除；新门店不在排除表中，自然可见
    }

    /**
     * 门店离开组织：清理该组织下所有管理员对此店的排除记录
     */
    protected function onStoreLeaveOrg(int $storeId, int $orgId): void
    {
        $adminIds = $this->adminDao->getColumn(['org_id' => $orgId, 'is_del' => 0], 'id') ?: [];
        if ($adminIds) {
            $this->excludeDao->delete([
                ['org_admin_id', 'in', $adminIds],
                ['store_id', '=', $storeId],
            ]);
        }
    }

    /**
     * 保存管理员排除门店（不自动恢复历史排除）
     */
    public function saveAdminStoreExcludes(int $orgAdminId, array $storeIds, int $operatorId = 0, string $operatorName = ''): bool
    {
        $admin = $this->adminDao->get($orgAdminId, ['id', 'org_id', 'is_del']);
        if (!$admin || (int)($admin['is_del'] ?? 0) === 1) {
            throw new \Exception('管理员不存在');
        }
        $admin = is_object($admin) ? $admin->toArray() : $admin;
        $orgStoreIds = $this->scopeService->getOrgStoreIds((int)$admin['org_id'], true);
        $orgStoreFlip = array_flip($orgStoreIds);
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        foreach ($storeIds as $storeId) {
            if (!isset($orgStoreFlip[$storeId])) {
                throw new \Exception('门店不在组织范围内');
            }
        }
        $before = $this->excludeDao->getColumn(['org_admin_id' => $orgAdminId], 'store_id') ?: [];
        $this->excludeDao->delete(['org_admin_id' => $orgAdminId]);
        $time = time();
        foreach ($storeIds as $storeId) {
            $this->excludeDao->save([
                'org_admin_id' => $orgAdminId,
                'store_id' => $storeId,
                'add_time' => $time,
            ]);
        }
        $this->writeLog((int)$admin['org_id'], 'save_exclude', 'org_admin', $orgAdminId, ['exclude' => $before], ['exclude' => $storeIds], '更新管理员排除门店', $operatorId, $operatorName);
        OrganizationScopeService::clearCache();
        return true;
    }

    public function getAdminExcludeData(int $orgAdminId): array
    {
        $admin = $this->adminDao->get($orgAdminId, ['id', 'org_id', 'legacy_agent_id', 'is_del']);
        if (!$admin) {
            throw new \Exception('管理员不存在');
        }
        $admin = is_object($admin) ? $admin->toArray() : $admin;
        if ((int)($admin['is_del'] ?? 0) === 1) {
            throw new \Exception('管理员不存在');
        }
        $orgId = (int)$admin['org_id'];
        $orgStoreIds = $this->scopeService->getOrgStoreIds($orgId, true);
        $excludeIds = $this->excludeDao->getColumn(['org_admin_id' => $orgAdminId], 'store_id') ?: [];
        $excludeIds = array_map('intval', $excludeIds);
        $resolved = $this->scopeService->getAdminResolvedStoreIds($orgAdminId);
        $storeRows = [];
        if ($orgStoreIds) {
            /** @var \app\dao\store\SystemStoreDao $storeDao */
            $storeDao = app()->make(\app\dao\store\SystemStoreDao::class);
            $storeRows = $storeDao->getStoreList(
                ['ids' => array_values(array_map('intval', $orgStoreIds)), 'is_del' => 0],
                ['id', 'name', 'phone', 'address'],
                0,
                0
            ) ?: [];
        }
        $excludeFlip = array_flip($excludeIds);
        foreach ($storeRows as &$store) {
            $sid = (int)($store['id'] ?? 0);
            $store['excluded'] = isset($excludeFlip[$sid]) ? 1 : 0;
            $store['has_access'] = $store['excluded'] ? 0 : 1;
        }
        unset($store);
        return [
            'org_admin_id' => $orgAdminId,
            'org_id' => $orgId,
            'legacy_agent_id' => (int)($admin['legacy_agent_id'] ?? 0),
            'org_store_ids' => $orgStoreIds,
            'excluded_store_ids' => $excludeIds,
            'resolved_store_ids' => $resolved,
            'stores' => array_values($storeRows),
            'access_store_count' => count($resolved),
            'excluded_store_count' => count($excludeIds),
        ];
    }

    /**
     * 按旧区域管理人员 ID 获取排除数据（前端兼容）
     */
    public function getAdminExcludeDataByLegacyAgentId(int $legacyAgentId): array
    {
        $orgAdminId = $this->resolveOrgAdminIdByLegacyAgent($legacyAgentId);
        return $this->getAdminExcludeData($orgAdminId);
    }

    public function saveAdminStoreExcludesByLegacyAgentId(
        int $legacyAgentId,
        array $storeIds,
        int $operatorId = 0,
        string $operatorName = ''
    ): bool {
        $orgAdminId = $this->resolveOrgAdminIdByLegacyAgent($legacyAgentId);
        return $this->saveAdminStoreExcludes($orgAdminId, $storeIds, $operatorId, $operatorName);
    }

    protected function resolveOrgAdminIdByLegacyAgent(int $legacyAgentId): int
    {
        if ($legacyAgentId <= 0) {
            throw new \Exception('管理人员无效');
        }
        $admin = $this->adminDao->getOne(['legacy_agent_id' => $legacyAgentId, 'is_del' => 0], 'id');
        if ($admin) {
            return (int)(is_object($admin) ? $admin->id : $admin['id']);
        }
        throw new \Exception('请先执行组织架构数据迁移');
    }

    /**
     * 解析组织 ID：支持新表 id，也支持旧区域 manage_region_id（迁移前/双读）
     */
    public function resolveOrgId(int $idOrLegacy): int
    {
        if ($idOrLegacy <= 0) {
            return 0;
        }
        $org = $this->dao->get($idOrLegacy, ['id', 'is_del']);
        if ($org && (int)($org['is_del'] ?? 0) !== 1) {
            return (int)(is_object($org) ? $org->id : $org['id']);
        }
        $byLegacy = $this->dao->getOne([
            'legacy_manage_region_id' => $idOrLegacy,
            'is_del' => 0,
        ], 'id');
        if ($byLegacy) {
            return (int)(is_object($byLegacy) ? $byLegacy->id : $byLegacy['id']);
        }
        return 0;
    }

    /**
     * 组织权限概况
     * 入参可为新组织 id，或旧区域 manage_region_id（左侧树当前仍用旧 ID）
     */
    public function getOrgOverview(int $orgId): array
    {
        $empty = [
            'org_id' => 0,
            'org_name' => '',
            'store_count' => 0,
            'admin_count' => 0,
            'admins' => [],
            'need_migrate' => !$this->scopeService->isMigrated(),
            'legacy_region_id' => $orgId > 0 ? $orgId : 0,
        ];
        if ($orgId <= 0) {
            return $empty;
        }
        $resolvedOrgId = $this->resolveOrgId($orgId);
        if ($resolvedOrgId <= 0) {
            // 未迁移或尚未写入：不抛错，返回空概况 + 引导迁移
            return $empty;
        }
        $org = $this->dao->get($resolvedOrgId, ['id', 'name', 'is_del']);
        if (!$org || (int)($org['is_del'] ?? 0) === 1) {
            return $empty;
        }
        $org = is_object($org) ? $org->toArray() : $org;
        $storeIds = $this->scopeService->getOrgStoreIds($resolvedOrgId, true);
        $admins = $this->adminDao->getList(['org_id' => $resolvedOrgId, 'is_del' => 0], 'id,name,phone,legacy_agent_id');
        $adminRows = [];
        foreach ($admins as $admin) {
            $orgAdminId = (int)($admin['id'] ?? 0);
            $resolved = $this->scopeService->getAdminResolvedStoreIds($orgAdminId);
            $adminRows[] = [
                'org_admin_id' => $orgAdminId,
                'legacy_agent_id' => (int)($admin['legacy_agent_id'] ?? 0),
                'name' => (string)($admin['name'] ?? ''),
                'phone' => (string)($admin['phone'] ?? ''),
                'access_store_count' => count($resolved),
                'excluded_store_count' => max(0, count($storeIds) - count($resolved)),
            ];
        }
        return [
            'org_id' => $resolvedOrgId,
            'org_name' => (string)($org['name'] ?? ''),
            'store_count' => count($storeIds),
            'admin_count' => count($adminRows),
            'admins' => $adminRows,
            'need_migrate' => false,
            'legacy_region_id' => $orgId,
        ];
    }

    public function getChangeLog(int $orgId, int $page = 1, int $limit = 20): array
    {
        $where = [];
        if ($orgId > 0) {
            $resolved = $this->resolveOrgId($orgId);
            if ($resolved > 0) {
                $where['org_id'] = $resolved;
            } else {
                // 未迁移时查不到新表日志，返回空
                return ['list' => [], 'count' => 0, 'page' => $page, 'limit' => $limit];
            }
        }
        return $this->logDao->getList($where, $page, $limit);
    }

    protected function buildTreeNodes(array $list, int $pid): array
    {
        $branch = [];
        foreach ($list as $row) {
            if ((int)($row['pid'] ?? 0) !== $pid) {
                continue;
            }
            $id = (int)$row['id'];
            $branch[] = [
                'id' => $id,
                'pid' => (int)($row['pid'] ?? 0),
                'name' => $row['name'] ?? '',
                'sort' => (int)($row['sort'] ?? 0),
                'store_count' => count($this->scopeService->getOrgStoreIds($id, false)),
                'agent_count' => (int)$this->adminDao->count(['org_id' => $id, 'is_del' => 0]),
                'children' => $this->buildTreeNodes($list, $id),
            ];
        }
        return $branch;
    }

    protected function writeLog(
        int $orgId,
        string $action,
        string $targetType,
        int $targetId,
        array $before,
        array $after,
        string $remark,
        int $operatorId,
        string $operatorName
    ): void {
        $this->logDao->save([
            'org_id' => $orgId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before_data' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : '',
            'after_data' => $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : '',
            'remark' => $remark,
            'operator_id' => $operatorId,
            'operator_name' => $operatorName,
            'add_time' => time(),
        ]);
    }
}
