<?php
namespace app\services\organization;

use app\dao\agent\SystemRegionAgentStoreDao;
use app\dao\organization\OrganizationAdminDao;
use app\dao\organization\OrganizationAdminStoreExcludeDao;
use app\dao\organization\OrganizationDao;
use app\dao\organization\OrganizationStoreDao;
use app\dao\store\SystemRegionManageDao;
use app\services\agent\SystemRegionAgentServices;
use app\services\BaseServices;
use app\services\store\SystemRegionManageServices;
use app\services\store\SystemStoreServices;
use think\facade\Db;

/**
 * 区域管理 → 组织架构 一次性迁移
 */
class OrganizationMigrateServices extends BaseServices
{
    /**
     * BaseDao::save 返回模型对象，统一取主键
     * @param mixed $saved
     */
    protected function extractId($saved): int
    {
        if (is_numeric($saved)) {
            return (int)$saved;
        }
        if (is_object($saved)) {
            if (isset($saved->id)) {
                return (int)$saved->id;
            }
            if (method_exists($saved, 'getAttr')) {
                return (int)$saved->getAttr('id');
            }
            if (method_exists($saved, 'toArray')) {
                $arr = $saved->toArray();
                return (int)($arr['id'] ?? 0);
            }
        }
        if (is_array($saved)) {
            return (int)($saved['id'] ?? 0);
        }
        return 0;
    }

