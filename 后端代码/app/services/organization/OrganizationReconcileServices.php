<?php
namespace app\services\organization;

use mohe\services\CacheService;
use think\facade\Db;
use think\facade\Log;

/**
 * O1 第三轮：迁移对账 vs 正式运行期原生完整性（禁止混成一套永久校验）
 */
class OrganizationReconcileServices
{
    public const MIGRATE_BATCH_ACTION = 'migrate_batch';
    public const MIGRATE_FAIL_ACTION = 'migrate_fail';

    public const RECONCILE_CACHE_KEY = 'organization:reconcile_report';
    public const NATIVE_CACHE_KEY = 'organization:native_integrity_report';
    public const CACHE_TTL = 60;

    /** @var bool 仅测试 */
    protected static $testQueryBoom = false;

    public static function setTestQueryBoom(bool $boom): void
    {
        self::$testQueryBoom = $boom;
    }

    public static function clearCaches(): void
    {
        try {
            CacheService::redisHandler()->delete(self::RECONCILE_CACHE_KEY);
            CacheService::redisHandler()->delete(self::NATIVE_CACHE_KEY);
        } catch (\Throwable $e) {
            // ignore cache clear failure here; callers that need strict publish handle redis separately
        }
    }

    /**
     * A. 迁移对账：legacy / dual_read / 首次切源前
     *
     * @param bool $forceRefresh
     * @param bool $requireSuccessfulBatch true 时校验成功批次 hash 与当前投影一致（切源门禁）
     */
    public function reconcileLegacyMigration(bool $forceRefresh = false, bool $requireSuccessfulBatch = false): array
    {
        if (!$forceRefresh && !$requireSuccessfulBatch) {
            $cached = $this->readCache(self::RECONCILE_CACHE_KEY);
            if ($cached !== null) {
                return $cached;
            }
        }

        $report = $this->computeReconcile();
        if ($requireSuccessfulBatch) {
            $this->applySuccessfulBatchGate($report);
        }
        if (!$requireSuccessfulBatch) {
            $this->writeCache(self::RECONCILE_CACHE_KEY, $report);
        }
        return $report;
    }

