<?php
namespace app\services\organization;

use app\dao\organization\OrganizationAdminDao;
use app\dao\organization\OrganizationAdminStoreExcludeDao;
use app\dao\organization\OrganizationDao;
use app\dao\organization\OrganizationStoreDao;
use app\services\agent\SystemRegionAgentServices;
use app\services\BaseServices;
use app\services\store\SystemRegionManageServices;

/**
 * 组织—门店—管理员 统一范围解析（后台与手机端共用）
 */
class OrganizationScopeService extends BaseServices
{
    /** @var array<int, array<int>> */
    protected static $orgStoreCache = [];

    /** @var array<int, array<int>> */
    protected static $adminStoreCache = [];

    public function __construct(
        OrganizationDao $orgDao,
        OrganizationStoreDao $orgStoreDao,
        OrganizationAdminDao $adminDao,
        OrganizationAdminStoreExcludeDao $excludeDao
    ) {
        $this->dao = $orgDao;
        $this->orgDao = $orgDao;
        $this->orgStoreDao = $orgStoreDao;
        $this->adminDao = $adminDao;
        $this->excludeDao = $excludeDao;
    }

    /** @var OrganizationDao */
    protected $orgDao;

    /** @var OrganizationStoreDao */
    protected $orgStoreDao;

    /** @var OrganizationAdminDao */
    protected $adminDao;

    /** @var OrganizationAdminStoreExcludeDao */
    protected $excludeDao;

