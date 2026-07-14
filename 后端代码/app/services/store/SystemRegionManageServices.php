<?php
namespace app\services\store;

use app\dao\store\SystemRegionManageDao;
use app\services\agent\SystemRegionAgentServices;
use app\services\BaseServices;

class SystemRegionManageServices extends BaseServices
{
    public function __construct(SystemRegionManageDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 子级列表（树懒加载）
     */
    public function getChildrenList(int $pid = 0): array
    {
        $where = ['pid' => $pid, 'is_del' => 0];
        $list = $this->dao->getList($where);
        foreach ($list as &$item) {
            $item['store_count'] = $this->getStoreCount((int)$item['id']);
            $item['agent_count'] = $this->getAgentCount((int)$item['id']);
            $item['has_children'] = $this->dao->count(['pid' => $item['id'], 'is_del' => 0]) > 0;
        }
        return $list;
    }

    /**
     * 全部区域（下拉/级联）
     */
    public function getAllList(): array
    {
        return $this->dao->getList(['is_del' => 0], 'id,pid,name,sort');
    }

    /**
     * 完整区域树（左侧树形导航）
     */
    public function getTree(): array
    {
        $list = $this->dao->getList(['is_del' => 0], 'id,pid,name,sort');
        if (!$list) {
            return [];
        }
        return $this->buildTreeNodes($list, 0);
    }

    /**
     * 各区域门店/管理人员数量（仅刷新左侧树数字）
     */
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
                'store_count' => $this->getStoreCount($id),
                'agent_count' => $this->getAgentCount($id),
            ];
        }
        return $map;
    }

    /**
     * @param array $list
     * @param int $pid
     * @return array
     */
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
                'store_count' => $this->getStoreCount($id),
                'agent_count' => $this->getAgentCount($id),
                'children' => $this->buildTreeNodes($list, $id),
            ];
        }
        return $branch;
    }

    /**
     * 级联选择器
     */
    public function cascaderList(): array
    {
        $list = get_tree_children(
            $this->dao->getList(['is_del' => 0], 'id as value,name as label,pid,sort'),
            'children',
            'value'
        );
        return array_merge([['value' => 0, 'label' => '根区域']], $list);
    }

    /**
     * 快捷保存区域
     */
    public function saveRegion(int $id, array $data): int
    {
        $pid = (int)($data['pid'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \Exception('请输入区域名称');
        }
        if ($id && $id === $pid) {
            throw new \Exception('上级区域不能是自己');
        }
        if ($pid && !$this->dao->get($pid, ['id'])) {
            throw new \Exception('上级区域不存在');
        }
        $save = [
            'pid' => $pid,
            'name' => $name,
            'sort' => (int)($data['sort'] ?? 0),
        ];
        if ($id) {
            $this->dao->update($id, $save);
            return $id;
        }
        $save['add_time'] = time();
        $save['is_del'] = 0;
        $res = $this->dao->save($save);
        return (int)$res->id;
    }

    /**
     * 根据架构区域ID解析代理商 name / pid
     * @param int $excludeAgentId 编辑时排除当前管理人员，避免父级解析到自己
     */
    public function resolveAgentRegion(int $manageRegionId, int $excludeAgentId = 0): array
    {
        $info = $this->getRegionInfo($manageRegionId);
        if (!$info) {
            throw new \Exception('所选区域不存在');
        }
        $archPid = (int)($info['pid'] ?? 0);
        $agentPid = $archPid > 0 ? $this->resolveAgentIdFromManageRegion($archPid, $excludeAgentId) : 0;
        return [
            'name' => $info['name'],
            'pid' => $agentPid,
            'manage_region_id' => (int)$info['id'],
            'level' => max(1, $this->getLevel($manageRegionId)),
        ];
    }

    /**
     * 架构区域ID → 区域代理商ID（代理商 pid 为上级代理商主键，非架构 pid）
     * 优先 manage_region_id，兼容旧数据 name+pid
     */
    protected function resolveAgentIdFromManageRegion(int $manageRegionId, int $excludeAgentId = 0): int
    {
        $info = $this->getRegionInfo($manageRegionId);
        if (!$info) {
            return 0;
        }
        /** @var SystemRegionAgentServices $agentServices */
        $agentServices = app()->make(SystemRegionAgentServices::class);
        $primaryId = $agentServices->getPrimaryAgentIdByManageRegion($manageRegionId);
        if ($primaryId > 0 && ($excludeAgentId <= 0 || $primaryId !== $excludeAgentId)) {
            return $primaryId;
        }
        $agentPid = 0;
        $archPid = (int)($info['pid'] ?? 0);
        if ($archPid > 0) {
            $agentPid = $this->resolveAgentIdFromManageRegion($archPid, $excludeAgentId);
        }
        $agent = $agentServices->getOne([
            'name' => $info['name'],
            'pid' => $agentPid,
            'is_del' => 0,
        ], 'id');
        if (!$agent) {
            return 0;
        }
        $agentId = (int)$agent['id'];
        if ($excludeAgentId > 0 && $agentId === $excludeAgentId) {
            return 0;
        }
        return $agentId;
    }

    /**
     * 根据 name+pid 反查架构区域ID（编辑回显）
     */
    public function findIdByAgentRegion(string $name, int $pid): int
    {
        $row = $this->dao->getOne(['name' => $name, 'pid' => $pid, 'is_del' => 0], 'id');
        return $row ? (int)$row['id'] : 0;
    }

    /**
     * 架构区域是否存在且未删除
     */
    public function getRegionInfo(int $manageRegionId): ?array
    {
        if ($manageRegionId <= 0) {
            return null;
        }
        $info = $this->dao->get($manageRegionId, ['id', 'pid', 'name', 'is_del']);
        if (!$info || (int)($info['is_del'] ?? 0) === 1) {
            return null;
        }
        return is_object($info) ? $info->toArray() : $info;
    }

    /**
     * 架构区域对应的区域代理商ID（门店 region_id 存代理商表主键）
     */
    public function getAgentIdByManageRegion(int $manageRegionId): int
    {
        if (!$this->getRegionInfo($manageRegionId)) {
            throw new \Exception('选择区域无效或已删除！');
        }
        /** @var SystemRegionAgentServices $agentServices */
        $agentServices = app()->make(SystemRegionAgentServices::class);
        $agentId = $agentServices->getPrimaryAgentIdByManageRegion($manageRegionId);
        if ($agentId > 0) {
            return $agentId;
        }
        return $this->resolveAgentIdFromManageRegion($manageRegionId);
    }

    /**
     * 根据区域代理商反查架构区域ID
     */
    public function findManageRegionIdByAgentId(int $agentId): int
    {
        if ($agentId <= 0) {
            return 0;
        }
        /** @var SystemRegionAgentServices $agentServices */
        $agentServices = app()->make(SystemRegionAgentServices::class);
        $agent = $agentServices->get($agentId, ['id', 'name', 'pid', 'manage_region_id', 'is_del']);
        if (!$agent || (int)($agent['is_del'] ?? 0) === 1) {
            return 0;
        }
        $agent = is_object($agent) ? $agent->toArray() : $agent;
        if (!empty($agent['manage_region_id'])) {
            return (int)$agent['manage_region_id'];
        }
        $rows = $this->dao->getList(['name' => $agent['name'], 'is_del' => 0], 'id');
        foreach ($rows as $row) {
            $manageId = (int)$row['id'];
            if ($this->resolveAgentIdFromManageRegion($manageId) === $agentId) {
                return $manageId;
            }
        }
        return 0;
    }

    /**
     * 级联路径（根到当前节点）
     */
    public function getManageRegionPath(int $manageRegionId): array
    {
        $path = [];
        $currentId = $manageRegionId;
        while ($currentId > 0) {
            $current = $this->dao->get($currentId, ['id', 'pid']);
            if (!$current || (int)($current['is_del'] ?? 0) === 1) {
                break;
            }
            $current = is_object($current) ? $current->toArray() : $current;
            $path[] = (int)$current['id'];
            $currentId = (int)($current['pid'] ?? 0);
        }
        return array_reverse($path);
    }

    /**
     * 统计区域下门店数（含子级）
     */
    public function getStoreCount(int $manageRegionId): int
    {
        return count($this->getStoreIdsByManageRegion($manageRegionId, true));
    }

    /**
     * 统计区域下管理人员数（含子级，与列表筛选一致）
     */
    public function getAgentCount(int $manageRegionId): int
    {
        return count($this->getAgentIdsByManageRegion($manageRegionId, true));
    }

    /**
     * 架构区域下门店ID（区域领土：region_id + 各管理员管辖门店并集）
     */
    public function getStoreIdsByManageRegion(int $manageRegionId, bool $includeDescendants = false): array
    {
        return $this->getRegionTerritoryStoreIds($manageRegionId, $includeDescendants);
    }

    /**
     * 区域架构下门店 ID
     * @param bool $includeDescendants 是否含下级架构
     * @param bool $strict 严格模式：不用名称模糊反查，避免区域外门店误入
     */
    public function getRegionTerritoryStoreIds(int $manageRegionId, bool $includeDescendants = false, bool $strict = false): array
    {
        if ($manageRegionId <= 0) {
            return [];
        }
        $agentIds = $this->getAgentIdsByManageRegion($manageRegionId, $includeDescendants);
        if (!$agentIds) {
            return [];
        }
        if (!$strict) {
            return $this->collectStoreIdsByAgentIds($agentIds);
        }
        $archIds = $includeDescendants
            ? $this->collectDescendantIds($manageRegionId)
            : [$manageRegionId];
        $archIds = array_values(array_unique(array_filter(array_map('intval', $archIds))));
        if (!$archIds) {
            return [];
        }
        $archIdSet = array_flip($archIds);

        /** @var SystemRegionAgentServices $agentServices */
        $agentServices = app()->make(SystemRegionAgentServices::class);

        $allowedAgentIds = $agentIds;
        $legacyAgentArchMap = [];
        foreach ($archIds as $archId) {
            $legacyId = $this->resolveAgentIdFromManageRegion((int)$archId);
            if ($legacyId > 0) {
                $allowedAgentIds[] = $legacyId;
                if (!isset($legacyAgentArchMap[$legacyId])) {
                    $legacyAgentArchMap[$legacyId] = (int)$archId;
                }
            }
            try {
                $bindAgentId = $this->getAgentIdByManageRegion((int)$archId);
            } catch (\Exception $e) {
                $bindAgentId = 0;
            }
            if ($bindAgentId > 0) {
                $allowedAgentIds[] = $bindAgentId;
                if (!isset($legacyAgentArchMap[$bindAgentId])) {
                    $legacyAgentArchMap[$bindAgentId] = (int)$archId;
                }
            }
        }
        $allowedAgentIds = array_values(array_unique(array_filter(array_map('intval', $allowedAgentIds))));

        $agentManageMap = $agentServices->dao->getColumn([
            'id' => $allowedAgentIds,
            'is_del' => 0,
        ], 'manage_region_id', 'id', true) ?: [];

        $stores = $this->fetchStoresBoundToAgents($allowedAgentIds);

        $storeIds = [];
        foreach ($stores as $store) {
            $storeId = (int)($store['id'] ?? 0);
            $regionAgentId = (int)($store['region_id'] ?? 0);
            if ($storeId <= 0 || $regionAgentId <= 0) {
                continue;
            }
            $agentManageRegionId = (int)($agentManageMap[$regionAgentId] ?? 0);
            if ($agentManageRegionId > 0) {
                if (isset($archIdSet[$agentManageRegionId])) {
                    $storeIds[] = $storeId;
                }
                continue;
            }
            $legacyArchId = (int)($legacyAgentArchMap[$regionAgentId] ?? 0);
            if ($legacyArchId > 0 && isset($archIdSet[$legacyArchId])) {
                $storeIds[] = $storeId;
            }
        }

        return array_values(array_unique($storeIds));
    }

    /**
     * 按区域管理人员汇总门店（region_id + 管辖门店表）
     */
    protected function collectStoreIdsByAgentIds(array $agentIds): array
    {
        $agentIds = array_values(array_unique(array_filter(array_map('intval', $agentIds))));
        if (!$agentIds) {
            return [];
        }
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        /** @var SystemRegionAgentServices $agentServices */
        $agentServices = app()->make(SystemRegionAgentServices::class);
        $storeIds = $storeServices->dao->getColumn([
            ['region_id', 'in', $agentIds],
            ['is_del', '=', 0],
        ], 'id') ?: [];
        foreach ($agentIds as $agentId) {
            $managedIds = $agentServices->getManagedStoreIds((int)$agentId);
            if ($managedIds) {
                $storeIds = array_merge($storeIds, $managedIds);
            }
        }
        return array_values(array_unique(array_filter(array_map('intval', $storeIds))));
    }

    /**
     * 管理门店弹窗可选门店（与门店保存绑定逻辑一致，排除未绑定 region_id 的门店）
     */
    public function getManageRegionSelectableStoreIds(int $manageRegionId): array
    {
        return $this->getRegionTerritoryStoreIds($manageRegionId, true, true);
    }

    /**
     * 按区域代理商 ID 查询已绑定门店
     */
    protected function fetchStoresBoundToAgents(array $agentIds): array
    {
        if (!$agentIds) {
            return [];
        }
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $stores = $storeServices->getList(
            [
                'region_id_in' => $agentIds,
                'is_del' => 0,
            ],
            ['id', 'region_id']
        ) ?: [];
        if ($stores) {
            return $stores;
        }
        $storeIds = $storeServices->dao->getColumn(
            [['region_id', 'in', $agentIds], ['is_del', '=', 0]],
            'id'
        ) ?: [];
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if (!$storeIds) {
            return [];
        }
        return $storeServices->getList(
            ['id' => $storeIds, 'is_del' => 0],
            ['id', 'region_id']
        ) ?: [];
    }

    /**
     * 获取架构区域关联的代理商ID（含子级架构与下级区域管理员）
     */
    public function getAgentIdsByManageRegion(int $manageRegionId, bool $includeDescendants = false): array
    {
        $ids = $includeDescendants
            ? $this->collectDescendantIds($manageRegionId)
            : [$manageRegionId];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        /** @var SystemRegionAgentServices $agentServices */
        $agentServices = app()->make(SystemRegionAgentServices::class);
        $agentIds = [];
        if ($ids) {
            $agentIds = $agentServices->dao->getColumn([
                'manage_region_id' => $ids,
                'is_del' => 0,
            ], 'id', '', true) ?: [];
        }
        foreach ($ids as $id) {
            $legacyId = $this->resolveAgentIdFromManageRegion((int)$id);
            if ($legacyId > 0) {
                $agentIds[] = $legacyId;
            }
        }
        return array_values(array_unique(array_filter(array_map('intval', $agentIds))));
    }

    protected function collectDescendantIds(int $id): array
    {
        $result = [$id];
        $children = $this->dao->getList(['pid' => $id, 'is_del' => 0], 'id');
        foreach ($children as $child) {
            $result = array_merge($result, $this->collectDescendantIds((int)$child['id']));
        }
        return $result;
    }

    public function getLevel(int $pid): int
    {
        if (!$pid) {
            return 0;
        }
        $level = 1;
        $current = $this->dao->get($pid, ['id', 'pid']);
        while ($current && $current['pid']) {
            $level++;
            $current = $this->dao->get((int)$current['pid'], ['id', 'pid']);
        }
        return $level;
    }

    /**
     * 删除
     */
    public function deleteRegion(int $id): bool
    {
        if ($this->dao->count(['pid' => $id, 'is_del' => 0])) {
            throw new \Exception('请先删除下级区域');
        }
        return (bool)$this->dao->update($id, ['is_del' => 1]);
    }
}