    /**
     * B. 正式运行期原生完整性：mode=organization
     * 允许 legacy_manage_region_id=0 / legacy_agent_id=0 的原生数据。
     */
    public function validateOrganizationNativeIntegrity(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = $this->readCache(self::NATIVE_CACHE_KEY);
            if ($cached !== null) {
                return $cached;
            }
        }
        $report = $this->computeNative();
        $this->writeCache(self::NATIVE_CACHE_KEY, $report);
        return $report;
    }

    /**
     * @return array{legacy_hash:string,new_projection_hash:string,projections:array}
     */
    public function buildProjectionHashes(): array
    {
        $data = $this->loadReconcileDataset();
        return $this->buildHashesFromDataset($data);
    }

    protected function computeReconcile(): array
    {
        $anomaly = $this->emptyReconcileAnomaly();
        $blockers = [];
        $warnings = [];
        $stats = $this->emptyStats();

        if (self::$testQueryBoom) {
            $anomaly['query_error_count'] = 1;
            return $this->failClosed('组织迁移对账查询异常，已失败关闭', $stats, $anomaly);
        }

        try {
            $data = $this->loadReconcileDataset();
        } catch (\Throwable $e) {
            $anomaly['query_error_count'] = 1;
            Log::error('组织迁移对账查询异常：' . $e->getMessage());
            return $this->failClosed('组织迁移对账查询异常，已失败关闭：' . $e->getMessage(), $stats, $anomaly);
        }

        $legacyOrgs = $data['legacy_orgs'];
        $orgs = $data['orgs'];
        $stores = $data['stores'];
        $orgStores = $data['org_stores'];
        $agents = $data['agents'];
        $admins = $data['admins'];

        $stats['legacy_org_count'] = count($legacyOrgs);
        $stats['mapped_org_count'] = count($orgs);
        $stats['active_store_count'] = count($stores);
        $stats['mapped_store_count'] = count($orgStores);
        $stats['legacy_agent_count'] = count($agents);
        $stats['mapped_admin_count'] = count($admins);

        $orgById = [];
        $orgByLegacy = [];
        foreach ($orgs as $org) {
            $oid = (int)$org['id'];
            $orgById[$oid] = $org;
            $legacyId = (int)($org['legacy_manage_region_id'] ?? 0);
            if ($legacyId > 0) {
                if (!isset($orgByLegacy[$legacyId])) {
                    $orgByLegacy[$legacyId] = [];
                }
                $orgByLegacy[$legacyId][] = $oid;
            }
        }

        $legacyById = [];
        foreach ($legacyOrgs as $row) {
            $legacyById[(int)$row['id']] = $row;
        }

        // 一一映射
        $dupLegacy = 0;
        $unmappedLegacy = 0;
        $unknownLegacyOnOrg = 0;
        $zeroLegacyPadding = 0;
        foreach ($orgByLegacy as $legacyId => $oids) {
            if (count($oids) > 1) {
                $dupLegacy += count($oids) - 1;
            }
            if (!isset($legacyById[$legacyId])) {
                $unknownLegacyOnOrg += count($oids);
            }
        }
        foreach ($legacyById as $legacyId => $_) {
            if (!isset($orgByLegacy[$legacyId]) || count($orgByLegacy[$legacyId]) !== 1) {
                $unmappedLegacy++;
            }
        }
        foreach ($orgs as $org) {
            if ((int)($org['legacy_manage_region_id'] ?? 0) <= 0) {
                $zeroLegacyPadding++;
            }
        }
        $anomaly['duplicate_legacy_org_map_count'] = $dupLegacy;
        $anomaly['unmapped_legacy_org_count'] = $unmappedLegacy;
        $anomaly['unknown_legacy_org_map_count'] = $unknownLegacyOnOrg;
        $anomaly['zero_legacy_org_padding_count'] = $zeroLegacyPadding;
        if ($dupLegacy > 0) {
            $blockers[] = "有 {$dupLegacy} 条重复的旧区域→组织映射";
        }
        if ($unmappedLegacy > 0) {
            $blockers[] = "有 {$unmappedLegacy} 个有效旧区域未唯一映射到有效新组织";
        }
        if ($unknownLegacyOnOrg > 0) {
            $blockers[] = "有 {$unknownLegacyOnOrg} 个新组织指向未知旧区域 ID";
        }
        if ($zeroLegacyPadding > 0) {
            $blockers[] = "迁移对账阶段不允许 legacy_manage_region_id=0 的组织补数量（{$zeroLegacyPadding}）";
        }

        // 父级必须等于旧区域 pid 对应的新组织
        $wrongParent = 0;
        foreach ($orgs as $org) {
            $legacyId = (int)($org['legacy_manage_region_id'] ?? 0);
            if ($legacyId <= 0 || !isset($legacyById[$legacyId])) {
                continue;
            }
            $legacyPid = (int)($legacyById[$legacyId]['pid'] ?? 0);
            $expectedParentOrgId = 0;
            if ($legacyPid > 0) {
                $expectedParentOrgId = (int)(($orgByLegacy[$legacyPid][0] ?? 0));
                if ($expectedParentOrgId <= 0) {
                    $wrongParent++;
                    continue;
                }
            }
            $actualPid = (int)($org['pid'] ?? 0);
            if ($actualPid !== $expectedParentOrgId) {
                $wrongParent++;
            }
        }
        $anomaly['wrong_org_parent_count'] = $wrongParent;
        if ($wrongParent > 0) {
            $blockers[] = "有 {$wrongParent} 个组织的父级与旧区域 pid 映射不一致";
        }

        // 门店：region_id → agent.manage_region_id → org
        $agentById = [];
        foreach ($agents as $a) {
            $agentById[(int)$a['id']] = $a;
        }
        $bindByStore = [];
        foreach ($orgStores as $bind) {
            $sid = (int)$bind['store_id'];
            $bindByStore[$sid][] = (int)$bind['org_id'];
        }
        $wrongStore = 0;
        $orphanStore = 0;
        $dupStore = 0;
        $invalidStoreOrg = 0;
        foreach ($stores as $store) {
            $sid = (int)$store['id'];
            $regionId = (int)($store['region_id'] ?? 0);
            $expectedLegacy = 0;
            if ($regionId > 0 && isset($agentById[$regionId])) {
                $expectedLegacy = (int)($agentById[$regionId]['manage_region_id'] ?? 0);
            }
            $expectedOrgId = $expectedLegacy > 0 ? (int)(($orgByLegacy[$expectedLegacy][0] ?? 0)) : 0;
            $binds = $bindByStore[$sid] ?? [];
            if (count($binds) > 1) {
                $dupStore += count($binds) - 1;
            }
            if (!$binds) {
                $orphanStore++;
                $wrongStore++;
                continue;
            }
            $actualOrgId = (int)$binds[0];
            if (!isset($orgById[$actualOrgId])) {
                $invalidStoreOrg++;
                $wrongStore++;
                continue;
            }
            if ($expectedOrgId <= 0 || $actualOrgId !== $expectedOrgId) {
                $wrongStore++;
            }
        }
        $anomaly['orphan_store_count'] = $orphanStore;
        $anomaly['duplicate_store_bind_count'] = $dupStore;
        $anomaly['invalid_org_store_org_count'] = $invalidStoreOrg;
        $anomaly['wrong_store_org_count'] = $wrongStore;
        $stats['orphan_store_count'] = $orphanStore;
        if ($wrongStore > 0) {
            $blockers[] = "有 {$wrongStore} 家门店的组织归属与旧区域业务链路不一致";
        }
        if ($dupStore > 0) {
            $blockers[] = "有 {$dupStore} 条重复的门店→组织绑定";
        }

        // 管理员：legacy_agent_id → agent.manage_region_id → org
        $adminsByAgent = [];
        $zeroAgentPadding = 0;
        $dupAgent = 0;
        $wrongAdmin = 0;
        $invalidAdminOrg = 0;
        $unknownAgent = 0;
        foreach ($admins as $admin) {
            $agentId = (int)($admin['legacy_agent_id'] ?? 0);
            $oid = (int)($admin['org_id'] ?? 0);
            if (!isset($orgById[$oid])) {
                $invalidAdminOrg++;
            }
            if ($agentId <= 0) {
                $zeroAgentPadding++;
                continue;
            }
            if (!isset($agentById[$agentId])) {
                $unknownAgent++;
            }
            if (!isset($adminsByAgent[$agentId])) {
                $adminsByAgent[$agentId] = [];
            }
            $adminsByAgent[$agentId][] = $admin;
        }
        foreach ($adminsByAgent as $agentId => $list) {
            if (count($list) > 1) {
                $dupAgent += count($list) - 1;
            }
        }
        $unmappedAgent = 0;
        foreach ($agentById as $agentId => $agent) {
            if (!isset($adminsByAgent[$agentId]) || count($adminsByAgent[$agentId]) !== 1) {
                $unmappedAgent++;
                continue;
            }
            $admin = $adminsByAgent[$agentId][0];
            $expectedLegacy = (int)($agent['manage_region_id'] ?? 0);
            $expectedOrgId = $expectedLegacy > 0 ? (int)(($orgByLegacy[$expectedLegacy][0] ?? 0)) : 0;
            $actualOrgId = (int)($admin['org_id'] ?? 0);
            if ($expectedOrgId <= 0 || $actualOrgId !== $expectedOrgId) {
                $wrongAdmin++;
            }
        }
        $anomaly['zero_legacy_agent_padding_count'] = $zeroAgentPadding;
        $anomaly['duplicate_legacy_agent_map_count'] = $dupAgent;
        $anomaly['unmapped_legacy_agent_count'] = $unmappedAgent;
        $anomaly['unknown_legacy_agent_map_count'] = $unknownAgent;
        $anomaly['invalid_admin_org_count'] = $invalidAdminOrg;
        $anomaly['wrong_admin_org_count'] = $wrongAdmin;
        if ($zeroAgentPadding > 0) {
            $blockers[] = "迁移对账阶段不允许 legacy_agent_id=0 的管理员补数量（{$zeroAgentPadding}）";
        }
        if ($dupAgent > 0) {
            $blockers[] = "有 {$dupAgent} 条重复的旧管理人员映射";
        }
        if ($unmappedAgent > 0) {
            $blockers[] = "有 {$unmappedAgent} 个有效旧区域管理员未唯一映射";
        }
        if ($unknownAgent > 0) {
            $blockers[] = "有 {$unknownAgent} 个组织管理员指向未知旧管理人员";
        }
        if ($invalidAdminOrg > 0) {
            $blockers[] = "有 {$invalidAdminOrg} 个组织管理员指向无效组织";
        }
        if ($wrongAdmin > 0) {
            $blockers[] = "有 {$wrongAdmin} 个组织管理员归属与旧区域业务链路不一致";
        }

        // 结构基础：单根、环（迁移阶段也要求）
        $roots = 0;
        foreach ($orgs as $org) {
            if ((int)($org['pid'] ?? 0) === 0) {
                $roots++;
            }
        }
        $anomaly['root_org_count'] = $roots;
        if (count($orgs) > 0 && $roots !== 1) {
            $blockers[] = '迁移对账要求有且仅有一个最高级组织，当前根组织数=' . $roots;
        }
        $hasCycle = $this->detectCycle($orgs);
        $anomaly['cycle_detected'] = $hasCycle ? 1 : 0;
        if ($hasCycle) {
            $blockers[] = '组织树存在循环引用';
        }
        if (count($orgs) <= 0) {
            $blockers[] = '新组织表无有效数据';
        }

        $hashes = $this->buildHashesFromDataset($data);
        $anomaly['legacy_hash'] = $hashes['legacy_hash'];
        $anomaly['new_projection_hash'] = $hashes['new_projection_hash'];
        $anomaly['missing_migrate_batch'] = 0;
        $anomaly['migrate_batch_hash_mismatch'] = 0;

        $blockers = array_values(array_unique($blockers));
        return [
            'kind' => 'reconcile',
            'passed' => empty($blockers),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'stats' => $stats,
            'anomaly' => $anomaly,
            'legacy_hash' => $hashes['legacy_hash'],
            'new_projection_hash' => $hashes['new_projection_hash'],
            'projections' => $hashes['projections'],
        ];
    }

    protected function applySuccessfulBatchGate(array &$report): void
    {
        $anomaly = $report['anomaly'] ?? $this->emptyReconcileAnomaly();
        $blockers = $report['blockers'] ?? [];
        try {
            $batch = Db::name('organization_change_log')
                ->where('action', self::MIGRATE_BATCH_ACTION)
                ->order('id', 'desc')
                ->find();
        } catch (\Throwable $e) {
            $anomaly['query_error_count'] = (int)($anomaly['query_error_count'] ?? 0) + 1;
            $blockers[] = '读取成功迁移批次失败：' . $e->getMessage();
            $report['anomaly'] = $anomaly;
            $report['blockers'] = array_values(array_unique($blockers));
            $report['passed'] = false;
            return;
        }
        if (empty($batch)) {
            $anomaly['missing_migrate_batch'] = 1;
            $blockers[] = '缺少成功迁移批次，不能仅凭表行数切源';
        } else {
            $anomaly['missing_migrate_batch'] = 0;
            $after = [];
            $raw = $batch['after_data'] ?? '';
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $after = $decoded;
                }
            }
            if (empty($after['reconcile_ok'])) {
                $blockers[] = '最近一次迁移批次对账结果未标记成功';
            }
            $batchId = trim((string)($after['batch_id'] ?? ''));
            if ($batchId === '') {
                $blockers[] = '成功迁移批次缺少 batch_id';
            }
            $legacyHash = (string)($after['legacy_hash'] ?? '');
            $newHash = (string)($after['new_projection_hash'] ?? '');
            if ($legacyHash === '' || $newHash === '') {
                $anomaly['migrate_batch_hash_mismatch'] = 1;
                $blockers[] = '成功迁移批次缺少投影 hash';
            } elseif ($legacyHash !== (string)($report['legacy_hash'] ?? '')
                || $newHash !== (string)($report['new_projection_hash'] ?? '')) {
                $anomaly['migrate_batch_hash_mismatch'] = 1;
                $blockers[] = '成功迁移批次 hash 与当前投影不一致，不能切源';
            } else {
                $anomaly['migrate_batch_hash_mismatch'] = 0;
            }
            $anomaly['batch_id'] = $batchId;
        }
        $report['anomaly'] = $anomaly;
        $report['blockers'] = array_values(array_unique($blockers));
        $report['passed'] = empty($report['blockers']);
    }

    protected function computeNative(): array
    {
        $anomaly = $this->emptyNativeAnomaly();
        $blockers = [];
        $warnings = [];
        $stats = $this->emptyStats();

        if (self::$testQueryBoom) {
            $anomaly['query_error_count'] = 1;
            return $this->failClosed('组织原生完整性查询异常，已失败关闭', $stats, $anomaly, 'native');
        }

        try {
            $orgs = Db::name('organization')->where('is_del', 0)->field('id,pid,name,legacy_manage_region_id')->select()->toArray();
            $stores = Db::name('system_store')->where('is_del', 0)->field('id')->select()->toArray();
            $orgStores = Db::name('organization_store')->field('id,org_id,store_id')->select()->toArray();
            $admins = Db::name('organization_admin')->where('is_del', 0)->field('id,org_id,legacy_agent_id')->select()->toArray();
        } catch (\Throwable $e) {
            $anomaly['query_error_count'] = 1;
            Log::error('组织原生完整性查询异常：' . $e->getMessage());
            return $this->failClosed('组织原生完整性查询异常，已失败关闭：' . $e->getMessage(), $stats, $anomaly, 'native');
        }

        $stats['mapped_org_count'] = count($orgs);
        $stats['active_store_count'] = count($stores);
        $stats['mapped_store_count'] = count($orgStores);
        $stats['mapped_admin_count'] = count($admins);

        $orgById = [];
        foreach ($orgs as $org) {
            $orgById[(int)$org['id']] = $org;
        }

        $roots = 0;
        $invalidParent = 0;
        foreach ($orgs as $org) {
            $pid = (int)($org['pid'] ?? 0);
            if ($pid === 0) {
                $roots++;
                continue;
            }
            if (!isset($orgById[$pid])) {
                $invalidParent++;
            }
        }
        $anomaly['root_org_count'] = $roots;
        $anomaly['invalid_parent_count'] = $invalidParent;
        if (count($orgs) > 0 && $roots !== 1) {
            $blockers[] = '正式运行期要求有且仅有一个最高级组织，当前根组织数=' . $roots;
        }
        if ($invalidParent > 0) {
            $blockers[] = "有 {$invalidParent} 个组织的上级无效或已删除";
        }
        $hasCycle = $this->detectCycle($orgs);
        $anomaly['cycle_detected'] = $hasCycle ? 1 : 0;
        if ($hasCycle) {
            $blockers[] = '组织树存在循环引用';
        }

        $bindByStore = [];
        $invalidStoreOrg = 0;
        foreach ($orgStores as $bind) {
            $sid = (int)$bind['store_id'];
            $oid = (int)$bind['org_id'];
            $bindByStore[$sid][] = $oid;
            if (!isset($orgById[$oid])) {
                $invalidStoreOrg++;
            }
        }
        $orphan = 0;
        $dup = 0;
        foreach ($stores as $store) {
            $sid = (int)$store['id'];
            $binds = $bindByStore[$sid] ?? [];
            if (!$binds) {
                $orphan++;
                continue;
            }
            if (count($binds) > 1) {
                $dup += count($binds) - 1;
            }
        }
        $anomaly['orphan_store_count'] = $orphan;
        $anomaly['duplicate_store_bind_count'] = $dup;
        $anomaly['invalid_org_store_org_count'] = $invalidStoreOrg;
        $stats['orphan_store_count'] = $orphan;
        if ($orphan > 0) {
            $blockers[] = "有 {$orphan} 家有效门店未归属组织";
        }
        if ($dup > 0) {
            $blockers[] = "有 {$dup} 条重复的门店→组织绑定";
        }
        if ($invalidStoreOrg > 0) {
            $blockers[] = "有 {$invalidStoreOrg} 条门店归属指向无效组织";
        }

        $invalidAdminOrg = 0;
        foreach ($admins as $admin) {
            $oid = (int)($admin['org_id'] ?? 0);
            if (!isset($orgById[$oid])) {
                $invalidAdminOrg++;
            }
        }
        $anomaly['invalid_admin_org_count'] = $invalidAdminOrg;
        if ($invalidAdminOrg > 0) {
            $blockers[] = "有 {$invalidAdminOrg} 个组织管理员指向无效组织";
        }
        // 负责人表 O2 才有：本阶段不引用 organization_leader

        if (count($orgs) <= 0) {
            $blockers[] = '新组织表无有效数据';
        }

        $blockers = array_values(array_unique($blockers));
        return [
            'kind' => 'native',
            'passed' => empty($blockers),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'stats' => $stats,
            'anomaly' => $anomaly,
        ];
    }

    /**
     * @return array{legacy_orgs:array,orgs:array,stores:array,org_stores:array,agents:array,admins:array}
     */
    protected function loadReconcileDataset(): array
    {
        return [
            'legacy_orgs' => Db::name('system_region_manage')->where('is_del', 0)->field('id,pid,name')->select()->toArray(),
            'orgs' => Db::name('organization')->where('is_del', 0)->field('id,pid,name,legacy_manage_region_id')->select()->toArray(),
            'stores' => Db::name('system_store')->where('is_del', 0)->field('id,region_id')->select()->toArray(),
            'org_stores' => Db::name('organization_store')->field('id,org_id,store_id')->select()->toArray(),
            'agents' => Db::name('system_region_agent')->where('is_del', 0)->field('id,manage_region_id')->select()->toArray(),
            'admins' => Db::name('organization_admin')->where('is_del', 0)->field('id,org_id,legacy_agent_id')->select()->toArray(),
        ];
    }

    /**
     * @param array $data
     * @return array{legacy_hash:string,new_projection_hash:string,projections:array}
     */
    protected function buildHashesFromDataset(array $data): array
    {
        $legacyProj = [];
        foreach ($data['legacy_orgs'] as $row) {
            $legacyProj[] = [(int)$row['id'], (int)$row['pid']];
        }
        usort($legacyProj, static function ($a, $b) {
            return $a[0] <=> $b[0];
        });

        $orgById = [];
        foreach ($data['orgs'] as $org) {
            $orgById[(int)$org['id']] = $org;
        }
        $orgProj = [];
        foreach ($data['orgs'] as $org) {
            $legacyId = (int)($org['legacy_manage_region_id'] ?? 0);
            if ($legacyId <= 0) {
                continue;
            }
            $pid = (int)($org['pid'] ?? 0);
            $parentLegacy = 0;
            if ($pid > 0 && isset($orgById[$pid])) {
                $parentLegacy = (int)($orgById[$pid]['legacy_manage_region_id'] ?? 0);
            }
            $orgProj[] = [$legacyId, $parentLegacy];
        }
        usort($orgProj, static function ($a, $b) {
            return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
        });

        $orgByLegacy = [];
        foreach ($data['orgs'] as $org) {
            $lid = (int)($org['legacy_manage_region_id'] ?? 0);
            if ($lid > 0) {
                $orgByLegacy[$lid] = (int)$org['id'];
            }
        }
        $agentById = [];
        foreach ($data['agents'] as $a) {
            $agentById[(int)$a['id']] = $a;
        }
        $bindByStore = [];
        foreach ($data['org_stores'] as $bind) {
            $bindByStore[(int)$bind['store_id']] = (int)$bind['org_id'];
        }
        $storeProj = [];
        foreach ($data['stores'] as $store) {
            $sid = (int)$store['id'];
            $regionId = (int)($store['region_id'] ?? 0);
            $expectedLegacy = 0;
            if ($regionId > 0 && isset($agentById[$regionId])) {
                $expectedLegacy = (int)($agentById[$regionId]['manage_region_id'] ?? 0);
            }
            $actualOrgId = (int)($bindByStore[$sid] ?? 0);
            $actualLegacy = 0;
            if ($actualOrgId > 0 && isset($orgById[$actualOrgId])) {
                $actualLegacy = (int)($orgById[$actualOrgId]['legacy_manage_region_id'] ?? 0);
            }
            $storeProj[] = [$sid, $expectedLegacy, $actualLegacy];
        }
        usort($storeProj, static function ($a, $b) {
            return $a[0] <=> $b[0];
        });

        $adminProj = [];
        foreach ($data['admins'] as $admin) {
            $agentId = (int)($admin['legacy_agent_id'] ?? 0);
            if ($agentId <= 0) {
                continue;
            }
            $expectedLegacy = 0;
            if (isset($agentById[$agentId])) {
                $expectedLegacy = (int)($agentById[$agentId]['manage_region_id'] ?? 0);
            }
            $actualOrgId = (int)($admin['org_id'] ?? 0);
            $actualLegacy = 0;
            if ($actualOrgId > 0 && isset($orgById[$actualOrgId])) {
                $actualLegacy = (int)($orgById[$actualOrgId]['legacy_manage_region_id'] ?? 0);
            }
            $adminProj[] = [$agentId, $expectedLegacy, $actualLegacy];
        }
        usort($adminProj, static function ($a, $b) {
            return $a[0] <=> $b[0];
        });

        $legacyHash = hash('sha256', json_encode($legacyProj, JSON_UNESCAPED_UNICODE));
        $newHash = hash('sha256', json_encode([
            'organization' => $orgProj,
            'store' => $storeProj,
            'admin' => $adminProj,
        ], JSON_UNESCAPED_UNICODE));

        return [
            'legacy_hash' => $legacyHash,
            'new_projection_hash' => $newHash,
            'projections' => [
                'legacy_region' => $legacyProj,
                'organization' => $orgProj,
                'store' => $storeProj,
                'admin' => $adminProj,
            ],
        ];
    }

    protected function detectCycle(array $list): bool
    {
        $pidMap = [];
        foreach ($list as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $pidMap[$id] = (int)($row['pid'] ?? 0);
            }
        }
        foreach ($pidMap as $start => $_) {
            $seen = [];
            $cur = $start;
            $guard = 0;
            $max = count($pidMap) + 2;
            while ($cur > 0) {
                if (isset($seen[$cur])) {
                    return true;
                }
                $seen[$cur] = true;
                $cur = (int)($pidMap[$cur] ?? 0);
                if (++$guard > $max) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function failClosed(string $msg, array $stats, array $anomaly, string $kind = 'reconcile'): array
    {
        return [
            'kind' => $kind,
            'passed' => false,
            'blockers' => [$msg],
            'warnings' => [],
            'stats' => $stats,
            'anomaly' => $anomaly,
            'legacy_hash' => '',
            'new_projection_hash' => '',
        ];
    }

    protected function emptyStats(): array
    {
        return [
            'legacy_org_count' => 0,
            'mapped_org_count' => 0,
            'active_store_count' => 0,
            'mapped_store_count' => 0,
            'legacy_agent_count' => 0,
            'mapped_admin_count' => 0,
            'orphan_store_count' => 0,
        ];
    }

    protected function emptyReconcileAnomaly(): array
    {
        return [
            'query_error_count' => 0,
            'root_org_count' => 0,
            'cycle_detected' => 0,
            'unmapped_legacy_org_count' => 0,
            'duplicate_legacy_org_map_count' => 0,
            'unknown_legacy_org_map_count' => 0,
            'zero_legacy_org_padding_count' => 0,
            'wrong_org_parent_count' => 0,
            'orphan_store_count' => 0,
            'duplicate_store_bind_count' => 0,
            'invalid_org_store_org_count' => 0,
            'wrong_store_org_count' => 0,
            'unmapped_legacy_agent_count' => 0,
            'duplicate_legacy_agent_map_count' => 0,
            'zero_legacy_agent_padding_count' => 0,
            'unknown_legacy_agent_map_count' => 0,
            'invalid_admin_org_count' => 0,
            'wrong_admin_org_count' => 0,
            'missing_migrate_batch' => 0,
            'migrate_batch_hash_mismatch' => 0,
            'legacy_hash' => '',
            'new_projection_hash' => '',
            'batch_id' => '',
        ];
    }

    protected function emptyNativeAnomaly(): array
    {
        return [
            'query_error_count' => 0,
            'root_org_count' => 0,
            'invalid_parent_count' => 0,
            'cycle_detected' => 0,
            'orphan_store_count' => 0,
            'duplicate_store_bind_count' => 0,
            'invalid_org_store_org_count' => 0,
            'invalid_admin_org_count' => 0,
        ];
    }

    protected function readCache(string $key): ?array
    {
        try {
            $raw = CacheService::redisHandler()->get($key);
            if (is_array($raw) && isset($raw['passed'])) {
                return $raw;
            }
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['passed'])) {
                    return $decoded;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }

    protected function writeCache(string $key, array $report): void
    {
        try {
            CacheService::redisHandler()->set($key, json_encode($report, JSON_UNESCAPED_UNICODE), self::CACHE_TTL);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