    /**
     * 新组织表是否已迁移可用
     */
    public function isMigrated(): bool
    {
        try {
            return (int)$this->orgDao->count(['is_del' => 0]) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 组织下门店（可选含下级组织）
     */
    public function getOrgStoreIds(int $orgId, bool $includeDescendants = false): array
    {
        if ($orgId <= 0) {
            return [];
        }
        $cacheKey = $orgId . ':' . (int)$includeDescendants;
        if (isset(self::$orgStoreCache[$cacheKey])) {
            return self::$orgStoreCache[$cacheKey];
        }
        $orgIds = $includeDescendants
            ? $this->collectDescendantOrgIds($orgId)
            : [$orgId];
        $storeIds = $this->orgStoreDao->getColumn([['org_id', 'in', $orgIds]], 'store_id') ?: [];
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        self::$orgStoreCache[$cacheKey] = $storeIds;
        return $storeIds;
    }

    /**
     * 管理员有效门店 = 组织全量 − 排除列表
     */
    public function getAdminResolvedStoreIds(int $orgAdminId): array
    {
        if ($orgAdminId <= 0) {
            return [];
        }
        if (isset(self::$adminStoreCache[$orgAdminId])) {
            return self::$adminStoreCache[$orgAdminId];
        }
        $admin = $this->adminDao->get($orgAdminId, ['id', 'org_id', 'is_del']);
        if (!$admin || (int)($admin['is_del'] ?? 0) === 1) {
            return [];
        }
        $admin = is_object($admin) ? $admin->toArray() : $admin;
        $orgStoreIds = $this->getOrgStoreIds((int)$admin['org_id'], true);
        if (!$orgStoreIds) {
            self::$adminStoreCache[$orgAdminId] = [];
            return [];
        }
        $excludeIds = $this->excludeDao->getColumn(['org_admin_id' => $orgAdminId], 'store_id') ?: [];
        $excludeIds = array_flip(array_map('intval', $excludeIds));
        $resolved = [];
        foreach ($orgStoreIds as $storeId) {
            if (!isset($excludeIds[$storeId])) {
                $resolved[] = $storeId;
            }
        }
        $resolved = array_values(array_unique($resolved));
        self::$adminStoreCache[$orgAdminId] = $resolved;
        return $resolved;
    }

    /**
     * 兼容旧区域代理：优先新表，否则回退旧逻辑
     */
    public function getResolvedStoreIdsByLegacyAgentId(int $agentId): array
    {
        if ($agentId <= 0) {
            return [];
        }
        if ($this->isMigrated()) {
            $orgAdmin = $this->adminDao->getOne([
                'legacy_agent_id' => $agentId,
                'is_del' => 0,
            ], 'id,org_id');
            if ($orgAdmin) {
                $row = is_object($orgAdmin) ? $orgAdmin->toArray() : $orgAdmin;
                return $this->getAdminResolvedStoreIds((int)$row['id']);
            }
        }
        /** @var SystemRegionAgentServices $legacy */
        $legacy = app()->make(SystemRegionAgentServices::class);
        return $legacy->getAgentStoreScopeIds($agentId);
    }

    /**
     * 手机端 uid → 有效门店并集
     */
    public function getResolvedStoreIdsByUid(int $uid): array
    {
        if ($uid <= 0) {
            return [];
        }
        if ($this->isMigrated()) {
            /** @var \app\services\user\UserServices $userServices */
            $userServices = app()->make(\app\services\user\UserServices::class);
            $phone = trim((string)$userServices->value(['uid' => $uid], 'phone'));
            $where = ['is_del' => 0];
            $admins = [];
            if ($phone !== '') {
                $admins = array_merge($admins, $this->adminDao->getList(array_merge($where, ['phone' => $phone])));
            }
            $uidAdmins = $this->adminDao->getList(array_merge($where, ['uid' => $uid]));
            $admins = array_merge($admins, $uidAdmins);
            $storeIds = [];
            $seenAdmin = [];
            foreach ($admins as $admin) {
                $adminId = (int)($admin['id'] ?? 0);
                if (!$adminId || isset($seenAdmin[$adminId])) {
                    continue;
                }
                $seenAdmin[$adminId] = true;
                $storeIds = array_merge($storeIds, $this->getAdminResolvedStoreIds($adminId));
            }
            $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
            if ($storeIds) {
                return $storeIds;
            }
        }
        /** @var SystemRegionAgentServices $legacy */
        $legacy = app()->make(SystemRegionAgentServices::class);
        $agent = $legacy->getRegionAgentByUid($uid);
        if (!$agent) {
            return [];
        }
        return $this->getResolvedStoreIdsByLegacyAgentId((int)($agent['id'] ?? 0));
    }

    /**
     * 实时解析筛选参数（分析类不固化，单次请求完成）
     *
     * @param array $params store_ids, org_ids, excluded_store_ids, legacy_agent_id
     * @param array $allowedStoreIds 调用方权限上限
     */
    public function resolveStoreIdsFromFilter(array $params, array $allowedStoreIds = []): array
    {
        $allowedSet = $allowedStoreIds
            ? array_flip(array_map('intval', $allowedStoreIds))
            : [];

        $storeIds = $this->parseIdList($params['store_ids'] ?? $params['store_id'] ?? '');
        $orgIds = $this->parseIdList($params['org_ids'] ?? $params['manage_region_id'] ?? '');
        $excludeIds = $this->parseIdList($params['excluded_store_ids'] ?? '');

        $resolved = [];
        if ($storeIds) {
            $resolved = array_merge($resolved, $storeIds);
        }
        foreach ($orgIds as $orgId) {
            $resolved = array_merge($resolved, $this->getOrgStoreIds((int)$orgId, true));
        }
        $resolved = array_values(array_unique(array_filter(array_map('intval', $resolved))));
        if ($excludeIds) {
            $excludeFlip = array_flip($excludeIds);
            $resolved = array_values(array_filter($resolved, function ($id) use ($excludeFlip) {
                return !isset($excludeFlip[$id]);
            }));
        }
        if ($allowedSet) {
            $resolved = array_values(array_filter($resolved, function ($id) use ($allowedSet) {
                return isset($allowedSet[$id]);
            }));
        }
        return $resolved;
    }

    /**
     * 按旧架构区域 ID（manage_region_id）解析门店
     */
    public function getOrgStoreIdsByLegacyManageRegionId(int $legacyManageRegionId, bool $includeDescendants = true): array
    {
        if ($legacyManageRegionId <= 0) {
            return [];
        }
        if (!$this->isMigrated()) {
            /** @var SystemRegionManageServices $manageServices */
            $manageServices = app()->make(SystemRegionManageServices::class);
            return $manageServices->getStoreIdsByManageRegion($legacyManageRegionId, $includeDescendants);
        }
        $org = $this->orgDao->getOne(['legacy_manage_region_id' => $legacyManageRegionId, 'is_del' => 0], 'id');
        if (!$org) {
            return [];
        }
        $org = is_object($org) ? $org->toArray() : $org;
        return $this->getOrgStoreIds((int)$org['id'], $includeDescendants);
    }

    /**
     * 门店当前组织
     */
    public function getStoreOrgId(int $storeId): int
    {
        if ($storeId <= 0) {
            return 0;
        }
        if (!$this->isMigrated()) {
            return 0;
        }
        return (int)$this->orgStoreDao->value(['store_id' => $storeId], 'org_id');
    }

    /**
     * @param int $orgId
     * @return int[]
     */
    protected function collectDescendantOrgIds(int $orgId): array
    {
        $all = $this->orgDao->getList(['is_del' => 0], 'id,pid');
        $result = [$orgId];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($all as $row) {
                $id = (int)($row['id'] ?? 0);
                $pid = (int)($row['pid'] ?? 0);
                if ($id > 0 && in_array($pid, $result, true) && !in_array($id, $result, true)) {
                    $result[] = $id;
                    $changed = true;
                }
            }
        }
        return array_values(array_unique($result));
    }

    /**
     * @param mixed $value
     * @return int[]
     */
    protected function parseIdList($value): array
    {
        if (is_array($value)) {
            return array_values(array_unique(array_filter(array_map('intval', $value))));
        }
        $str = trim((string)$value);
        if ($str === '') {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $str)))));
    }

    /**
     * 清除请求内缓存（组织变更后调用）
     */
    public static function clearCache(): void
    {
        self::$orgStoreCache = [];
        self::$adminStoreCache = [];
    }
}