    public function migrateFromLegacy(bool $dryRun = false): array
    {
        $report = [
            'organizations' => 0,
            'stores' => 0,
            'admins' => 0,
            'excludes' => 0,
            'skipped' => [],
        ];

        /** @var SystemRegionManageDao $legacyManageDao */
        $legacyManageDao = app()->make(SystemRegionManageDao::class);
        /** @var OrganizationDao $orgDao */
        $orgDao = app()->make(OrganizationDao::class);
        /** @var OrganizationStoreDao $orgStoreDao */
        $orgStoreDao = app()->make(OrganizationStoreDao::class);
        /** @var OrganizationAdminDao $adminDao */
        $adminDao = app()->make(OrganizationAdminDao::class);
        /** @var OrganizationAdminStoreExcludeDao $excludeDao */
        $excludeDao = app()->make(OrganizationAdminStoreExcludeDao::class);
        /** @var SystemRegionAgentServices $agentServices */
        $agentServices = app()->make(SystemRegionAgentServices::class);
        /** @var SystemRegionManageServices $manageServices */
        $manageServices = app()->make(SystemRegionManageServices::class);
        /** @var OrganizationScopeService $scopeService */
        $scopeService = app()->make(OrganizationScopeService::class);

        $legacyRegions = $legacyManageDao->getList(['is_del' => 0]);
        if (!$legacyRegions) {
            throw new \Exception('旧区域架构表无数据，无法迁移');
        }

        $legacyIdToOrgId = [];
        $time = time();

        Db::startTrans();
        try {
            foreach ($legacyRegions as $region) {
                $legacyId = (int)($region['id'] ?? 0);
                if ($legacyId <= 0) {
                    continue;
                }
                $exists = $orgDao->getOne(['legacy_manage_region_id' => $legacyId]);
                if ($exists) {
                    $legacyIdToOrgId[$legacyId] = $this->extractId($exists);
                    continue;
                }
                if ($dryRun) {
                    $legacyIdToOrgId[$legacyId] = $legacyId;
                    $report['organizations']++;
                    continue;
                }
                $orgId = $this->extractId($orgDao->save([
                    'pid' => (int)($region['pid'] ?? 0),
                    'name' => (string)($region['name'] ?? ''),
                    'sort' => (int)($region['sort'] ?? 0),
                    'legacy_manage_region_id' => $legacyId,
                    'is_del' => 0,
                    'add_time' => (int)($region['add_time'] ?? $time),
                    'update_time' => $time,
                ]));
                if ($orgId <= 0) {
                    throw new \Exception("组织写入失败：legacy_manage_region_id={$legacyId}");
                }
                $legacyIdToOrgId[$legacyId] = $orgId;
                $report['organizations']++;
            }

            // 修正 pid 映射到新 org id（若 id 不同）
            if (!$dryRun) {
                foreach ($legacyIdToOrgId as $legacyId => $orgId) {
                    $legacy = null;
                    foreach ($legacyRegions as $r) {
                        if ((int)$r['id'] === $legacyId) {
                            $legacy = $r;
                            break;
                        }
                    }
                    if (!$legacy) {
                        continue;
                    }
                    $legacyPid = (int)($legacy['pid'] ?? 0);
                    $newPid = $legacyPid > 0 ? ($legacyIdToOrgId[$legacyPid] ?? 0) : 0;
                    if ($newPid !== (int)($legacy['pid'] ?? 0)) {
                        $orgDao->update($orgId, ['pid' => $newPid]);
                    }
                }
            }

            // 门店归属
            $storeRows = Db::name('system_store')->where('is_del', 0)->field('id,region_id')->select()->toArray();
            foreach ($storeRows as $storeRow) {
                $storeId = (int)($storeRow['id'] ?? 0);
                $agentId = (int)($storeRow['region_id'] ?? 0);
                if ($storeId <= 0 || $agentId <= 0) {
                    continue;
                }
                $agent = $agentServices->get($agentId);
                if (!$agent || (int)($agent['is_del'] ?? 0) === 1) {
                    $report['skipped'][] = "store {$storeId}: agent {$agentId} invalid";
                    continue;
                }
                $agent = is_object($agent) ? $agent->toArray() : $agent;
                $manageRegionId = (int)($agent['manage_region_id'] ?? 0);
                if ($manageRegionId <= 0) {
                    $report['skipped'][] = "store {$storeId}: no manage_region_id";
                    continue;
                }
                $orgId = $legacyIdToOrgId[$manageRegionId] ?? 0;
                if ($orgId <= 0) {
                    $report['skipped'][] = "store {$storeId}: org not found for manage_region {$manageRegionId}";
                    continue;
                }
                if ($dryRun) {
                    $report['stores']++;
                    continue;
                }
                $existing = $orgStoreDao->getOne(['store_id' => $storeId]);
                if ($existing) {
                    $orgStoreDao->update($this->extractId($existing), ['org_id' => $orgId]);
                } else {
                    $orgStoreDao->save([
                        'org_id' => $orgId,
                        'store_id' => $storeId,
                        'add_time' => $time,
                    ]);
                }
                $report['stores']++;
            }

            // 管理员 + 排除关系（白名单 → 全量−排除）
            $agents = Db::name('system_region_agent')->where('is_del', 0)->select()->toArray();
            /** @var SystemRegionAgentStoreDao $agentStoreDao */
            $agentStoreDao = app()->make(SystemRegionAgentStoreDao::class);

            foreach ($agents as $agent) {
                $agent = is_array($agent) ? $agent : $agent->toArray();
                $agentId = (int)($agent['id'] ?? 0);
                $manageRegionId = (int)($agent['manage_region_id'] ?? 0);
                if ($agentId <= 0 || $manageRegionId <= 0) {
                    continue;
                }
                $orgId = $legacyIdToOrgId[$manageRegionId] ?? 0;
                if ($orgId <= 0) {
                    continue;
                }
                if ($dryRun) {
                    $report['admins']++;
                    continue;
                }
                $orgAdmin = $adminDao->getOne(['legacy_agent_id' => $agentId]);
                if ($orgAdmin) {
                    $orgAdminId = $this->extractId($orgAdmin);
                } else {
                    $adminId = 0;
                    $uid = 0;
                    $adminRow = Db::name('system_admin')
                        ->where('relation_id', $agentId)
                        ->where('is_del', 0)
                        ->find();
                    if ($adminRow) {
                        $adminId = (int)($adminRow['id'] ?? 0);
                        $uid = (int)($adminRow['uid'] ?? 0);
                    }
                    $orgAdminId = $this->extractId($adminDao->save([
                        'org_id' => $orgId,
                        'name' => (string)($agent['name'] ?? ''),
                        'phone' => (string)($agent['phone'] ?? ''),
                        'admin_id' => $adminId,
                        'uid' => $uid,
                        'legacy_agent_id' => $agentId,
                        'is_del' => 0,
                        'add_time' => $time,
                        'update_time' => $time,
                    ]));
                    if ($orgAdminId <= 0) {
                        throw new \Exception("管理员写入失败：legacy_agent_id={$agentId}");
                    }
                }
                $report['admins']++;

                $managedIds = $agentStoreDao->getColumn(['agent_id' => $agentId], 'store_id') ?: [];
                $managedIds = array_map('intval', $managedIds);
                if (!$managedIds) {
                    continue;
                }
                $orgStoreIds = $manageServices->getStoreIdsByManageRegion($manageRegionId, true);
                $excludeIds = array_values(array_diff($orgStoreIds, $managedIds));
                if (!$excludeIds) {
                    continue;
                }
                $excludeDao->delete(['org_admin_id' => $orgAdminId]);
                foreach ($excludeIds as $storeId) {
                    $excludeDao->save([
                        'org_admin_id' => $orgAdminId,
                        'store_id' => (int)$storeId,
                        'add_time' => $time,
                    ]);
                    $report['excludes']++;
                }
            }

            if ($dryRun) {
                Db::rollback();
            } else {
                Db::commit();
                OrganizationScopeService::clearCache();
            }
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $report['migrated'] = !$dryRun && $scopeService->isMigrated();
        return $report;
    }
}
