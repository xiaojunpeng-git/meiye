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
        $list = $this->dao->getList(['is_del' => 0], 'id,pid,name,sort') ?: [];
        // Navigation tree deliberately excludes store/admin aggregates. Those
        // values belong to the selected organization's overview request.
        return $this->buildTreeFromRows($list, [], []);
    }

    /**
     * 内存构树（供接口与冒烟复用）。SQL：组织列表 + 直属门店分组 + 管理员分组，不随节点线性增加。
     *
     * @param array<int, array> $list
     * @param array<int, int>|null $directStoreMap
     * @param array<int, int>|null $adminCountMap
     */
    public function buildTreeFromRows(array $list, ?array $directStoreMap = null, ?array $adminCountMap = null): array
    {
        if ($this->scopeService->detectCycleInOrgRows($list)) {
            throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
        }
        if ($directStoreMap === null) {
            $directStoreMap = $this->getDirectStoreCountMap();
        }
        if ($adminCountMap === null) {
            $adminCountMap = $this->getAdminCountMap();
        }
        $allStoreMap = ($directStoreMap === [] && $adminCountMap === [])
            ? []
            : $this->computeAllStoreCounts($list, $directStoreMap);
        return $this->buildTreeNodesBatched($list, 0, $directStoreMap, $adminCountMap, $allStoreMap, []);
    }

    public function getRegionCountsMap(): array
    {
        $list = $this->dao->getList(['is_del' => 0], 'id,pid') ?: [];
        if ($this->scopeService->detectCycleInOrgRows($list)) {
            throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
        }
        $directStoreMap = $this->getDirectStoreCountMap();
        $adminCountMap = $this->getAdminCountMap();
        $allStoreMap = $this->computeAllStoreCounts($list, $directStoreMap);
        $map = [];
        foreach ($list as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $direct = (int)($directStoreMap[$id] ?? 0);
            $map[$id] = [
                // 兼容店员页：store_count = 直属门店数（与旧语义一致）
                'store_count' => $direct,
                // agent_count = 组织管理员数，不是员工数
                'agent_count' => (int)($adminCountMap[$id] ?? 0),
                'direct_store_count' => $direct,
                'all_store_count' => (int)($allStoreMap[$id] ?? 0),
            ];
        }
        return $map;
    }

    public function saveOrganization(int $id, array $data, int $operatorId = 0, string $operatorName = '', array $auditMeta = []): int
    {
        return (int)$this->withOrganizationStructureLock(function () use ($id, $data, $operatorId, $operatorName, $auditMeta) {
            Db::startTrans();
            try {
                $newId = $this->applySaveOrganization($id, $data, $operatorId, $operatorName, $auditMeta);
                Db::commit();
                OrganizationScopeService::clearCache();
                OrganizationScopeService::clearIntegrityCache();
                return $newId;
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
        });
    }

    /**
     * 锁内/事务内：保存组织（供 O4 编排层与内部封装复用）
     */
    public function applySaveOrganization(int $id, array $data, int $operatorId = 0, string $operatorName = '', array $auditMeta = []): int
    {
        $pid = (int)($data['pid'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $sort = (int)($data['sort'] ?? 0);
        $time = time();
        $save = [
            'pid' => $pid,
            'name' => $name,
            'sort' => $sort,
            'update_time' => $time,
        ];
        $this->lockAllOrganizationsForUpdate();
        $this->assertOrganizationSaveAllowed($id, $pid, $name, true);

        if ($id > 0) {
            $beforeRow = Db::name('organization')->where('id', $id)->where('is_del', 0)->find();
            if (!$beforeRow) {
                throw new \Exception('组织不存在或已删除');
            }
            $before = is_array($beforeRow) ? $beforeRow : (array)$beforeRow;
            $this->dao->update($id, $save);
            $all = Db::name('organization')->where('is_del', 0)->field('id,pid')->select()->toArray();
            if ($this->scopeService->detectCycleInOrgRows($all)) {
                throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
            }
            $this->writeLog($id, 'update', 'organization', $id, $before, $save, '编辑组织', $operatorId, $operatorName, $auditMeta);
            return $id;
        }

        $save['add_time'] = $time;
        $save['is_del'] = 0;
        $saved = $this->dao->save($save);
        $newId = is_object($saved) ? (int)$saved->id : (int)$saved;
        if ($newId <= 0) {
            throw new \Exception('组织保存失败');
        }
        $this->writeLog($newId, 'create', 'organization', $newId, [], $save, '新增组织', $operatorId, $operatorName, $auditMeta);
        return $newId;
    }

    /**
     * 锁内/事务内保存组织当前选择的统计维度。
     * 本方法只持久化选择本身，不对组织层级、维度代码或报表口径作业务判断。
     *
     * @return array{value:string,changed:bool}
     */
    public function applySaveOrganizationStatisticDimension(
        int $orgId,
        string $dimensionCode,
        int $operatorId = 0,
        string $operatorName = '',
        array $auditMeta = []
    ): array {
        $org = Db::name('organization')->where('id', $orgId)->where('is_del', 0)->lock(true)->find();
        if (!$org) {
            throw new \Exception('组织不存在或已删除');
        }

        $dimensionCode = trim($dimensionCode);
        if (strlen($dimensionCode) > 32) {
            throw new \Exception('统计维度长度不能超过 32 个字符');
        }

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $now = time();
        $rows = Db::name('cashier_v3_report_organization_dimension')
            ->where('tenant_id', '0')
            ->where('organization_id', (string)$orgId)
            ->where('enabled', 1)
            ->where('valid_from', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereNull('valid_to')->whereOr('valid_to', '>=', $today);
            })
            ->order('valid_from', 'desc')
            ->order('id', 'desc')
            ->lock(true)
            ->select()
            ->toArray();
        $current = $rows[0] ?? null;
        $currentValue = (string)($current['dimension_code'] ?? '');
        if (count($rows) === 1 && $currentValue === $dimensionCode) {
            return ['value' => $dimensionCode, 'changed' => false];
        }

        if ($rows) {
            Db::name('cashier_v3_report_organization_dimension')
                ->whereIn('id', array_map('intval', array_column($rows, 'id')))
                ->update([
                    'valid_to' => $yesterday,
                    'updated_by' => $operatorId,
                    'updated_at' => $now,
                    'version' => Db::raw('version + 1'),
                ]);
        }

        if ($dimensionCode !== '') {
            // 同日反复切换回已选值时复用当日记录，避免违反 (tenant, org, code, valid_from) 唯一键。
            $existing = Db::name('cashier_v3_report_organization_dimension')
                ->where('tenant_id', '0')
                ->where('organization_id', (string)$orgId)
                ->where('dimension_code', $dimensionCode)
                ->where('valid_from', $today)
                ->lock(true)
                ->find();
            $save = [
                'organization_name_snapshot' => (string)($org['name'] ?? ''),
                'valid_to' => null,
                'enabled' => 1,
                'updated_by' => $operatorId,
                'updated_at' => $now,
                'version' => Db::raw('version + 1'),
            ];
            if ($existing) {
                Db::name('cashier_v3_report_organization_dimension')->where('id', (int)$existing['id'])->update($save);
            } else {
                Db::name('cashier_v3_report_organization_dimension')->insert([
                    'tenant_id' => '0',
                    'organization_id' => (string)$orgId,
                    'organization_name_snapshot' => (string)($org['name'] ?? ''),
                    'dimension_code' => $dimensionCode,
                    'display_order' => 0,
                    'valid_from' => $today,
                    'valid_to' => null,
                    'enabled' => 1,
                    'version' => 1,
                    'created_by' => $operatorId,
                    'updated_by' => $operatorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $this->writeLog(
            $orgId,
            'save_statistic_dimension',
            'organization_statistic_dimension',
            $orgId,
            ['statistic_dimension_code' => $currentValue],
            ['statistic_dimension_code' => $dimensionCode],
            '保存组织统计维度',
            $operatorId,
            $operatorName,
            $auditMeta
        );

        return ['value' => $dimensionCode, 'changed' => true];
    }

    public function deleteOrganization(int $id, int $operatorId = 0, string $operatorName = '', array $auditMeta = []): bool
    {
        if ($id <= 0) {
            throw new \Exception('组织无效');
        }
        return (bool)$this->withOrganizationStructureLock(function () use ($id, $operatorId, $operatorName, $auditMeta) {
            Db::startTrans();
            try {
                $this->applyDeleteOrganization($id, $operatorId, $operatorName, $auditMeta);
                Db::commit();
                OrganizationScopeService::clearCache();
                OrganizationScopeService::clearIntegrityCache();
                return true;
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
        });
    }

    /**
     * 删除预检只读返回具体阻断项。最终删除仍在结构锁和事务内复核。
     */
    public function getDeleteBlockers(int $id): array
    {
        $org = Db::name('organization')->where('id', $id)->where('is_del', 0)->field('id,pid,name')->find();
        if (!$org) {
            throw new \Exception('组织不存在或已删除');
        }
        $blockers = [];
        if ((int)($org['pid'] ?? 0) === 0) {
            $blockers[] = ['type' => 'root', 'title' => '最高级组织', 'items' => [(string)($org['name'] ?? '')]];
        }
        $children = Db::name('organization')->where('pid', $id)->where('is_del', 0)->field('id,name')->select()->toArray();
        if ($children) {
            $blockers[] = ['type' => 'child_org', 'title' => '下级组织', 'items' => array_map(static function ($row) {
                return (string)($row['name'] ?? ('组织#' . (int)($row['id'] ?? 0)));
            }, $children)];
        }
        $stores = Db::name('organization_store')->alias('os')
            ->leftJoin('system_store st', 'st.id=os.store_id')
            ->where('os.org_id', $id)
            ->field('os.store_id,st.name,st.is_del')
            ->select()->toArray();
        if ($stores) {
            $blockers[] = ['type' => 'store', 'title' => '关联门店', 'items' => array_map(static function ($row) {
                $name = (string)($row['name'] ?? '已删除门店');
                return $name . ((int)($row['is_del'] ?? 0) === 1 ? '（已删除记录）' : '');
            }, $stores)];
        }
        $leaders = Db::name('organization_leader')->alias('l')
            ->leftJoin('employee e', 'e.id=l.employee_id')
            ->where('l.org_id', $id)->where('l.is_del', 0)
            ->field('l.employee_id,e.name')
            ->select()->toArray();
        if ($leaders) {
            $blockers[] = ['type' => 'leader', 'title' => '组织负责人', 'items' => array_map(static function ($row) {
                return (string)($row['name'] ?? ('员工#' . (int)($row['employee_id'] ?? 0)));
            }, $leaders)];
        }
        $admins = Db::name('organization_admin')->alias('oa')
            ->leftJoin('employee e', 'e.id=oa.employee_id')
            ->leftJoin('system_admin sa', 'sa.id=oa.admin_id')
            ->where('oa.org_id', $id)->where('oa.is_del', 0)
            ->field('oa.id,oa.employee_id,oa.name,e.name as employee_name,sa.account')
            ->select()->toArray();
        if ($admins) {
            $blockers[] = ['type' => 'admin', 'title' => '组织管理员授权', 'items' => array_map(static function ($row) {
                $name = (string)($row['employee_name'] ?? $row['name'] ?? ('员工#' . (int)($row['employee_id'] ?? 0)));
                $account = trim((string)($row['account'] ?? ''));
                return $account !== '' ? $name . '（' . $account . '）' : $name;
            }, $admins)];
        }
        $orphanCount = (int)(Db::query(
            'SELECT COUNT(*) AS c FROM `eb_organization_admin_store_exclude` ex LEFT JOIN `eb_organization_admin` a ON a.id = ex.org_admin_id WHERE a.id IS NULL'
        )[0]['c'] ?? 0);
        if ($orphanCount > 0) {
            $blockers[] = ['type' => 'orphan_exclude', 'title' => '异常排除门店数据', 'items' => [$orphanCount . ' 条孤儿记录']];
        }
        $deadAdminIds = array_map('intval', Db::name('organization_admin')->where('org_id', $id)->where('is_del', 1)->column('id') ?: []);
        if ($deadAdminIds) {
            $deadExcludeCount = (int)Db::name('organization_admin_store_exclude')->whereIn('org_admin_id', $deadAdminIds)->count();
            if ($deadExcludeCount > 0) {
                $blockers[] = ['type' => 'dead_admin_exclude', 'title' => '异常排除门店数据', 'items' => [$deadExcludeCount . ' 条已撤销授权残留']];
            }
        }
        return ['can_delete' => $blockers === [], 'blockers' => $blockers];
    }

    /**
     * 锁内/事务内：删除组织
     */
    public function applyDeleteOrganization(int $id, int $operatorId = 0, string $operatorName = '', array $auditMeta = []): void
    {
        $this->lockAllOrganizationsForUpdate();
        $beforeRow = Db::name('organization')->where('id', $id)->where('is_del', 0)->find();
        if (!$beforeRow) {
            throw new \Exception('组织不存在或已删除');
        }
        $before = is_array($beforeRow) ? $beforeRow : (array)$beforeRow;
        if ((int)($before['pid'] ?? 0) === 0) {
            throw new \Exception('最高级组织不能删除');
        }
        if ((int)$this->dao->count(['pid' => $id, 'is_del' => 0]) > 0) {
            throw new \Exception('请先删除下级组织');
        }
        if ((int)$this->orgStoreDao->count(['org_id' => $id]) > 0) {
            throw new \Exception('组织下仍有门店，无法删除');
        }
        $leaderCnt = (int)Db::name('organization_leader')->where('org_id', $id)->where('is_del', 0)->count();
        if ($leaderCnt > 0) {
            throw new \Exception('组织下仍有负责人，无法删除');
        }
        if ((int)$this->adminDao->count(['org_id' => $id, 'is_del' => 0]) > 0) {
            throw new \Exception('组织下仍有管理员，无法删除');
        }
        // 真正孤儿排除：exclude 指向已不存在的 organization_admin（fail-closed，不自动清理）
        $orphanRows = Db::query(
            'SELECT COUNT(*) AS c FROM `eb_organization_admin_store_exclude` ex
             LEFT JOIN `eb_organization_admin` a ON a.id = ex.org_admin_id
             WHERE a.id IS NULL'
        );
        $orphanCnt = (int)($orphanRows[0]['c'] ?? $orphanRows[0]['C'] ?? 0);
        if ($orphanCnt > 0) {
            throw new \Exception('组织存在异常排除门店数据，请先处理后再删除');
        }
        // 本组织已软删管理员仍挂着排除记录，同样 fail-closed
        $deadAdminIds = array_map('intval', Db::name('organization_admin')
            ->where('org_id', $id)->where('is_del', 1)->column('id') ?: []);
        if ($deadAdminIds) {
            $deadEx = (int)Db::name('organization_admin_store_exclude')->whereIn('org_admin_id', $deadAdminIds)->count();
            if ($deadEx > 0) {
                throw new \Exception('组织存在异常排除门店数据，请先处理后再删除');
            }
        }
        $this->dao->update($id, ['is_del' => 1, 'update_time' => time()]);
        $this->writeLog($id, 'delete', 'organization', $id, $before, ['is_del' => 1], '删除组织', $operatorId, $operatorName, $auditMeta);
    }

    /**
     * 组织结构变更串行锁：MySQL 命名锁 + 事务内按 id 顺序 FOR UPDATE。
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function withOrganizationStructureLock(callable $fn)
    {
        $lockName = 'mohe_organization_structure';
        $got = Db::query('SELECT GET_LOCK(?, 15) AS got', [$lockName]);
        $ok = (int)($got[0]['got'] ?? $got[0]['GOT'] ?? 0);
        if ($ok !== 1) {
            throw new \Exception('组织架构正在被其他人修改，请稍后重试');
        }
        try {
            return $fn();
        } finally {
            $released = 0;
            try {
                $rel = Db::query('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
                $released = (int)($rel[0]['released'] ?? $rel[0]['RELEASED'] ?? 0);
            } catch (\Throwable $e) {
                \think\facade\Log::error('组织架构命名锁 RELEASE_LOCK 异常：' . $e->getMessage());
                $released = 0;
            }
            if ($released !== 1) {
                \think\facade\Log::error('组织架构命名锁 RELEASE_LOCK 未返回 1，丢弃当前数据库连接以防锁泄漏');
                try {
                    Db::connect()->close();
                } catch (\Throwable $e) {
                    try {
                        // ThinkPHP 多连接时强制重连下一请求
                        Db::clear();
                    } catch (\Throwable $e2) {
                        // ignore
                    }
                }
            }
        }
    }

    /**
     * 按固定 id 顺序锁定全部有效组织行，消除并发移动窗口。
     */
    protected function lockAllOrganizationsForUpdate(): void
    {
        Db::name('organization')->where('is_del', 0)->order('id', 'asc')->lock(true)->field('id,pid,name')->select();
    }

    /**
     * 组织新增/编辑安全校验（可供冒烟只读调用，默认不写库）
     */
    public function assertOrganizationSaveAllowed(int $id, int $pid, string $name, bool $inTransaction = false): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \Exception('请输入组织名称');
        }
        if ($id > 0) {
            $current = $this->dao->get($id, ['id', 'pid', 'is_del']);
            if (!$current || (int)($current['is_del'] ?? 0) === 1) {
                throw new \Exception('组织不存在或已删除');
            }
            $current = is_object($current) ? $current->toArray() : $current;
            if ((int)($current['pid'] ?? 0) === 0 && $pid !== 0) {
                throw new \Exception('最高级组织不能移动');
            }
        }
        if ($id > 0 && $id === $pid) {
            throw new \Exception('上级组织不能是自己');
        }
        if ($pid > 0) {
            $parent = $this->dao->get($pid, ['id', 'is_del']);
            if (!$parent || (int)($parent['is_del'] ?? 0) === 1) {
                throw new \Exception('上级组织不存在或已删除');
            }
        } else {
            // 普通页面不能创建第二个最高级组织
            $rootWhere = ['pid' => 0, 'is_del' => 0];
            $rootCount = (int)$this->dao->count($rootWhere);
            if ($id <= 0 && $rootCount > 0) {
                throw new \Exception('已存在最高级组织，不能再创建');
            }
            if ($id > 0) {
                $otherRoot = Db::name('organization')
                    ->where('pid', 0)
                    ->where('is_del', 0)
                    ->where('id', '<>', $id)
                    ->count();
                if ((int)$otherRoot > 0) {
                    throw new \Exception('已存在最高级组织，不能再创建');
                }
            }
        }
        if ($id > 0 && $pid > 0) {
            $descendantIds = $this->collectDescendantOrgIdsSafe($id);
            if (in_array($pid, $descendantIds, true)) {
                throw new \Exception('上级组织不能是当前组织的下级');
            }
        }
        $dupQuery = Db::name('organization')
            ->where('pid', $pid)
            ->where('is_del', 0)
            ->where('name', $name);
        if ($id > 0) {
            $dupQuery->where('id', '<>', $id);
        }
        if ((int)$dupQuery->count() > 0) {
            throw new \Exception('同一上级下组织名称不能重复');
        }
        // 历史环数据：编辑前若整树已环，直接拒绝，避免卡死
        $all = $this->dao->getList(['is_del' => 0], 'id,pid') ?: [];
        if ($this->scopeService->detectCycleInOrgRows($all)) {
            throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
        }
        unset($inTransaction);
    }

    /**
     * 门店绑定组织（一店一组织，事务；与切源/组织变更共用结构锁）
     */
    public function bindStoreToOrg(int $storeId, int $orgId, int $operatorId = 0, string $operatorName = '', array $auditMeta = []): bool
    {
        return (bool)$this->withOrganizationStructureLock(function () use ($storeId, $orgId, $operatorId, $operatorName, $auditMeta) {
            Db::startTrans();
            try {
                $this->applyBindStoreToOrg($storeId, $orgId, $operatorId, $operatorName, $auditMeta);
                Db::commit();
                OrganizationScopeService::clearCache();
                return true;
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
        });
    }

    /**
     * 结构锁内/事务内：门店组织归属调整。
     * @return array{changed:bool,old_org_id:int,org_id:int}
     */
    public function applyBindStoreToOrg(int $storeId, int $orgId, int $operatorId = 0, string $operatorName = '', array $auditMeta = []): array
    {
        if ($storeId <= 0 || $orgId <= 0) {
            throw new \Exception('门店或组织无效');
        }
        // 固定锁序：组织行 → 门店行 → 归属行
        $org = Db::name('organization')->where('id', $orgId)->where('is_del', 0)->lock(true)->find();
        if (!$org) {
            throw new \Exception('组织不存在');
        }
        $store = Db::name('system_store')->where('id', $storeId)->lock(true)->find();
        if (!$store || (int)($store['is_del'] ?? 0) === 1) {
            throw new \Exception('门店不存在或已删除');
        }
        $existing = Db::name('organization_store')->where('store_id', $storeId)->lock(true)->find();
        $oldOrgId = $existing ? (int)($existing['org_id'] ?? 0) : 0;
        if ($existing && $oldOrgId === $orgId) {
            return ['changed' => false, 'old_org_id' => $oldOrgId, 'org_id' => $orgId];
        }
        if ($existing) {
            Db::name('organization_store')->where('id', (int)$existing['id'])->update(['org_id' => $orgId]);
        } else {
            Db::name('organization_store')->insert([
                'org_id' => $orgId,
                'store_id' => $storeId,
                'add_time' => time(),
            ]);
        }
        if ($oldOrgId > 0 && $oldOrgId !== $orgId) {
            $this->onStoreLeaveOrg($storeId, $oldOrgId);
        }
        $this->onStoreJoinOrg($storeId, $orgId);
        $this->writeLog($orgId, 'bind_store', 'store', $storeId, ['org_id' => $oldOrgId], ['org_id' => $orgId], '门店迁移组织', $operatorId, $operatorName, $auditMeta);
        return ['changed' => true, 'old_org_id' => $oldOrgId, 'org_id' => $orgId];
    }

    /**
     * 新门店加入组织：
     * - inherit：自然获得新门店
     * - custom：自动把新门店加入排除，维持原有 allowed 集合
     */
    protected function onStoreJoinOrg(int $storeId, int $orgId): void
    {
        $hasCol = $this->hasOrganizationAdminScopeModeColumn();
        $field = $hasCol ? 'id,scope_mode' : 'id';
        $admins = Db::name('organization_admin')
            ->where('org_id', $orgId)
            ->where('is_del', 0)
            ->field($field)
            ->select()
            ->toArray();
        if (!$admins) {
            return;
        }
        $time = time();
        foreach ($admins as $admin) {
            $orgAdminId = (int)$admin['id'];
            if ($hasCol) {
                $isCustom = strtolower(trim((string)($admin['scope_mode'] ?? 'inherit'))) === 'custom';
            } else {
                $isCustom = (int)Db::name('organization_admin_store_exclude')->where('org_admin_id', $orgAdminId)->count() > 0;
            }
            if (!$isCustom) {
                continue;
            }
            $exists = (int)Db::name('organization_admin_store_exclude')
                ->where('org_admin_id', $orgAdminId)
                ->where('store_id', $storeId)
                ->count();
            if ($exists > 0) {
                continue;
            }
            Db::name('organization_admin_store_exclude')->insert([
                'org_admin_id' => $orgAdminId,
                'store_id' => $storeId,
                'add_time' => $time,
            ]);
        }
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
    public function saveAdminStoreExcludes(int $orgAdminId, array $storeIds, int $operatorId = 0, string $operatorName = '', array $auditMeta = []): bool
    {
        return (bool)$this->withOrganizationStructureLock(function () use ($orgAdminId, $storeIds, $operatorId, $operatorName, $auditMeta) {
            Db::startTrans();
            try {
                $this->applySaveAdminStoreExcludes($orgAdminId, $storeIds, $operatorId, $operatorName, $auditMeta);
                Db::commit();
                OrganizationScopeService::clearCache();
                return true;
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
        });
    }

    /**
     * 锁内/事务内：按排除集合保存（旧语义 store_ids=排除门店）
     * @return array{changed:bool,org_id:int,before:array,after:array}
     */
    public function applySaveAdminStoreExcludes(int $orgAdminId, array $storeIds, int $operatorId = 0, string $operatorName = '', array $auditMeta = []): array
    {
        $admin = $this->adminDao->get($orgAdminId, ['id', 'org_id', 'is_del', 'employee_id']);
        if (!$admin || (int)($admin['is_del'] ?? 0) === 1) {
            throw new \Exception('管理员不存在');
        }
        $admin = is_object($admin) ? $admin->toArray() : $admin;
        $orgId = (int)$admin['org_id'];
        $employeeId = (int)($admin['employee_id'] ?? 0);
        $orgStoreIds = $this->getValidOrgStoreIdsForWrite($orgId);
        $orgStoreFlip = array_flip($orgStoreIds);
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        sort($storeIds);
        foreach ($storeIds as $storeId) {
            if (!isset($orgStoreFlip[$storeId])) {
                throw new \Exception('门店不在组织范围内');
            }
        }
        $before = array_map('intval', $this->excludeDao->getColumn(['org_admin_id' => $orgAdminId], 'store_id') ?: []);
        sort($before);
        $afterMode = $storeIds === [] ? 'inherit' : 'custom';
        $beforeMode = $this->resolveAdminScopeMode($orgAdminId, $before);
        if ($before === $storeIds && $beforeMode === $afterMode) {
            return ['changed' => false, 'org_id' => $orgId, 'before' => $before, 'after' => $storeIds];
        }
        $this->excludeDao->delete(['org_admin_id' => $orgAdminId]);
        $time = time();
        foreach ($storeIds as $storeId) {
            $this->excludeDao->save([
                'org_admin_id' => $orgAdminId,
                'store_id' => $storeId,
                'add_time' => $time,
            ]);
        }
        $this->updateOrganizationAdminScopeMode($orgAdminId, $afterMode);
        $this->writeLog(
            $orgId,
            'save_exclude',
            'org_admin',
            $orgAdminId,
            ['exclude' => $before, 'scope_mode' => $beforeMode],
            ['exclude' => $storeIds, 'scope_mode' => $afterMode],
            '更新管理员排除门店',
            $operatorId,
            $operatorName,
            $auditMeta
        );
        if ($employeeId > 0) {
            $this->writeEmployeeChangeLog(
                $employeeId,
                'org_admin_exclude',
                'org_admin',
                $orgAdminId,
                ['exclude' => $before, 'scope_mode' => $beforeMode],
                ['exclude' => $storeIds, 'scope_mode' => $afterMode],
                '调整组织排除门店',
                $operatorId,
                $operatorName,
                $auditMeta
            );
        }
        return ['changed' => true, 'org_id' => $orgId, 'before' => $before, 'after' => $storeIds];
    }

    /**
     * 有效门店（组织树 ∩ system_store.is_del=0）
     * @return int[]
     */
    public function getValidOrgStoreIdsForWrite(int $orgId): array
    {
        $ids = $this->scopeService->getOrgStoreIds($orgId, true) ?: [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $alive = Db::name('system_store')->whereIn('id', $ids)->where('is_del', 0)->column('id') ?: [];
        $alive = array_map('intval', $alive);
        sort($alive);
        return $alive;
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
        string $operatorName = '',
        array $auditMeta = []
    ): bool {
        $orgAdminId = $this->resolveOrgAdminIdByLegacyAgent($legacyAgentId);
        return $this->saveAdminStoreExcludes($orgAdminId, $storeIds, $operatorId, $operatorName, $auditMeta);
    }

    public function resolveOrgAdminIdByLegacyAgentPublic(int $legacyAgentId): int
    {
        return $this->resolveOrgAdminIdByLegacyAgent($legacyAgentId);
    }

    /**
     * 锁内/事务内：保存组织负责人集合（软删/复活，禁止硬删）
     * @param array<int, array{employee_id?:int,sort?:int}|int> $leaders
     * @return array{changed:bool,org_id:int,leaders:array,leader_count:int,before:array,after:array}
     */
    public function applySaveLeaders(int $orgId, array $leaders, int $operatorId = 0, string $operatorName = '', array $auditMeta = []): array
    {
        $org = Db::name('organization')->where('id', $orgId)->where('is_del', 0)->lock(true)->find();
        if (!$org) {
            throw new \Exception('组织不存在');
        }
        $normalized = [];
        foreach ($leaders as $item) {
            if (is_array($item)) {
                $eid = (int)($item['employee_id'] ?? 0);
                $sort = (int)($item['sort'] ?? 0);
            } else {
                $eid = (int)$item;
                $sort = 0;
            }
            if ($eid <= 0) {
                continue;
            }
            $normalized[$eid] = $sort;
        }
        // 稳定排序：按 sort 升序，再按 employee_id
        $pairs = [];
        foreach ($normalized as $eid => $sort) {
            $pairs[] = ['employee_id' => (int)$eid, 'sort' => (int)$sort];
        }
        usort($pairs, static function ($a, $b) {
            if ($a['sort'] === $b['sort']) {
                return $a['employee_id'] <=> $b['employee_id'];
            }
            return $a['sort'] <=> $b['sort'];
        });
        // 重排 sort 为稳定序号
        foreach ($pairs as $i => &$p) {
            $p['sort'] = $i + 1;
        }
        unset($p);

        $desiredIds = array_column($pairs, 'employee_id');
        $empNameMap = [];
        if ($desiredIds) {
            $empList = Db::name('employee')
                ->whereIn('id', $desiredIds)
                ->where('is_del', 0)
                ->where('status', 1)
                ->field('id,name')
                ->select()
                ->toArray();
            foreach ($empList as $er) {
                $empNameMap[(int)$er['id']] = (string)($er['name'] ?? '');
            }
            foreach ($desiredIds as $eid) {
                if (!isset($empNameMap[$eid])) {
                    throw new \Exception('负责人必须是在职且未删除的员工');
                }
            }
        }

        $existing = Db::name('organization_leader')->where('org_id', $orgId)->lock(true)->select()->toArray();
        $byEmp = [];
        foreach ($existing as $row) {
            $byEmp[(int)$row['employee_id']] = $row;
        }
        $beforeActive = [];
        foreach ($existing as $row) {
            if ((int)($row['is_del'] ?? 0) === 0) {
                $beforeActive[] = [
                    'employee_id' => (int)$row['employee_id'],
                    'sort' => (int)$row['sort'],
                ];
            }
        }
        usort($beforeActive, static function ($a, $b) {
            if ($a['sort'] === $b['sort']) {
                return $a['employee_id'] <=> $b['employee_id'];
            }
            return $a['sort'] <=> $b['sort'];
        });

        $time = time();
        $desiredFlip = array_flip($desiredIds);
        $changed = false;

        // 软删离开集
        foreach ($existing as $row) {
            $eid = (int)$row['employee_id'];
            if ((int)($row['is_del'] ?? 0) === 0 && !isset($desiredFlip[$eid])) {
                Db::name('organization_leader')->where('id', (int)$row['id'])->update([
                    'is_del' => 1,
                    'update_time' => $time,
                ]);
                $this->writeEmployeeChangeLog(
                    $eid,
                    'org_leader_remove',
                    'organization',
                    $orgId,
                    ['org_id' => $orgId, 'employee_id' => $eid, 'sort' => (int)$row['sort']],
                    ['is_del' => 1],
                    '移除组织负责人',
                    $operatorId,
                    $operatorName,
                    $auditMeta
                );
                $changed = true;
            }
        }

        // 新增或复活 / 更新 sort
        foreach ($pairs as $p) {
            $eid = (int)$p['employee_id'];
            $sort = (int)$p['sort'];
            if (isset($byEmp[$eid])) {
                $row = $byEmp[$eid];
                $need = ((int)($row['is_del'] ?? 0) === 1) || ((int)$row['sort'] !== $sort);
                if ($need) {
                    $wasDel = (int)($row['is_del'] ?? 0) === 1;
                    Db::name('organization_leader')->where('id', (int)$row['id'])->update([
                        'is_del' => 0,
                        'sort' => $sort,
                        'update_time' => $time,
                    ]);
                    if ($wasDel) {
                        $this->writeEmployeeChangeLog(
                            $eid,
                            'org_leader_add',
                            'organization',
                            $orgId,
                            ['is_del' => 1],
                            ['org_id' => $orgId, 'employee_id' => $eid, 'sort' => $sort],
                            '设置组织负责人',
                            $operatorId,
                            $operatorName,
                            $auditMeta
                        );
                    }
                    $changed = true;
                }
            } else {
                Db::name('organization_leader')->insert([
                    'org_id' => $orgId,
                    'employee_id' => $eid,
                    'sort' => $sort,
                    'is_del' => 0,
                    'add_time' => $time,
                    'update_time' => $time,
                ]);
                $this->writeEmployeeChangeLog(
                    $eid,
                    'org_leader_add',
                    'organization',
                    $orgId,
                    [],
                    ['org_id' => $orgId, 'employee_id' => $eid, 'sort' => $sort],
                    '设置组织负责人',
                    $operatorId,
                    $operatorName,
                    $auditMeta
                );
                $changed = true;
            }
        }

        $afterLeaders = [];
        foreach ($pairs as $p) {
            $eid = (int)$p['employee_id'];
            $afterLeaders[] = [
                'employee_id' => $eid,
                'sort' => (int)$p['sort'],
                'name' => (string)($empNameMap[$eid] ?? ''),
            ];
        }

        if ($changed) {
            $this->writeLog(
                $orgId,
                'save_leaders',
                'organization',
                $orgId,
                ['leaders' => $beforeActive],
                ['leaders' => array_map(static function ($x) {
                    return ['employee_id' => $x['employee_id'], 'sort' => $x['sort']];
                }, $afterLeaders)],
                '保存组织负责人',
                $operatorId,
                $operatorName,
                $auditMeta
            );
        }

        return [
            'changed' => $changed,
            'org_id' => $orgId,
            'leaders' => $afterLeaders,
            'leader_count' => count($afterLeaders),
            'before' => $beforeActive,
            'after' => array_map(static function ($x) {
                return ['employee_id' => $x['employee_id'], 'sort' => $x['sort']];
            }, $afterLeaders),
        ];
    }

    /**
     * 锁内/事务内：按 scope_mode + allowed_store_ids 保存权限范围（排除=组织有效门店−allowed）
     * @return array{changed:bool,org_id:int,org_admin_id:int,scope_mode:string,allowed_store_ids:array,excluded_store_ids:array}
     */
    public function applySaveAdminPermissionScope(
        int $orgAdminId,
        string $scopeMode,
        array $allowedStoreIds,
        int $operatorId = 0,
        string $operatorName = '',
        array $auditMeta = []
    ): array {
        $scopeMode = strtolower(trim($scopeMode));
        if (!in_array($scopeMode, ['inherit', 'custom'], true)) {
            throw new \Exception('权限范围模式无效');
        }
        $orgAdmin = Db::name('organization_admin')->where('id', $orgAdminId)->where('is_del', 0)->lock(true)->find();
        if (!$orgAdmin) {
            throw new \Exception('权限人员不存在');
        }
        $orgId = (int)$orgAdmin['org_id'];
        $employeeId = (int)($orgAdmin['employee_id'] ?? 0);
        $adminId = (int)($orgAdmin['admin_id'] ?? 0);
        if ($employeeId <= 0) {
            throw new \Exception('权限人员未关联员工，无法调整范围');
        }
        if ($adminId <= 0) {
            throw new \Exception('权限人员未关联后台账号，无法调整范围');
        }
        $sysAdmin = Db::name('system_admin')->where('id', $adminId)->lock(true)->find();
        if (!$sysAdmin || (int)($sysAdmin['is_del'] ?? 0) === 1) {
            throw new \Exception('后台账号不存在或已删除');
        }
        if ((int)($sysAdmin['status'] ?? 0) !== 1) {
            throw new \Exception('后台账号已停用');
        }
        if ((int)($sysAdmin['employee_id'] ?? 0) !== $employeeId) {
            throw new \Exception('后台账号与员工关联异常，无法调整范围');
        }

        $orgStoreIds = $this->getValidOrgStoreIdsForWrite($orgId);
        $orgFlip = array_flip($orgStoreIds);
        $allowedStoreIds = array_values(array_unique(array_filter(array_map('intval', $allowedStoreIds))));
        sort($allowedStoreIds);
        if ($scopeMode === 'inherit') {
            if ($allowedStoreIds !== []) {
                throw new \Exception('继承组织范围时请勿传入可管理门店');
            }
            $excludeIds = [];
        } else {
            foreach ($allowedStoreIds as $sid) {
                if (!isset($orgFlip[$sid])) {
                    throw new \Exception('可管理门店超出组织范围');
                }
            }
            $allowedFlip = array_flip($allowedStoreIds);
            $excludeIds = [];
            foreach ($orgStoreIds as $sid) {
                if (!isset($allowedFlip[$sid])) {
                    $excludeIds[] = $sid;
                }
            }
            sort($excludeIds);
        }

        $beforeExclude = array_map('intval', $this->excludeDao->getColumn(['org_admin_id' => $orgAdminId], 'store_id') ?: []);
        sort($beforeExclude);
        $beforeMode = $this->resolveAdminScopeMode($orgAdminId, $beforeExclude, is_array($orgAdmin) ? $orgAdmin : null);
        $beforeAllowed = $beforeMode === 'inherit'
            ? []
            : array_values(array_diff($orgStoreIds, $beforeExclude));
        sort($beforeAllowed);

        $afterAllowed = $scopeMode === 'inherit' ? [] : $allowedStoreIds;
        if ($beforeExclude === $excludeIds && $beforeMode === $scopeMode) {
            return [
                'changed' => false,
                'org_id' => $orgId,
                'org_admin_id' => $orgAdminId,
                'scope_mode' => $scopeMode,
                'allowed_store_ids' => $afterAllowed,
                'excluded_store_ids' => $excludeIds,
            ];
        }

        $this->excludeDao->delete(['org_admin_id' => $orgAdminId]);
        $time = time();
        foreach ($excludeIds as $sid) {
            $this->excludeDao->save([
                'org_admin_id' => $orgAdminId,
                'store_id' => $sid,
                'add_time' => $time,
            ]);
        }
        $this->updateOrganizationAdminScopeMode($orgAdminId, $scopeMode);

        $this->writeLog(
            $orgId,
            'save_admin_permission',
            'org_admin',
            $orgAdminId,
            ['scope_mode' => $beforeMode, 'allowed_store_ids' => $beforeAllowed, 'excluded_store_ids' => $beforeExclude],
            ['scope_mode' => $scopeMode, 'allowed_store_ids' => $afterAllowed, 'excluded_store_ids' => $excludeIds],
            '保存权限范围',
            $operatorId,
            $operatorName,
            $auditMeta
        );
        $this->writeEmployeeChangeLog(
            $employeeId,
            'org_admin_permission',
            'org_admin',
            $orgAdminId,
            ['scope_mode' => $beforeMode, 'allowed_store_ids' => $beforeAllowed, 'excluded_store_ids' => $beforeExclude],
            ['scope_mode' => $scopeMode, 'allowed_store_ids' => $afterAllowed, 'excluded_store_ids' => $excludeIds],
            '调整组织权限范围',
            $operatorId,
            $operatorName,
            $auditMeta
        );

        return [
            'changed' => true,
            'org_id' => $orgId,
            'org_admin_id' => $orgAdminId,
            'scope_mode' => $scopeMode,
            'allowed_store_ids' => $afterAllowed,
            'excluded_store_ids' => $excludeIds,
        ];
    }

    public function writeEmployeeChangeLog(
        int $employeeId,
        string $action,
        string $targetType,
        int $targetId,
        array $before,
        array $after,
        string $reason,
        int $operatorId,
        string $operatorName,
        array $auditMeta = []
    ): void {
        if ($employeeId <= 0) {
            return;
        }
        $beforeJson = '';
        $afterJson = '';
        if ($before) {
            $beforeJson = json_encode($before, JSON_UNESCAPED_UNICODE);
            if ($beforeJson === false) {
                throw new \Exception('员工审计序列化失败');
            }
        }
        if ($after) {
            $afterJson = json_encode($after, JSON_UNESCAPED_UNICODE);
            if ($afterJson === false) {
                throw new \Exception('员工审计序列化失败');
            }
        }
        Db::name('employee_change_log')->insert([
            'employee_id' => $employeeId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'source' => 'admin',
            'before_data' => $beforeJson,
            'after_data' => $afterJson,
            'reason' => $reason,
            'operator_type' => 'admin',
            'operator_id' => $operatorId,
            'operator_name' => $operatorName,
            'operator_ip' => (string)($auditMeta['operator_ip'] ?? ''),
            'request_id' => (string)($auditMeta['request_id'] ?? ''),
            'add_time' => time(),
        ]);
    }

    /**
     * @param int[] $excludeIds
     * @param array|null $adminRow
     */
    public function resolveAdminScopeMode(int $orgAdminId, array $excludeIds = [], ?array $adminRow = null): string
    {
        if ($this->hasOrganizationAdminScopeModeColumn()) {
            if ($adminRow !== null && array_key_exists('scope_mode', $adminRow)) {
                $mode = strtolower(trim((string)$adminRow['scope_mode']));
            } else {
                $mode = strtolower(trim((string)Db::name('organization_admin')->where('id', $orgAdminId)->value('scope_mode')));
            }
            if ($mode === 'custom' || $mode === 'inherit') {
                return $mode;
            }
        }
        return $excludeIds === [] ? 'inherit' : 'custom';
    }

    public function updateOrganizationAdminScopeMode(int $orgAdminId, string $scopeMode): void
    {
        if (!$this->hasOrganizationAdminScopeModeColumn()) {
            return;
        }
        $scopeMode = strtolower(trim($scopeMode));
        if (!in_array($scopeMode, ['inherit', 'custom'], true)) {
            throw new \Exception('权限范围模式无效');
        }
        Db::name('organization_admin')->where('id', $orgAdminId)->update([
            'scope_mode' => $scopeMode,
            'update_time' => time(),
        ]);
    }

    /**
     * 仅正向缓存 true；未安装时每次重查
     */
    public function hasOrganizationAdminScopeModeColumn(): bool
    {
        static $positive = false;
        if ($positive) {
            return true;
        }
        try {
            $cols = Db::query("SHOW COLUMNS FROM `eb_organization_admin` LIKE 'scope_mode'");
            $positive = !empty($cols);
            return $positive;
        } catch (\Throwable $e) {
            return false;
        }
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

    /**
     * @return array<int, int>
     */
    protected function getDirectStoreCountMap(): array
    {
        $rows = Db::name('organization_store')
            ->field('org_id, COUNT(*) as cnt')
            ->group('org_id')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['org_id']] = (int)$row['cnt'];
        }
        return $map;
    }

    /**
     * @return array<int, int>
     */
    protected function getAdminCountMap(): array
    {
        $rows = Db::name('organization_admin')
            ->where('is_del', 0)
            ->field('org_id, COUNT(*) as cnt')
            ->group('org_id')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['org_id']] = (int)$row['cnt'];
        }
        return $map;
    }

    /**
     * 内存汇总：直属 + 全部下级门店数；带 visited 防循环。
     *
     * @param array<int, array> $list
     * @param array<int, int> $directStoreMap
     * @return array<int, int>
     */
    protected function computeAllStoreCounts(array $list, array $directStoreMap): array
    {
        $children = [];
        foreach ($list as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $children[(int)($row['pid'] ?? 0)][] = $id;
        }
        $all = [];
        $visiting = [];
        $compute = function (int $id) use (&$compute, &$all, &$visiting, $children, $directStoreMap): int {
            if (isset($all[$id])) {
                return $all[$id];
            }
            if (isset($visiting[$id])) {
                throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
            }
            $visiting[$id] = true;
            $sum = (int)($directStoreMap[$id] ?? 0);
            foreach ($children[$id] ?? [] as $childId) {
                $sum += $compute((int)$childId);
            }
            unset($visiting[$id]);
            $all[$id] = $sum;
            return $sum;
        };
        foreach ($list as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $compute($id);
            }
        }
        return $all;
    }

    /**
     * @param array<int, array> $list
     * @param array<int, int> $directStoreMap
     * @param array<int, int> $adminCountMap
     * @param array<int, int> $allStoreMap
     * @param array<int, bool> $ancestors
     */
    protected function buildTreeNodesBatched(
        array $list,
        int $pid,
        array $directStoreMap,
        array $adminCountMap,
        array $allStoreMap,
        array $ancestors
    ): array {
        $branch = [];
        foreach ($list as $row) {
            if ((int)($row['pid'] ?? 0) !== $pid) {
                continue;
            }
            $id = (int)$row['id'];
            if ($id <= 0) {
                continue;
            }
            if (isset($ancestors[$id])) {
                throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
            }
            $nextAncestors = $ancestors + [$id => true];
            $direct = (int)($directStoreMap[$id] ?? 0);
            $branch[] = [
                'id' => $id,
                'pid' => (int)($row['pid'] ?? 0),
                'name' => $row['name'] ?? '',
                'sort' => (int)($row['sort'] ?? 0),
                // 兼容：store_count 保持直属门店数语义
                'store_count' => $direct,
                // agent_count = 组织管理员数，不是员工数
                'agent_count' => (int)($adminCountMap[$id] ?? 0),
                'direct_store_count' => $direct,
                'all_store_count' => (int)($allStoreMap[$id] ?? 0),
                'children' => $this->buildTreeNodesBatched(
                    $list,
                    $id,
                    $directStoreMap,
                    $adminCountMap,
                    $allStoreMap,
                    $nextAncestors
                ),
            ];
        }
        return $branch;
    }

    /**
     * @return int[] 不含自身
     */
    protected function collectDescendantOrgIdsSafe(int $orgId): array
    {
        $all = $this->dao->getList(['is_del' => 0], 'id,pid') ?: [];
        if ($this->scopeService->detectCycleInOrgRows($all)) {
            throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
        }
        $children = [];
        foreach ($all as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $children[(int)($row['pid'] ?? 0)][] = $id;
        }
        $result = [];
        $queue = [$orgId];
        $visited = [$orgId => true];
        $guard = 0;
        $max = count($all) + 2;
        while ($queue) {
            if (++$guard > $max) {
                throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
            }
            $current = (int)array_shift($queue);
            foreach ($children[$current] ?? [] as $childId) {
                $childId = (int)$childId;
                if ($childId <= 0) {
                    continue;
                }
                if (isset($visited[$childId])) {
                    throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
                }
                $visited[$childId] = true;
                $result[] = $childId;
                $queue[] = $childId;
            }
        }
        return $result;
    }

    /**
     * 授权组织权限人员（仅建/复活 organization_admin 关系，不改 system_admin 账号密码角色）
     * @param int[] $allowedStoreIds
     * @return array{changed:bool,idempotent:bool,org_id:int,org_admin_id:int,employee_id:int,admin_id:int,scope_mode:string,allowed_store_ids:array}
     */
    public function applyGrantAdmin(
        int $orgId,
        int $employeeId,
        int $adminId,
        string $scopeMode,
        array $allowedStoreIds,
        int $operatorId = 0,
        string $operatorName = '',
        array $auditMeta = []
    ): array {
        $orgId = (int)$orgId;
        $employeeId = (int)$employeeId;
        $adminId = (int)$adminId;
        $scopeMode = strtolower(trim($scopeMode));
        if ($orgId <= 0 || $employeeId <= 0 || $adminId <= 0) {
            throw new \Exception('授权参数不完整');
        }
        if (!in_array($scopeMode, ['inherit', 'custom'], true)) {
            throw new \Exception('权限范围模式无效');
        }

        $org = Db::name('organization')->where('id', $orgId)->where('is_del', 0)->lock(true)->find();
        if (!$org) {
            throw new \Exception('组织不存在');
        }
        $employee = Db::name('employee')->where('id', $employeeId)->lock(true)->find();
        if (!$employee || (int)($employee['is_del'] ?? 0) === 1 || (int)($employee['status'] ?? 0) !== 1) {
            throw new \Exception('员工不存在或非在职');
        }
        $sysAdmin = Db::name('system_admin')->where('id', $adminId)->lock(true)->find();
        if (!$sysAdmin || (int)($sysAdmin['is_del'] ?? 0) === 1) {
            throw new \Exception('后台账号不存在或已删除');
        }
        if ((int)($sysAdmin['status'] ?? 0) !== 1) {
            throw new \Exception('后台账号已停用');
        }
        if ((int)($sysAdmin['employee_id'] ?? 0) !== $employeeId) {
            throw new \Exception('后台账号未精确绑定该员工，禁止授权');
        }

        $orgStoreIds = $this->getValidOrgStoreIdsForWrite($orgId);
        $orgFlip = array_flip($orgStoreIds);
        $allowedStoreIds = array_values(array_unique(array_filter(array_map('intval', $allowedStoreIds))));
        sort($allowedStoreIds);
        if ($scopeMode === 'inherit') {
            if ($allowedStoreIds !== []) {
                throw new \Exception('继承组织范围时请勿传入可管理门店');
            }
        } else {
            if ($allowedStoreIds === []) {
                // custom 允许空 allowed（即全部排除），与范围保存一致按 store 校验
            }
            foreach ($allowedStoreIds as $sid) {
                if (!isset($orgFlip[$sid])) {
                    throw new \Exception('可管理门店超出组织范围');
                }
            }
        }

        $existing = Db::name('organization_admin')
            ->where('org_id', $orgId)
            ->where('admin_id', $adminId)
            ->lock(true)
            ->find();
        $time = time();
        $snapshotName = (string)($employee['name'] ?? '');
        $snapshotPhone = (string)($employee['phone'] ?? '');
        $snapshotUid = (int)($employee['uid'] ?? 0);
        $changed = false;
        $idempotent = false;

        if ($existing) {
            $orgAdminId = (int)$existing['id'];
            if ((int)($existing['is_del'] ?? 0) === 0
                && (int)($existing['employee_id'] ?? 0) === $employeeId
            ) {
                $idempotent = true;
            } else {
                Db::name('organization_admin')->where('id', $orgAdminId)->update([
                    'employee_id' => $employeeId,
                    'name' => $snapshotName,
                    'phone' => $snapshotPhone,
                    'uid' => $snapshotUid > 0 ? $snapshotUid : 0,
                    'admin_id' => $adminId,
                    'is_del' => 0,
                    'update_time' => $time,
                ]);
                $changed = true;
            }
        } else {
            $orgAdminId = (int)Db::name('organization_admin')->insertGetId([
                'employee_id' => $employeeId,
                'org_id' => $orgId,
                'name' => $snapshotName,
                'phone' => $snapshotPhone,
                'admin_id' => $adminId,
                'uid' => $snapshotUid > 0 ? $snapshotUid : 0,
                'legacy_agent_id' => 0,
                'is_del' => 0,
                'scope_mode' => 'inherit',
                'add_time' => $time,
                'update_time' => $time,
            ]);
            if ($orgAdminId <= 0) {
                throw new \Exception('创建组织权限关系失败');
            }
            $changed = true;
        }

        $scopeRet = $this->applySaveAdminPermissionScope(
            $orgAdminId,
            $scopeMode,
            $allowedStoreIds,
            $operatorId,
            $operatorName,
            $auditMeta
        );
        if (!empty($scopeRet['changed'])) {
            $changed = true;
            $idempotent = false;
        }

        if ($changed) {
            $this->writeLog(
                $orgId,
                $existing && (int)($existing['is_del'] ?? 0) === 1 ? 'revive_admin_grant' : 'grant_admin',
                'org_admin',
                $orgAdminId,
                $existing ? [
                    'org_admin_id' => $orgAdminId,
                    'employee_id' => (int)($existing['employee_id'] ?? 0),
                    'admin_id' => (int)($existing['admin_id'] ?? 0),
                    'is_del' => (int)($existing['is_del'] ?? 0),
                ] : [],
                [
                    'org_admin_id' => $orgAdminId,
                    'employee_id' => $employeeId,
                    'admin_id' => $adminId,
                    'scope_mode' => $scopeMode,
                    'allowed_store_ids' => $scopeMode === 'inherit' ? [] : $allowedStoreIds,
                ],
                $existing && (int)($existing['is_del'] ?? 0) === 1 ? '复活组织权限人员' : '授权组织权限人员',
                $operatorId,
                $operatorName,
                $auditMeta
            );
            $this->writeEmployeeChangeLog(
                $employeeId,
                'org_admin_grant',
                'org_admin',
                $orgAdminId,
                [],
                [
                    'org_id' => $orgId,
                    'admin_id' => $adminId,
                    'scope_mode' => $scopeMode,
                    'allowed_store_ids' => $scopeMode === 'inherit' ? [] : $allowedStoreIds,
                ],
                '授权组织后台权限',
                $operatorId,
                $operatorName,
                $auditMeta
            );
        }

        return [
            'changed' => $changed,
            'idempotent' => $idempotent && !$changed,
            'org_id' => $orgId,
            'org_admin_id' => $orgAdminId,
            'employee_id' => $employeeId,
            'admin_id' => $adminId,
            'scope_mode' => (string)$scopeRet['scope_mode'],
            'allowed_store_ids' => $scopeRet['allowed_store_ids'],
        ];
    }

    /**
     * 撤销组织权限：仅软删 organization_admin，清理排除明细；不删员工/后台账号/系统角色
     * @return array{changed:bool,org_id:int,org_admin_id:int,employee_id:int,admin_id:int}
     */
    public function applyRevokeAdminGrant(
        int $orgId,
        int $orgAdminId,
        int $operatorId = 0,
        string $operatorName = '',
        array $auditMeta = []
    ): array {
        $orgId = (int)$orgId;
        $orgAdminId = (int)$orgAdminId;
        if ($orgId <= 0 || $orgAdminId <= 0) {
            throw new \Exception('撤销参数不完整');
        }
        $org = Db::name('organization')->where('id', $orgId)->where('is_del', 0)->lock(true)->find();
        if (!$org) {
            throw new \Exception('组织不存在');
        }
        $row = Db::name('organization_admin')->where('id', $orgAdminId)->lock(true)->find();
        if (!$row || (int)($row['org_id'] ?? 0) !== $orgId) {
            throw new \Exception('组织权限关系不存在');
        }
        if ((int)($row['is_del'] ?? 0) === 1) {
            return [
                'changed' => false,
                'org_id' => $orgId,
                'org_admin_id' => $orgAdminId,
                'employee_id' => (int)($row['employee_id'] ?? 0),
                'admin_id' => (int)($row['admin_id'] ?? 0),
            ];
        }
        $employeeId = (int)($row['employee_id'] ?? 0);
        $adminId = (int)($row['admin_id'] ?? 0);
        $beforeExclude = array_map('intval', $this->excludeDao->getColumn(['org_admin_id' => $orgAdminId], 'store_id') ?: []);
        sort($beforeExclude);
        $this->excludeDao->delete(['org_admin_id' => $orgAdminId]);
        Db::name('organization_admin')->where('id', $orgAdminId)->update([
            'is_del' => 1,
            'update_time' => time(),
        ]);
        $this->writeLog(
            $orgId,
            'revoke_admin_grant',
            'org_admin',
            $orgAdminId,
            [
                'employee_id' => $employeeId,
                'admin_id' => $adminId,
                'scope_mode' => (string)($row['scope_mode'] ?? ''),
                'excluded_store_ids' => $beforeExclude,
                'is_del' => 0,
            ],
            ['is_del' => 1],
            '撤销组织权限人员',
            $operatorId,
            $operatorName,
            $auditMeta
        );
        if ($employeeId > 0) {
            $this->writeEmployeeChangeLog(
                $employeeId,
                'org_admin_revoke',
                'org_admin',
                $orgAdminId,
                ['org_id' => $orgId, 'admin_id' => $adminId, 'is_del' => 0],
                ['is_del' => 1],
                '撤销组织后台权限',
                $operatorId,
                $operatorName,
                $auditMeta
            );
        }
        return [
            'changed' => true,
            'org_id' => $orgId,
            'org_admin_id' => $orgAdminId,
            'employee_id' => $employeeId,
            'admin_id' => $adminId,
        ];
    }

    public function writeLog(
        int $orgId,
        string $action,
        string $targetType,
        int $targetId,
        array $before,
        array $after,
        string $remark,
        int $operatorId,
        string $operatorName,
        array $auditMeta = []
    ): void {
        // O4 HTTP：auditMeta 含 request_token 时走编排事务；CLI smoke 可一次性注入故障以验证整单回滚
        $token = trim((string)($auditMeta['request_token'] ?? ''));
        if ($token !== '' && OrganizationWorkspaceWriteServices::consumeCliAuditFaultOnce()) {
            throw new \Exception(OrganizationWorkspaceWriteServices::ERR_CLI_AUDIT_FAULT);
        }

        $beforeJson = '';
        $afterJson = '';
        if ($before) {
            $beforeJson = json_encode($before, JSON_UNESCAPED_UNICODE);
            if ($beforeJson === false) {
                throw new \Exception('组织审计序列化失败');
            }
        }
        if ($after) {
            $afterJson = json_encode($after, JSON_UNESCAPED_UNICODE);
            if ($afterJson === false) {
                throw new \Exception('组织审计序列化失败');
            }
        }
        $row = [
            'org_id' => $orgId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before_data' => $beforeJson,
            'after_data' => $afterJson,
            'remark' => $remark,
            'operator_id' => $operatorId,
            'operator_name' => $operatorName,
            'add_time' => time(),
        ];
        // O4 HTTP：auditMeta 含 request_token 时必须写三新字段；失败整单回滚，禁止降级
        // 内部可信调用无 request_token：只写旧字段，保证 011 前可用
        if ($token !== '') {
            $row['operator_ip'] = (string)($auditMeta['operator_ip'] ?? '');
            $row['request_id'] = (string)($auditMeta['request_id'] ?? '');
            $row['request_token'] = $token;
        }
        $this->logDao->save($row);
    }
}
