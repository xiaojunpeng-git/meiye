<?php
namespace app\services\target;

use app\dao\target\StoreTargetAllocateDao;
use app\dao\target\StoreTargetDao;
use app\dao\target\StoreTargetMetricDao;
use app\dao\target\StoreTargetProductDao;
use app\dao\yeji\StaffYejiDao;
use app\model\product\product\StoreProduct;
use app\model\product\product\StoreProductRelation;
use app\model\store\SystemStore;
use app\model\store\SystemStoreStaff;
use app\model\yeji\CashSource;
use app\model\yeji\CashType;
use app\services\BaseServices;
use app\services\order\store\BranchOrderServices;
use app\services\report\ReportProductServices;
use app\services\report\ReportServices;
use app\services\store\SystemRegionManageServices;
use app\services\store\SystemStoreServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 门店目标管理
 * Class StoreTargetServices
 * @package app\services\target
 */
class StoreTargetServices extends BaseServices
{
    /** @var StoreTargetMetricDao */
    protected $metricDao;

    /** @var StoreTargetProductDao */
    protected $productDao;

    /** @var StoreTargetAllocateDao */
    protected $allocateDao;

    public function __construct(
        StoreTargetDao $dao,
        StoreTargetMetricDao $metricDao,
        StoreTargetProductDao $productDao,
        StoreTargetAllocateDao $allocateDao
    ) {
        $this->dao = $dao;
        $this->metricDao = $metricDao;
        $this->productDao = $productDao;
        $this->allocateDao = $allocateDao;
    }

    /**
     * 核心指标定义
     * @return array
     */
    public function getMetricDefinitions(): array
    {
        return [
            ['key' => 'revenue', 'name' => '销售收入', 'unit' => '元', 'icon' => 'revenue'],
            ['key' => 'consume', 'name' => '消耗业绩', 'unit' => '元', 'icon' => 'consume'],
            ['key' => 'new_customer', 'name' => '成交新客', 'unit' => '人', 'icon' => 'new_customer'],
            ['key' => 'old_customer', 'name' => '会员客数', 'unit' => '人', 'icon' => 'old_customer'],
            ['key' => 'service', 'name' => '服务客次', 'unit' => '次', 'icon' => 'service'],
            ['key' => 'point', 'name' => '点客数', 'unit' => '人', 'icon' => 'point'],
            ['key' => 'book', 'name' => '预约数', 'unit' => '单', 'icon' => 'book'],
        ];
    }

    /**
     * 品项指标类型
     * @return array
     */
    public function getProductMetricTypes(): array
    {
        return [
            ['key' => 'revenue', 'name' => '销售收入', 'unit' => '元'],
            ['key' => 'count', 'name' => '销售数量', 'unit' => '个'],
            ['key' => 'consume', 'name' => '消耗业绩', 'unit' => '元'],
            ['key' => 'service', 'name' => '服务客次', 'unit' => '次'],
        ];
    }

    /**
     * @param array $where
     * @param array $allowedStoreIds
     * @return array
     */
    public function list(array $where, array $allowedStoreIds = []): array
    {
        if ($allowedStoreIds) {
            $allowedStoreIds = array_values(array_filter(array_map('intval', $allowedStoreIds)));
            if (!empty($where['store_id']) && (int)$where['store_id'] > 0) {
                if (!in_array((int)$where['store_id'], $allowedStoreIds, true)) {
                    throw new ValidateException('无权查看该门店');
                }
            }
        }

        [$page, $limit] = $this->getPageValue();
        $searchWhere = $where;
        if ($allowedStoreIds && empty($searchWhere['store_id']) && empty($searchWhere['store_ids'])) {
            $searchWhere['store_ids'] = $allowedStoreIds;
        }
        $list = $this->dao->getList($searchWhere, $page, $limit);
        $count = $this->dao->search($searchWhere)->count();

        $cards = [];
        foreach ($list as $row) {
            $cards[] = $this->formatTargetCard($row);
        }

        return [
            'cards' => $cards,
            'list' => $cards,
            'count' => $count,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @param int $id
     * @param int $storeId
     * @param array $allowedStoreIds
     * @return array
     */
    public function detail(int $id, int $storeId = 0, array $allowedStoreIds = []): array
    {
        $target = $this->getTargetOrFail($id, $storeId, $allowedStoreIds);
        return $this->formatTargetDetail($target);
    }

    /**
     * @param array $data
     * @param int $storeId
     * @param array $allowedStoreIds
     * @return array
     */
    public function save(array $data, int $storeId, array $allowedStoreIds = []): array
    {
        if ($storeId <= 0) {
            throw new ValidateException('请选择门店');
        }
        if ($allowedStoreIds && !in_array($storeId, $allowedStoreIds, true)) {
            throw new ValidateException('无权操作该门店');
        }

        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidateException('请输入目标名称');
        }

        $metrics = $data['metrics'] ?? [];
        $products = $data['products'] ?? [];
        if (!$metrics && !$products) {
            throw new ValidateException('请至少填写一个指标');
        }

        $metricCount = count($metrics) + count($products);
        $now = time();
        $main = [
            'name' => $name,
            'store_id' => $storeId,
            'object_type' => (int)($data['object_type'] ?? 1),
            'object_name' => (string)($data['object_name'] ?? ''),
            'time_type' => (int)($data['time_type'] ?? 1),
            'year' => (int)($data['year'] ?? date('Y')),
            'month' => (int)($data['month'] ?? 0),
            'period_start' => (int)($data['period_start'] ?? 0),
            'period_end' => (int)($data['period_end'] ?? 0),
            'metric_count' => $metricCount,
            'status' => (int)($data['status'] ?? 1),
            'update_time' => $now,
        ];

        if ($id > 0) {
            $this->getTargetOrFail($id, $storeId, $allowedStoreIds);
            $this->dao->update($id, $main);
            $this->metricDao->deleteByTargetId($id);
            $this->productDao->deleteByTargetId($id);
            $this->allocateDao->deleteByTargetId($id);
        } else {
            $main['add_time'] = $now;
            $main['is_del'] = 0;
            $created = $this->dao->save($main);
            $id = (int)($created->id ?? $created['id'] ?? 0);
            if ($id <= 0) {
                throw new ValidateException('保存失败');
            }
        }

        $this->saveMetrics($id, $storeId, $metrics);
        $this->saveProducts($id, $storeId, $products);

        return $this->detail($id, $storeId, $allowedStoreIds);
    }

    /**
     * @param int $id
     * @param int $storeId
     * @param array $allowedStoreIds
     */
    public function delete(int $id, int $storeId = 0, array $allowedStoreIds = []): void
    {
        $this->getTargetOrFail($id, $storeId, $allowedStoreIds);
        $this->dao->update($id, ['is_del' => 1, 'update_time' => time()]);
    }

    /**
     * @param int $id
     * @param int $storeId
     * @param array $allowedStoreIds
     * @return array
     */
    public function copy(int $id, int $storeId = 0, array $allowedStoreIds = []): array
    {
        $target = $this->getTargetOrFail($id, $storeId, $allowedStoreIds);
        $detail = $this->formatTargetDetail($target);
        unset($detail['id']);
        $detail['name'] = ($detail['name'] ?? '目标') . '（复制）';
        $detail['metrics'] = array_map(function ($m) {
            unset($m['id']);
            return $m;
        }, $detail['metrics'] ?? []);
        $detail['products'] = array_map(function ($p) {
            unset($p['id']);
            return $p;
        }, $detail['products'] ?? []);
        return $this->save($detail, (int)$target['store_id'], $allowedStoreIds);
    }

    /**
     * @param array $where
     * @param array $allowedStoreIds
     * @return array
     */
    public function analysis(array $where, array $allowedStoreIds = []): array
    {
        if (!empty($where['start_month']) && !empty($where['end_month'])) {
            return $this->analysisByPeriod($where, $allowedStoreIds);
        }

        $targetId = (int)($where['target_id'] ?? $where['id'] ?? 0);
        if ($targetId > 0) {
            $target = $this->getTargetOrFail($targetId, (int)($where['store_id'] ?? 0), $allowedStoreIds);
            $detail = $this->formatTargetDetail($target);
            return [
                'target' => $detail,
                'metrics' => $detail['metrics'] ?? [],
                'products' => $detail['products'] ?? [],
                'compare' => $this->buildCompareList($detail),
                'ranking' => $this->buildStoreRanking($detail),
            ];
        }

        $searchWhere = $where;
        if ($allowedStoreIds && empty($searchWhere['store_id']) && empty($searchWhere['store_ids'])) {
            $searchWhere['store_ids'] = $allowedStoreIds;
        }
        $list = $this->dao->getList($searchWhere, 0, 0);
        $metricMap = [];
        $productRows = [];
        foreach ($list as $row) {
            $card = $this->formatTargetCard($row);
            foreach ($card['metrics'] ?? [] as $m) {
                $key = (string)($m['metric_key'] ?? '');
                if ($key === '') {
                    continue;
                }
                if (!isset($metricMap[$key])) {
                    $metricMap[$key] = $m;
                } else {
                    $metricMap[$key]['target_value'] += (int)($m['target_value'] ?? 0);
                    $metricMap[$key]['completed_value'] += (int)($m['completed_value'] ?? 0);
                    $metricMap[$key]['rate'] = $metricMap[$key]['target_value'] > 0
                        ? round($metricMap[$key]['completed_value'] / $metricMap[$key]['target_value'] * 100, 1)
                        : 0;
                }
            }
            foreach ($card['products'] ?? [] as $p) {
                $productRows[] = $p;
            }
        }

        $metrics = array_values($metricMap);
        if (!$metrics) {
            foreach ($this->getMetricDefinitions() as $def) {
                $metrics[] = [
                    'metric_key' => $def['key'],
                    'metric_name' => $def['name'],
                    'target_value' => 0,
                    'completed_value' => 0,
                    'unit' => $def['unit'],
                    'rate' => 0,
                ];
            }
        }

        return [
            'metrics' => $metrics,
            'products' => $productRows,
            'compare' => [],
            'ranking' => $this->buildStoreRankingFromCards(array_map([$this, 'formatTargetCard'], $list)),
        ];
    }

    /**
     * @param int $storeId
     * @param array $regionStoreIds
     * @return array
     */
    public function storeOptions(int $storeId, array $regionStoreIds = []): array
    {
        $options = [];
        if ($regionStoreIds) {
            $regionStoreIds = array_values(array_filter(array_map('intval', $regionStoreIds)));
            $stores = SystemStore::whereIn('id', $regionStoreIds)->where('is_del', 0)->field('id,name')->select();
            foreach ($stores as $store) {
                $row = is_array($store) ? $store : $store->toArray();
                $options[] = [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                    'object_type' => 1,
                    'label' => '门店',
                ];
            }
            if (count($options) > 1) {
                array_unshift($options, [
                    'id' => 0,
                    'name' => '区域汇总',
                    'object_type' => 2,
                    'label' => '区域',
                ]);
            }
            return $options;
        }

        if ($storeId > 0) {
            $name = SystemStore::where('id', $storeId)->value('name');
            $options[] = [
                'id' => $storeId,
                'name' => (string)$name,
                'object_type' => 1,
                'label' => '门店',
            ];
        } else {
            $stores = SystemStore::where('is_del', 0)->field('id,name')->select();
            foreach ($stores as $store) {
                $row = is_array($store) ? $store : $store->toArray();
                $options[] = [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                    'object_type' => 1,
                    'label' => '门店',
                ];
            }
        }

        array_unshift($options, [
            'id' => 0,
            'name' => '全部门店',
            'object_type' => 3,
            'label' => '全部门店',
        ]);

        return $options;
    }

    /**
     * 目标对象树（与后台区域管理多级架构一致，不含全部门店汇总节点）
     * @param int $storeId
     * @param array $regionStoreIds
     * @return array
     */
    public function storeOptionsTree(int $storeId, array $regionStoreIds = []): array
    {
        /** @var SystemRegionManageServices $manageServices */
        $manageServices = app()->make(SystemRegionManageServices::class);

        $allowedSet = null;
        if ($regionStoreIds) {
            $allowedSet = array_flip(array_values(array_filter(array_map('intval', $regionStoreIds))));
        } elseif ($storeId > 0) {
            $allowedSet = [$storeId => true];
        }

        $regions = $this->buildRegionOptionsTreeNodes(0, $manageServices, $allowedSet);

        // 未挂区域架构的门店 / 区域管理员管辖但未匹配到区域树时，归入「管辖门店」
        if ($allowedSet !== null) {
            $assigned = $this->collectStoreIdsFromOptionTree($regions);
            $assignedSet = array_flip($assigned);
            $orphanIds = array_values(array_filter(array_keys($allowedSet), function ($sid) use ($assignedSet) {
                return !isset($assignedSet[(int)$sid]);
            }));
            if ($orphanIds) {
                $stores = $this->buildStoreOptionNodes($orphanIds);
                if ($stores) {
                    $regions[] = [
                        'id' => 0,
                        'name' => '管辖门店',
                        'object_type' => 2,
                        'label' => '区域',
                        'desc' => count($stores) . '家门店',
                        'store_count' => count($stores),
                        'children' => $stores,
                    ];
                }
            }
        }

        $totalStores = $this->countStoresInOptionTree($regions);

        // 单门店账号：仅展示该门店
        if ($storeId > 0 && $totalStores <= 1) {
            $name = (string)(SystemStore::where('id', $storeId)->value('name') ?: '门店');
            $staffCount = (int)SystemStoreStaff::where('store_id', $storeId)
                ->where('status', 1)->where('is_del', 0)->count();
            return [[
                'id' => $storeId,
                'name' => $name,
                'object_type' => 1,
                'label' => '门店',
                'desc' => $staffCount . '名员工',
                'staff_count' => $staffCount,
                'children' => [],
            ]];
        }

        if (!$regions && $allowedSet === null) {
            $allIds = array_map('intval', SystemStore::where('is_del', 0)->column('id'));
            $stores = $this->buildStoreOptionNodes($allIds);
            if ($stores) {
                $regions[] = [
                    'id' => 0,
                    'name' => '未分区门店',
                    'object_type' => 2,
                    'label' => '区域',
                    'desc' => count($stores) . '家门店',
                    'store_count' => count($stores),
                    'children' => $stores,
                ];
            }
        }

        return $regions;
    }

    /**
     * 按后台区域管理架构递归构建目标对象树（子区域 + 直属门店）
     * @param int $pid
     * @param SystemRegionManageServices $manageServices
     * @param array|null $allowedSet
     * @return array
     */
    protected function buildRegionOptionsTreeNodes(
        int $pid,
        SystemRegionManageServices $manageServices,
        ?array $allowedSet
    ): array {
        $nodes = [];
        foreach ($manageServices->getChildrenList($pid) as $regionRow) {
            $regionId = (int)($regionRow['id'] ?? 0);
            if ($regionId <= 0) {
                continue;
            }

            $childRegionNodes = $this->buildRegionOptionsTreeNodes($regionId, $manageServices, $allowedSet);

            // 子区域已挂载的门店不再出现在当前层，保证同一门店只出现在最内层
            $childAssignedSet = array_flip($this->collectStoreIdsFromOptionTree($childRegionNodes));

            $directStoreIds = $manageServices->getStoreIdsByManageRegion($regionId, false);
            if ($allowedSet !== null) {
                $directStoreIds = array_values(array_filter($directStoreIds, function ($sid) use ($allowedSet) {
                    return isset($allowedSet[(int)$sid]);
                }));
            }
            if ($childAssignedSet) {
                $directStoreIds = array_values(array_filter($directStoreIds, function ($sid) use ($childAssignedSet) {
                    return !isset($childAssignedSet[(int)$sid]);
                }));
            }
            $storeNodes = $this->buildStoreOptionNodes($directStoreIds);
            $children = array_merge($childRegionNodes, $storeNodes);

            if ($allowedSet !== null && !$children) {
                continue;
            }

            $allStoreIds = $manageServices->getStoreIdsByManageRegion($regionId, true);
            if ($allowedSet !== null) {
                $allStoreIds = array_values(array_filter($allStoreIds, function ($sid) use ($allowedSet) {
                    return isset($allowedSet[(int)$sid]);
                }));
            }
            $storeCount = count($allStoreIds);

            $nodes[] = [
                'id' => $regionId,
                'name' => (string)($regionRow['name'] ?? ''),
                'object_type' => 2,
                'label' => '区域',
                'desc' => $storeCount . '家门店',
                'store_count' => $storeCount,
                'children' => $children,
            ];
        }
        return $nodes;
    }

    /**
     * @param array $nodes
     * @return array
     */
    protected function collectStoreIdsFromOptionTree(array $nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            if ((int)($node['object_type'] ?? 0) === 1 && (int)($node['id'] ?? 0) > 0) {
                $ids[] = (int)$node['id'];
            }
            if (!empty($node['children'])) {
                $ids = array_merge($ids, $this->collectStoreIdsFromOptionTree($node['children']));
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param array $nodes
     * @return int
     */
    protected function countStoresInOptionTree(array $nodes): int
    {
        return count($this->collectStoreIdsFromOptionTree($nodes));
    }

    /**
     * @param array $storeIds
     * @return array
     */
    protected function buildStoreOptionNodes(array $storeIds): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if (!$storeIds) {
            return [];
        }
        $rows = SystemStore::whereIn('id', $storeIds)->where('is_del', 0)
            ->field('id,name')->order('id asc')->select();
        $staffCounts = SystemStoreStaff::whereIn('store_id', $storeIds)
            ->where('status', 1)->where('is_del', 0)
            ->group('store_id')->column('count(*)', 'store_id');
        $list = [];
        foreach ($rows as $store) {
            $row = is_array($store) ? $store : $store->toArray();
            $sid = (int)($row['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $staffNum = (int)($staffCounts[$sid] ?? 0);
            $list[] = [
                'id' => $sid,
                'name' => (string)($row['name'] ?? ''),
                'object_type' => 1,
                'label' => '门店',
                'desc' => $staffNum . '名员工',
                'staff_count' => $staffNum,
                'children' => [],
            ];
        }
        return $list;
    }

    /**
     * @param string $keyword
     * @param int $storeId
     * @return array
     */
    public function productSearch(string $keyword, int $storeId): array
    {
        if ($storeId <= 0) {
            throw new ValidateException('请选择门店');
        }
        $query = StoreProduct::where('is_del', 0)
            ->where('is_show', 1)
            ->where('type', 1)
            ->where('relation_id', $storeId);
        if ($keyword !== '') {
            $query->whereLike('store_name|keyword', '%' . $keyword . '%');
        }
        $list = $query->field('id as product_id,store_name as product_name,price,image')
            ->limit(50)
            ->select();
        return $list ? $list->toArray() : [];
    }

    /**
     * @return array
     */
    public function getProductItemTypes(): array
    {
        return [
            ['key' => 'project', 'name' => '项目', 'product_types' => [6]],
            ['key' => 'card', 'name' => '卡项', 'product_types' => [5]],
            ['key' => 'product', 'name' => '产品', 'product_types' => [0]],
        ];
    }

    /**
     * @param string $itemType
     * @return array
     */
    protected function mapItemTypeToProductTypes(string $itemType): array
    {
        foreach ($this->getProductItemTypes() as $row) {
            if ($row['key'] === $itemType) {
                return $row['product_types'];
            }
        }
        return [6];
    }

    /**
     * @param string $itemType
     * @param int $storeId
     * @param string $keyword
     * @return array
     */
    public function productSelectTree(string $itemType, int $storeId, string $keyword = ''): array
    {
        if ($storeId <= 0) {
            throw new ValidateException('请选择门店');
        }
        $categories = $this->productSelectCategories($itemType, $storeId, $keyword);
        $tree = [];
        foreach ($categories['categories'] as $cat) {
            $products = $this->productSelectProducts(
                $itemType,
                $storeId,
                (int)$cat['category_id'],
                $keyword,
                1,
                500
            );
            $tree[] = array_merge($cat, ['products' => $products['list'] ?? []]);
        }
        return [
            'item_types' => $categories['item_types'],
            'categories' => $tree,
        ];
    }

    /**
     * @param string $itemType
     * @param int $storeId
     * @param string $keyword
     * @return array
     */
    public function productSelectCategories(string $itemType, int $storeId, string $keyword = ''): array
    {
        if ($storeId <= 0) {
            throw new ValidateException('请选择门店');
        }
        $productTypes = $this->mapItemTypeToProductTypes($itemType);
        $categories = $this->fetchStoreCategories($storeId, $productTypes, $keyword);
        return [
            'item_types' => $this->getProductItemTypes(),
            'categories' => $categories,
        ];
    }

    /**
     * @param string $itemType
     * @param int $storeId
     * @param int $categoryId
     * @param string $keyword
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function productSelectProducts(
        string $itemType,
        int $storeId,
        int $categoryId,
        string $keyword = '',
        int $page = 1,
        int $limit = 20
    ): array {
        if ($storeId <= 0) {
            throw new ValidateException('请选择门店');
        }
        $productTypes = $this->mapItemTypeToProductTypes($itemType);
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));

        $query = StoreProduct::where('is_del', 0)
            ->where('is_show', 1)
            ->where('type', 1)
            ->where('relation_id', $storeId)
            ->whereIn('product_type', $productTypes);

        if ($categoryId > 0) {
            $query->whereIn('id', function ($q) use ($categoryId) {
                $q->name('store_product_relation')
                    ->where('type', 1)
                    ->where(function ($sub) use ($categoryId) {
                        $sub->where('relation_id', $categoryId)
                            ->whereOr('relation_pid', $categoryId);
                    })
                    ->field('product_id')
                    ->select();
            });
        } elseif ($categoryId === 0) {
            $query->whereNotIn('id', function ($q) use ($storeId) {
                $q->name('store_product_relation')
                    ->where('type', 1)
                    ->field('product_id')
                    ->select();
            });
        }

        if ($keyword !== '') {
            $query->whereLike('store_name|keyword|id', '%' . $keyword . '%');
        }

        $count = (int)(clone $query)->count();
        $list = $query->field('id as product_id,store_name as product_name,price,image,product_type')
            ->page($page, $limit)
            ->order('sort desc,id desc')
            ->select();
        $rows = $list ? $list->toArray() : [];
        foreach ($rows as &$row) {
            $row['icon_theme'] = $this->mapProductTypeTheme((int)($row['product_type'] ?? 0));
            unset($row['product_type']);
        }
        unset($row);

        return [
            'list' => $rows,
            'count' => $count,
            'page' => $page,
            'limit' => $limit,
            'has_more' => $page * $limit < $count,
        ];
    }

    /**
     * @param int $targetId
     * @param string $metricKey
     * @param string $sortType
     * @param bool $asc
     * @param array $allowedStoreIds
     * @return array
     */
    public function employeeRanking(
        int $targetId,
        string $metricKey,
        string $sortType,
        bool $asc,
        array $allowedStoreIds = []
    ): array {
        $target = $this->getTargetOrFail($targetId, 0, $allowedStoreIds);
        $storeIds = $this->resolveStoreIds($target);
        [$start, $end] = $this->getTimeRange($target);
        $timeStr = date('Y/m/d H:i:s', $start) . '-' . date('Y/m/d H:i:s', $end);

        $allocRows = $this->allocateDao->getByTargetAndRef($targetId, $metricKey, 1);
        $targetMap = [];
        foreach ($allocRows as $row) {
            $staffId = (int)($row['staff_id'] ?? 0);
            if ($this->isTargetExcludedStaffId($staffId)) {
                continue;
            }
            $targetMap[$staffId] = (int)round((float)($row['allocate_value'] ?? 0));
        }
        $fallbackMeta = [];
        $this->applyStaffTargetFallback($targetMap, $fallbackMeta, $storeIds, [$targetId], $metricKey);

        /** @var StaffYejiDao $yejiDao */
        $yejiDao = app()->make(StaffYejiDao::class);
        $where = [
            'created_time' => $timeStr,
            'store_id' => count($storeIds) === 1 ? $storeIds[0] : $storeIds,
        ];

        $rows = [];
        $productGroup = $this->findProductGroupByRefKey($targetId, $metricKey);
        if ($productGroup) {
            $rows = $this->buildProductStaffRankingRows($productGroup, $where);
        } elseif (in_array($metricKey, ['revenue', 'consume'], true)) {
            $where['sum_type'] = $metricKey === 'revenue' ? 1 : 2;
            $rows = $yejiDao->ranking($where);
            if ($metricKey === 'revenue') {
                $rows = $this->adjustStaffRankingExcludeCombinationOldCard($rows, $where);
            }
        } elseif ($metricKey === 'point') {
            $rows = $yejiDao->dianke($where);
        } elseif ($metricKey === 'service') {
            $serviceWhere = $where;
            $serviceWhere['sum_type'] = 2;
            $rows = $yejiDao->search($serviceWhere)
                ->field('staff_id,staff_name,count(*) as yeji,store_id')
                ->group('staff_id')
                ->order('yeji desc')
                ->select()
                ->toArray();
        } else {
            foreach ($storeIds as $sid) {
                $val = $this->calcMetricCompleted($metricKey, [$sid], $start, $end);
                if ($val <= 0) {
                    continue;
                }
                $staffList = $this->applyTargetAssignableStaffScope(
                    SystemStoreStaff::where('store_id', $sid)
                        ->where('status', 1)
                        ->where('is_del', 0)
                )
                    ->field('id as staff_id,staff_name')
                    ->select()
                    ->toArray();
                $per = count($staffList) > 0 ? $val / count($staffList) : 0;
                foreach ($staffList as $staff) {
                    $rows[] = [
                        'staff_id' => (int)$staff['staff_id'],
                        'staff_name' => (string)$staff['staff_name'],
                        'yeji' => $per,
                    ];
                }
            }
        }

        $rows = $this->filterTargetStaffRankingRows($rows);
        $allowedStaffFlip = $storeIds ? array_flip($this->getStaffIdsForStores($storeIds)) : null;
        if ($allowedStaffFlip !== null) {
            $rows = $this->filterRankingStaffByStoreScope($rows, $storeIds);
        }

        $list = [];
        $seenStaff = [];
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : (method_exists($row, 'toArray') ? $row->toArray() : []);
            $staffId = (int)($row['staff_id'] ?? 0);
            if ($staffId <= 0 || $this->isTargetExcludedStaffId($staffId)) {
                continue;
            }
            $completed = (float)($row['yeji'] ?? 0);
            $targetVal = $targetMap[$staffId] ?? 0;
            $rate = $targetVal > 0 ? round($completed / $targetVal * 100, 1) : 0;
            $list[] = [
                'staff_id' => $staffId,
                'staff_name' => (string)($row['staff_name'] ?? ''),
                'target_value' => $targetVal,
                'value' => $completed,
                'completed_value' => $completed,
                'rate' => $rate,
            ];
            $seenStaff[$staffId] = true;
        }

        foreach ($targetMap as $staffId => $targetVal) {
            if (isset($seenStaff[$staffId]) || $targetVal <= 0) {
                continue;
            }
            if ($allowedStaffFlip !== null && !isset($allowedStaffFlip[$staffId])) {
                continue;
            }
            $staff = SystemStoreStaff::where('id', $staffId)->where('is_del', 0)->find();
            $list[] = [
                'staff_id' => $staffId,
                'staff_name' => $staff ? (string)$staff['staff_name'] : '',
                'target_value' => $targetVal,
                'value' => 0,
                'completed_value' => 0,
                'rate' => 0,
            ];
        }

        $field = $sortType === 'rate' ? 'rate' : 'value';
        usort($list, function ($a, $b) use ($field, $asc) {
            $cmp = $a[$field] <=> $b[$field];
            return $asc ? $cmp : -$cmp;
        });

        return $list;
    }

    /**
     * 品项指标 ref_key 解析为分组（与 buildProductDetailRows 一致）
     * @param int $targetId
     * @param string $refKey
     * @return array|null
     */
    protected function findProductGroupByRefKey(int $targetId, string $refKey): ?array
    {
        if ($refKey === '' || strpos($refKey, 'product_') !== 0) {
            return null;
        }
        $products = $this->productDao->getByTargetId($targetId);
        if (!$products) {
            return null;
        }
        $groups = [];
        foreach ($products as $index => $p) {
            $key = (string)($p['allocate_ref_key'] ?? '');
            if ($key === '') {
                $key = 'product_' . (int)($p['product_id'] ?? 0) . '_' . (string)($p['metric_key'] ?? 'revenue');
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'metric_key' => (string)($p['metric_key'] ?? 'revenue'),
                    'product_items' => [],
                ];
            }
            $productId = (int)($p['product_id'] ?? 0);
            $productName = (string)($p['product_name'] ?? '');
            $exists = false;
            foreach ($groups[$key]['product_items'] as $item) {
                if ((int)($item['product_id'] ?? 0) === $productId && (string)($item['product_name'] ?? '') === $productName) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $groups[$key]['product_items'][] = [
                    'product_id' => $productId,
                    'product_name' => $productName,
                ];
            }
        }
        return $groups[$refKey] ?? null;
    }

    /**
     * 从 metric_key（如 product_123_revenue）解析品项分组，供按时间范围排行使用
     * @param string $metricKey
     * @return array|null
     */
    protected function parseProductGroupFromMetricKey(string $metricKey): ?array
    {
        if ($metricKey === '' || strpos($metricKey, 'product_') !== 0) {
            return null;
        }
        if (!preg_match('/^product_(\d+)_(\w+)$/', $metricKey, $matches)) {
            return null;
        }
        return [
            'metric_key' => (string)$matches[2],
            'product_items' => [
                [
                    'product_id' => (int)$matches[1],
                    'product_name' => '',
                ],
            ],
        ];
    }

    /**
     * 品项指标员工排行
     * @param array $productGroup
     * @param array $where
     * @return array
     */
    protected function buildProductStaffRankingRows(array $productGroup, array $where): array
    {
        $productIds = array_values(array_filter(array_map(function ($item) {
            return (int)($item['product_id'] ?? 0);
        }, $productGroup['product_items'] ?? [])));
        if (!$productIds) {
            return [];
        }
        $metricKey = (string)($productGroup['metric_key'] ?? 'revenue');
        $expandedProductIds = $this->expandProductIdsForReport($productIds);
        /** @var StaffYejiDao $yejiDao */
        $yejiDao = app()->make(StaffYejiDao::class);
        $rows = [];
        switch ($metricKey) {
            case 'revenue':
                $where['sum_type'] = 1;
                $rows = $yejiDao->search($where)->whereIn('goods_id', $expandedProductIds)
                    ->field('staff_id,staff_name,SUM(yeji) as yeji,store_id')
                    ->group('staff_id')
                    ->order('yeji desc')
                    ->select()
                    ->toArray();
                $rows = $this->adjustStaffRankingExcludeCombinationOldCard($rows, $where, $expandedProductIds);
                break;
            case 'consume':
                $where['sum_type'] = 2;
                $rows = $yejiDao->search($where)->whereIn('goods_id', $expandedProductIds)
                    ->field('staff_id,staff_name,SUM(yeji) as yeji,store_id')
                    ->group('staff_id')
                    ->order('yeji desc')
                    ->select()
                    ->toArray();
                break;
            case 'service':
                $where['sum_type'] = 2;
                $rows = $yejiDao->search($where)->whereIn('goods_id', $expandedProductIds)
                    ->field('staff_id,staff_name,count(*) as yeji,store_id')
                    ->group('staff_id')
                    ->order('yeji desc')
                    ->select()
                    ->toArray();
                break;
        }

        return $this->filterTargetStaffRankingRows($rows);
    }

    /**
     * @param array $params
     * @param array $regionStoreIds
     * @return array
     */
    public function allocateInfo(array $params, array $regionStoreIds = []): array
    {
        $storeId = (int)($params['store_id'] ?? 0);
        $storeIds = $this->resolveAllocateStoreIds($storeId, $regionStoreIds);
        if (!$storeIds) {
            throw new ValidateException('请选择门店');
        }
        $storeId = $storeIds[0];

        $targetId = (int)($params['target_id'] ?? 0);
        $refKey = (string)($params['ref_key'] ?? '');
        $allocateType = (int)($params['allocate_type'] ?? 1);
        $targetValue = (int)round((float)($params['target_value'] ?? 0));

        $existingMap = [];
        if ($targetId > 0 && $refKey !== '') {
            $rows = $this->allocateDao->getByTargetAndRef($targetId, $refKey, $allocateType);
            foreach ($rows as $row) {
                $staffId = (int)($row['staff_id'] ?? 0);
                if ($this->isTargetExcludedStaffId($staffId)) {
                    continue;
                }
                $existingMap[$staffId] = (int)round((float)($row['allocate_value'] ?? 0));
            }
        }

        $staffList = [];
        foreach ($storeIds as $sid) {
            $staffs = $this->applyTargetAssignableStaffScope(
                SystemStoreStaff::where('store_id', $sid)
                    ->where('status', 1)
                    ->where('is_del', 0)
            )
                ->field('id as staff_id,staff_name,avatar,store_id')
                ->select()
                ->toArray();
            $staffList = array_merge($staffList, $staffs ?: []);
        }

        $list = [];
        foreach ($staffList as $staff) {
            $sid = (int)$staff['staff_id'];
            $list[] = [
                'staff_id' => $sid,
                'staff_name' => (string)($staff['staff_name'] ?? ''),
                'avatar' => (string)($staff['avatar'] ?? ''),
                'store_id' => (int)($staff['store_id'] ?? 0),
                'allocate_value' => $existingMap[$sid] ?? 0,
            ];
        }

        return [
            'target_id' => $targetId,
            'ref_key' => $refKey,
            'allocate_type' => $allocateType,
            'target_value' => $targetValue,
            'metric_name' => (string)($params['metric_name'] ?? ''),
            'unit' => (string)($params['unit'] ?? ''),
            'staff_list' => $list,
        ];
    }

    /**
     * @param array $data
     * @param int $storeId
     * @param array $regionStoreIds
     * @return array
     */
    public function saveAllocate(array $data, int $storeId, array $regionStoreIds = []): array
    {
        $storeIds = $this->resolveAllocateStoreIds($storeId, $regionStoreIds);
        if (!$storeIds) {
            throw new ValidateException('请选择门店');
        }
        $storeId = $storeIds[0];

        $targetId = (int)($data['target_id'] ?? 0);
        if ($targetId <= 0) {
            throw new ValidateException('请先保存目标');
        }

        $refKey = (string)($data['ref_key'] ?? '');
        if ($refKey === '') {
            throw new ValidateException('缺少分配标识');
        }

        $allocateType = (int)($data['allocate_type'] ?? 1);
        $targetValue = (int)round((float)($data['target_value'] ?? 0));
        $items = $data['items'] ?? [];

        $this->getTargetOrFail($targetId, $storeId, $regionStoreIds);
        $this->validateAllocationSum($items, $targetValue);
        $this->saveAllocationsFromPayload($targetId, $storeId, $refKey, $allocateType, $items);

        return ['ok' => true];
    }

    /**
     * @param int $id
     * @param int $storeId
     * @param array $allowedStoreIds
     * @return array
     */
    protected function getTargetOrFail(int $id, int $storeId = 0, array $allowedStoreIds = []): array
    {
        $target = $this->dao->get($id);
        if (!$target || (int)($target['is_del'] ?? 0) === 1) {
            throw new ValidateException('目标不存在');
        }
        $row = is_array($target) ? $target : $target->toArray();
        $targetStoreId = (int)($row['store_id'] ?? 0);
        if ($allowedStoreIds) {
            $allowedStoreIds = array_values(array_filter(array_map('intval', $allowedStoreIds)));
            if ($allowedStoreIds && !in_array($targetStoreId, $allowedStoreIds, true)) {
                throw new ValidateException('无权查看该目标');
            }
            if ($storeId > 0 && $targetStoreId !== $storeId) {
                throw new ValidateException('无权查看该目标');
            }
        } elseif ($storeId > 0 && $targetStoreId !== $storeId) {
            throw new ValidateException('无权查看该目标');
        }
        return $row;
    }

    /**
     * @param array $target
     * @return array
     */
    protected function formatTargetCard(array $target): array
    {
        $detail = $this->formatTargetDetail($target);
        return [
            'id' => $detail['id'],
            'name' => $detail['name'],
            'store_id' => (int)($detail['store_id'] ?? 0),
            'object_type' => $detail['object_type'],
            'object_name' => $detail['object_name'],
            'time_type' => $detail['time_type'],
            'year' => $detail['year'],
            'month' => $detail['month'],
            'period_label' => $detail['period_label'],
            'summary' => $detail['summary'],
            'metrics' => $detail['metrics'],
            'products' => $detail['products'],
            'metric_count' => $detail['metric_count'],
        ];
    }

    /**
     * 列表/详情展示：补全未保存的核心指标行（目标值为0）
     * @param array $savedMetrics
     * @return array
     */
    protected function mergeCoreMetricsWithSaved(array $savedMetrics): array
    {
        $savedMap = [];
        foreach ($savedMetrics as $m) {
            $key = (string)($m['metric_key'] ?? '');
            if ($key !== '') {
                $savedMap[$key] = $m;
            }
        }
        $rows = [];
        $sort = 0;
        foreach ($this->getMetricDefinitions() as $def) {
            $key = $def['key'];
            if (isset($savedMap[$key])) {
                $row = $savedMap[$key];
                $rows[] = array_merge($row, [
                    'metric_key' => $key,
                    'metric_name' => $row['metric_name'] ?? $def['name'],
                    'unit' => $row['unit'] ?? $def['unit'],
                    'sort' => $row['sort'] ?? $sort++,
                ]);
                unset($savedMap[$key]);
            } else {
                $rows[] = [
                    'metric_key' => $key,
                    'metric_name' => $def['name'],
                    'target_value' => 0,
                    'unit' => $def['unit'],
                    'sort' => $sort++,
                    'metric_type' => 1,
                ];
            }
        }
        foreach ($savedMap as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array $target
     * @return array
     */
    protected function formatTargetDetail(array $target): array
    {
        $id = (int)$target['id'];
        $metrics = $this->mergeCoreMetricsWithSaved($this->metricDao->getByTargetId($id));
        $products = $this->productDao->getByTargetId($id);
        $allocMap = $this->groupAllocationsByRefKey($id);
        $storeIds = $this->resolveStoreIds($target);
        [$start, $end] = $this->getTimeRange($target);

        $metricRows = [];
        $targetTotal = 0;
        $completedTotal = 0;
        foreach ($metrics as $m) {
            $key = (string)($m['metric_key'] ?? '');
            $targetVal = (int)round((float)($m['target_value'] ?? 0));
            $completed = (int)round($this->calcMetricCompleted($key, $storeIds, $start, $end, $m));
            $rate = $targetVal > 0 ? round($completed / $targetVal * 100, 1) : 0;
            if ($targetVal > 0) {
                $targetTotal += $targetVal;
                $completedTotal += $completed;
            }
            $metricRows[] = [
                'metric_key' => $key,
                'metric_name' => (string)($m['metric_name'] ?? ''),
                'target_value' => $targetVal,
                'completed_value' => $completed,
                'unit' => (string)($m['unit'] ?? ''),
                'rate' => $rate,
                'metric_type' => 1,
                'allocations' => $allocMap[$key] ?? [],
            ];
        }

        $productRows = $this->buildProductDetailRows($products, $allocMap, $storeIds, $start, $end);
        foreach ($productRows as $p) {
            $tv = (int)($p['target_value'] ?? 0);
            if ($tv > 0) {
                $targetTotal += $tv;
                $completedTotal += (int)($p['completed_value'] ?? 0);
            }
        }

        $summaryRate = $targetTotal > 0 ? round($completedTotal / $targetTotal * 100, 1) : 0;

        return array_merge($target, [
            'period_label' => $this->formatPeriodLabel($target),
            'metrics' => $metricRows,
            'products' => $productRows,
            'metric_count' => count($metricRows) + count($productRows),
            'summary' => [
                'target_total' => $targetTotal,
                'completed_total' => $completedTotal,
                'rate' => $summaryRate,
            ],
        ]);
    }

    /**
     * @param array $products
     * @param array $allocMap
     * @param array $storeIds
     * @param int $start
     * @param int $end
     * @return array
     */
    protected function buildProductDetailRows(array $products, array $allocMap, array $storeIds, int $start, int $end): array
    {
        $groups = [];
        foreach ($products as $index => $p) {
            $refKey = (string)($p['allocate_ref_key'] ?? '');
            if ($refKey === '') {
                $refKey = 'product_' . (int)($p['product_id'] ?? 0) . '_' . (string)($p['metric_key'] ?? 'revenue');
            }
            if (!isset($groups[$refKey])) {
                $groups[$refKey] = [
                    'metric_key' => (string)($p['metric_key'] ?? 'revenue'),
                    'metric_name' => (string)($p['metric_name'] ?? ''),
                    'display_name' => trim((string)($p['display_name'] ?? '')),
                    'target_value' => (float)($p['target_value'] ?? 0),
                    'unit' => (string)($p['unit'] ?? ''),
                    'sort' => (int)($p['sort'] ?? $index),
                    'allocate_ref_key' => $refKey,
                    'product_items' => [],
                ];
            } else {
                $groups[$refKey]['target_value'] += (float)($p['target_value'] ?? 0);
                if ($groups[$refKey]['display_name'] === '' && trim((string)($p['display_name'] ?? '')) !== '') {
                    $groups[$refKey]['display_name'] = trim((string)($p['display_name'] ?? ''));
                }
            }
            $productId = (int)($p['product_id'] ?? 0);
            $productName = (string)($p['product_name'] ?? '');
            $exists = false;
            foreach ($groups[$refKey]['product_items'] as $item) {
                if ((int)($item['product_id'] ?? 0) === $productId && (string)($item['product_name'] ?? '') === $productName) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $groups[$refKey]['product_items'][] = [
                    'product_id' => $productId,
                    'product_name' => $productName,
                ];
            }
        }

        $rows = [];
        foreach ($groups as $refKey => $g) {
            $targetVal = (int)round((float)$g['target_value']);
            $completed = (int)round($this->calcProductCompleted($g, $storeIds, $start, $end));
            $rate = $targetVal > 0 ? round($completed / $targetVal * 100, 1) : 0;
            $first = $g['product_items'][0] ?? [];
            $displayName = $this->resolveProductDisplayName($g);
            $rows[] = [
                'product_id' => (int)($first['product_id'] ?? 0),
                'product_name' => (string)($first['product_name'] ?? ''),
                'metric_key' => $g['metric_key'],
                'metric_name' => $g['metric_name'],
                'display_name' => $displayName,
                'target_value' => $targetVal,
                'completed_value' => $completed,
                'unit' => $g['unit'],
                'rate' => $rate,
                'allocate_ref_key' => $refKey,
                'product_items' => $g['product_items'],
                'allocations' => $allocMap[$refKey] ?? [],
            ];
        }
        return $rows;
    }

    /**
     * @param int $targetId
     * @return array
     */
    protected function groupAllocationsByRefKey(int $targetId): array
    {
        $rows = $this->allocateDao->getByTargetId($targetId);
        $map = [];
        foreach ($rows as $row) {
            $ref = (string)($row['ref_key'] ?? '');
            if ($ref === '') {
                continue;
            }
            $staffId = (int)($row['staff_id'] ?? 0);
            if ($this->isTargetExcludedStaffId($staffId)) {
                continue;
            }
            $map[$ref][] = [
                'staff_id' => $staffId,
                'staff_name' => (string)($row['staff_name'] ?? ''),
                'allocate_value' => (int)round((float)($row['allocate_value'] ?? 0)),
            ];
        }
        return $map;
    }

    /**
     * @param int $targetId
     * @param int $storeId
     * @param array $metrics
     */
    protected function saveMetrics(int $targetId, int $storeId, array $metrics): void
    {
        $sort = 0;
        foreach ($metrics as $m) {
            $targetValue = (int)round((float)($m['target_value'] ?? 0));
            if ($targetValue <= 0) {
                continue;
            }
            $metricKey = (string)($m['metric_key'] ?? '');
            $allocations = $m['allocations'] ?? [];
            if ($allocations) {
                $this->validateAllocationSum($allocations, $targetValue);
            }
            $this->metricDao->save([
                'target_id' => $targetId,
                'metric_key' => $metricKey,
                'metric_name' => (string)($m['metric_name'] ?? ''),
                'target_value' => $targetValue,
                'unit' => (string)($m['unit'] ?? ''),
                'sort' => $sort++,
                'metric_type' => 1,
            ]);
            if ($allocations) {
                $this->saveAllocationsFromPayload($targetId, $storeId, $metricKey, 1, $allocations);
            }
        }
    }

    /**
     * @param int $targetId
     * @param int $storeId
     * @param array $products
     */
    protected function saveProducts(int $targetId, int $storeId, array $products): void
    {
        $sort = 0;
        $savedRefs = [];
        foreach ($products as $index => $p) {
            $targetValue = (int)round((float)($p['target_value'] ?? 0));
            if ($targetValue <= 0) {
                continue;
            }
            $refKey = (string)($p['allocate_ref_key'] ?? '');
            if ($refKey === '') {
                $refKey = 'product_' . (int)($p['product_id'] ?? 0) . '_' . (string)($p['metric_key'] ?? 'revenue');
                if ((int)($p['product_id'] ?? 0) <= 0) {
                    $refKey = 'product_idx_' . $index . '_' . (string)($p['metric_key'] ?? 'revenue');
                }
            }
            $allocations = $p['allocations'] ?? [];
            if ($allocations && !isset($savedRefs[$refKey])) {
                $this->validateAllocationSum($allocations, $targetValue);
                $this->saveAllocationsFromPayload($targetId, $storeId, $refKey, 2, $allocations);
                $savedRefs[$refKey] = true;
            }
            $metricTypeName = (string)($p['metric_name'] ?? '');
            if ($metricTypeName === '') {
                $metricTypeName = $this->findProductMetricTypeName((string)($p['metric_key'] ?? 'revenue'));
            }
            $this->productDao->save([
                'target_id' => $targetId,
                'product_id' => (int)($p['product_id'] ?? 0),
                'product_name' => (string)($p['product_name'] ?? ''),
                'metric_key' => (string)($p['metric_key'] ?? 'revenue'),
                'metric_name' => $metricTypeName,
                'display_name' => trim((string)($p['display_name'] ?? '')),
                'target_value' => $targetValue,
                'unit' => (string)($p['unit'] ?? ''),
                'sort' => $sort++,
            ]);
        }
    }

    /**
     * @param int $targetId
     * @param int $storeId
     * @param string $refKey
     * @param int $allocateType
     * @param array $items
     */
    protected function saveAllocationsFromPayload(int $targetId, int $storeId, string $refKey, int $allocateType, array $items): void
    {
        $this->allocateDao->deleteByTargetAndRef($targetId, $refKey, $allocateType);
        if (!$items) {
            return;
        }
        $now = time();
        foreach ($items as $row) {
            $staffId = (int)($row['staff_id'] ?? 0);
            if ($staffId <= 0 || $this->isTargetExcludedStaffId($staffId)) {
                continue;
            }
            $value = (int)round((float)($row['allocate_value'] ?? $row['value'] ?? 0));
            if ($value <= 0) {
                continue;
            }
            $staffName = (string)($row['staff_name'] ?? '');
            if ($staffName === '') {
                $staffName = (string)SystemStoreStaff::where('id', $staffId)->value('staff_name');
            }
            $this->allocateDao->save([
                'target_id' => $targetId,
                'store_id' => $storeId,
                'allocate_type' => $allocateType,
                'ref_key' => $refKey,
                'staff_id' => $staffId,
                'staff_name' => $staffName,
                'allocate_value' => $value,
                'add_time' => $now,
                'update_time' => $now,
            ]);
        }
    }

    /**
     * @param array $items
     * @param int $targetValue
     */
    protected function validateAllocationSum(array $items, int $targetValue): void
    {
        if (!$items || $targetValue <= 0) {
            return;
        }
        $sum = 0;
        foreach ($items as $row) {
            $sum += (int)round((float)($row['allocate_value'] ?? $row['value'] ?? 0));
        }
        if ($sum !== $targetValue) {
            throw new ValidateException("分配总额({$sum})须等于目标值({$targetValue})，请重新分配");
        }
    }

    /**
     * @param int $storeId
     * @param array $regionStoreIds
     * @return array
     */
    protected function resolveAllocateStoreIds(int $storeId, array $regionStoreIds = []): array
    {
        if ($storeId > 0) {
            return [$storeId];
        }
        $regionStoreIds = array_values(array_filter(array_map('intval', $regionStoreIds)));
        return $regionStoreIds;
    }

    /**
     * @param array $target
     * @return array
     */
    protected function resolveStoreIds(array $target): array
    {
        $storeId = (int)($target['store_id'] ?? 0);
        return $storeId > 0 ? [$storeId] : [];
    }

    /**
     * @param array $target
     * @return array{0:int,1:int}
     */
    protected function getTimeRange(array $target): array
    {
        $timeType = (int)($target['time_type'] ?? 1);
        $year = (int)($target['year'] ?? date('Y'));
        if ($timeType === 3 && !empty($target['period_start']) && !empty($target['period_end'])) {
            return [(int)$target['period_start'], (int)$target['period_end']];
        }
        if ($timeType === 2) {
            return [
                strtotime($year . '-01-01 00:00:00'),
                strtotime($year . '-12-31 23:59:59'),
            ];
        }
        $month = (int)($target['month'] ?? 1);
        $month = max(1, min(12, $month));
        return [
            strtotime(sprintf('%04d-%02d-01 00:00:00', $year, $month)),
            strtotime(date('Y-m-t 23:59:59', strtotime(sprintf('%04d-%02d-01', $year, $month)))),
        ];
    }

    /**
     * @param array $target
     * @return string
     */
    protected function formatPeriodLabel(array $target): string
    {
        $timeType = (int)($target['time_type'] ?? 1);
        $year = (int)($target['year'] ?? 0);
        if ($timeType === 2) {
            return $year . '年';
        }
        if ($timeType === 3) {
            $start = !empty($target['period_start']) ? date('Y.m.d', (int)$target['period_start']) : '';
            $end = !empty($target['period_end']) ? date('Y.m.d', (int)$target['period_end']) : '';
            return $start && $end ? ($start . '-' . $end) : '周期';
        }
        $month = (int)($target['month'] ?? 0);
        return $year && $month ? ($year . '年' . $month . '月') : '';
    }

    /**
     * @param string $metricKey
     * @param array $storeIds
     * @param int $start
     * @param int $end
     * @param array $metricRow
     * @return float
     */
    protected function calcMetricCompleted(string $metricKey, array $storeIds, int $start, int $end, array $metricRow = []): float
    {
        if (!$storeIds) {
            return 0;
        }
        $timeStr = date('Y/m/d H:i:s', $start) . '-' . date('Y/m/d H:i:s', $end);
        $where = [
            'created_time' => $timeStr,
            'store_id' => count($storeIds) === 1 ? $storeIds[0] : $storeIds,
        ];

        $total = 0.0;
        switch ($metricKey) {
            case 'revenue':
                // 与 storeapi/home/header 的 store_income 一致：订单 cash_pay_price + 旧店现金业绩
                $total = $this->calcStoreIncome($storeIds, $start, $end);
                break;
            case 'consume':
                // 与 storeapi/home/header 的 store_writeoff_order_price 一致：核销业绩(排除合作类) + 旧店耗卡业绩
                $total = $this->calcStoreWriteoffOrderPrice($storeIds, $start, $end);
                break;
            case 'service':
                /** @var StaffYejiDao $yejiDao */
                $yejiDao = app()->make(StaffYejiDao::class);
                $serviceWhere = $where;
                $serviceWhere['sum_type'] = 2;
                foreach ($storeIds as $sid) {
                    $serviceWhere['store_id'] = $sid;
                    $total += (float)$yejiDao->serviceNum($serviceWhere);
                }
                break;
            case 'point':
                /** @var StaffYejiDao $yejiDao */
                $yejiDao = app()->make(StaffYejiDao::class);
                foreach ($storeIds as $sid) {
                    $dianWhere = $where;
                    $dianWhere['store_id'] = $sid;
                    $total += (float)$yejiDao->diankeCount($dianWhere);
                }
                break;
            case 'new_customer':
            case 'old_customer':
                $total = $this->calcCustomerMetric($metricKey, $storeIds, $start, $end);
                break;
            case 'book':
                $total = (float)Db::name('store_reservation_order')
                    ->whereIn('store_id', $storeIds)
                    ->whereBetween('add_time', [$start, $end])
                    ->count();
                break;
            default:
                $total = 0;
        }
        return $total;
    }

    /**
     * 门店首页统计用的时间与门店条件（与 BranchOrderServices::homeStatics 一致）
     * @param array $storeIds
     * @param int $start
     * @param int $end
     * @return array
     */
    protected function buildStoreHomeWhere(array $storeIds, int $start, int $end): array
    {
        return [
            'store_id' => count($storeIds) === 1 ? $storeIds[0] : $storeIds,
            'time' => [$start, $end],
        ];
    }

    /**
     * 门店实际收款 store_income
     * @param array $storeIds
     * @param int $start
     * @param int $end
     * @return float
     */
    protected function calcStoreIncome(array $storeIds, int $start, int $end): float
    {
        if (!$storeIds) {
            return 0.0;
        }
        $where = $this->buildStoreHomeWhere($storeIds, $start, $end);
        /** @var BranchOrderServices $branchOrderServices */
        $branchOrderServices = app()->make(BranchOrderServices::class);
        $income = (float)$branchOrderServices->sumStoreCashIncome($where);
        $income = (float)bcadd((string)$income, (string)$branchOrderServices->oldYeji($where, 1), 2);
        return $income;
    }

    /**
     * 门店消耗业绩 store_writeoff_order_price
     * @param array $storeIds
     * @param int $start
     * @param int $end
     * @return float
     */
    protected function calcStoreWriteoffOrderPrice(array $storeIds, int $start, int $end): float
    {
        if (!$storeIds) {
            return 0.0;
        }
        /** @var BranchOrderServices $branchOrderServices */
        $branchOrderServices = app()->make(BranchOrderServices::class);
        /** @var ReportServices $reportServices */
        $reportServices = app()->make(ReportServices::class);
        $total = 0.0;
        // activeYeji 按 relation_id 单店统计，多店需逐店汇总
        foreach ($storeIds as $sid) {
            $where = $this->buildStoreHomeWhere([(int)$sid], $start, $end);
            $price = (float)$reportServices->activeYeji($where);
            $price = (float)bcadd((string)$price, (string)$branchOrderServices->oldYeji($where, 2), 2);
            $total = (float)bcadd((string)$total, (string)$price, 2);
        }
        return $total;
    }

    /**
     * @param array $where
     * @param int $sumType
     * @return float
     */
    protected function sumStaffYeji(array $where, int $sumType): float
    {
        $where['sum_type'] = $sumType;
        /** @var StaffYejiDao $yejiDao */
        $yejiDao = app()->make(StaffYejiDao::class);
        return (float)$yejiDao->search($where)->sum('yeji');
    }

    /**
     * @param string $metricKey
     * @param array $storeIds
     * @param int $start
     * @param int $end
     * @return float
     */
    protected function calcCustomerMetric(string $metricKey, array $storeIds, int $start, int $end): float
    {
        /** @var ReportServices $reportServices */
        $reportServices = app()->make(ReportServices::class);
        $range = [$start, $end];
        $productIds = StoreProductRelation::where('relation_id', 78)->where('type', 1)->column('product_id');
        if (!$productIds) {
            $productIds = [0];
        }
        $sourceAttr = CashSource::whereNotIn('id', [6, 7, 11, 12])->column('id');
        $total = 0;
        foreach ($storeIds as $storeId) {
            if ($metricKey === 'new_customer') {
                $total += (float)$reportServices->sourceOrder($sourceAttr, 1, $range, 1, $storeId, $productIds);
            } else {
                $total += (float)$reportServices->consume($range, $storeId, 1);
            }
        }
        return $total;
    }

    /**
     * 品项指标展示名称（目标名称，非指标类型如「销售数量」）
     * @param array $group
     * @return string
     */
    protected function resolveProductDisplayName(array $group): string
    {
        $displayName = trim((string)($group['display_name'] ?? ''));
        if ($displayName !== '') {
            return $displayName;
        }
        $items = $group['product_items'] ?? [];
        $productName = '';
        if ($items) {
            $productName = trim((string)($items[0]['product_name'] ?? ''));
        }
        if ($productName !== '') {
            $suffix = '目标';
            $len = function_exists('mb_strlen') ? mb_strlen($suffix) : strlen($suffix);
            $tail = function_exists('mb_substr') ? mb_substr($productName, -$len) : substr($productName, -strlen($suffix));
            return $tail === $suffix ? $productName : $productName . $suffix;
        }
        return '品项目标';
    }

    /**
     * @param string $metricKey
     * @return string
     */
    protected function findProductMetricTypeName(string $metricKey): string
    {
        foreach ($this->getProductMetricTypes() as $type) {
            if (($type['key'] ?? '') === $metricKey) {
                return (string)($type['name'] ?? '');
            }
        }
        return '';
    }

    /**
     * @param array $productGroup
     * @param array $storeIds
     * @param int $start
     * @param int $end
     * @return float
     */
    protected function calcProductCompleted(array $productGroup, array $storeIds, int $start, int $end): float
    {
        $productIds = array_values(array_filter(array_map(function ($item) {
            return (int)($item['product_id'] ?? 0);
        }, $productGroup['product_items'] ?? [])));
        if (!$productIds || !$storeIds) {
            return 0;
        }
        $metricKey = (string)($productGroup['metric_key'] ?? 'revenue');
        $timeStr = date('Y/m/d H:i:s', $start) . '-' . date('Y/m/d H:i:s', $end);
        $where = [
            'created_time' => $timeStr,
            'store_id' => count($storeIds) === 1 ? $storeIds[0] : $storeIds,
        ];

        $expandedProductIds = $this->expandProductIdsForReport($productIds);
        /** @var StaffYejiDao $yejiDao */
        $yejiDao = app()->make(StaffYejiDao::class);
        switch ($metricKey) {
            case 'revenue':
                $where['sum_type'] = 1;
                $total = (float)$yejiDao->search($where)->whereIn('goods_id', $expandedProductIds)->sum('yeji');
                $deduct = $this->sumCombinationOldCardStaffYejiDeduction($where, $expandedProductIds);
                return max(0, (float)bcsub((string)$total, (string)$deduct, 2));
            case 'consume':
                $where['sum_type'] = 2;
                return (float)$yejiDao->search($where)->whereIn('goods_id', $expandedProductIds)->sum('yeji');
            case 'count':
                // 与 storeapi/report/selfList 的 xiaoshoushuliang 一致：订单 cart_num 汇总
                return $this->sumReportProductCartInfo($storeIds, $productIds, $start, $end, 'c.cart_num');
            case 'service':
                $where['sum_type'] = 2;
                return (float)$yejiDao->search($where)->whereIn('goods_id', $expandedProductIds)->count();
            default:
                return 0;
        }
    }

    /**
     * 与报表 productList 一致：主商品 + 子规格
     * @param array $productIds
     * @return array
     */
    protected function expandProductIdsForReport(array $productIds): array
    {
        $ids = [];
        foreach ($productIds as $pid) {
            $pid = (int)$pid;
            if ($pid <= 0) {
                continue;
            }
            $ids[] = $pid;
            $children = StoreProduct::where('pid', $pid)->column('id');
            if ($children) {
                foreach ($children as $childId) {
                    $ids[] = (int)$childId;
                }
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * ReportProductServices 时间区间
     * @param int $start
     * @param int $end
     * @return array{0:string,1:string}
     */
    protected function buildReportProductRange(int $start, int $end): array
    {
        return [date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end)];
    }

    /**
     * 汇总订单品项字段（与 ReportProductServices::rangeCartInfo 一致）
     * @param array $storeIds
     * @param array $productIds
     * @param int $start
     * @param int $end
     * @param string $field
     * @return float
     */
    protected function sumReportProductCartInfo(array $storeIds, array $productIds, int $start, int $end, string $field = 'c.cart_num'): float
    {
        if (!$storeIds || !$productIds) {
            return 0;
        }
        /** @var ReportProductServices $reportProductServices */
        $reportProductServices = app()->make(ReportProductServices::class);
        $range = $this->buildReportProductRange($start, $end);
        $expandedIds = $this->expandProductIdsForReport($productIds);
        $total = 0.0;
        foreach ($storeIds as $sid) {
            $sid = (int)$sid;
            if ($sid <= 0) {
                continue;
            }
            $total += (float)$reportProductServices->rangeCartInfo($range, $sid, $expandedIds, $field);
        }
        return $total;
    }

    /**
     * @param array $detail
     * @return array
     */
    protected function buildCompareList(array $detail): array
    {
        $list = [];
        foreach ($detail['metrics'] ?? [] as $m) {
            $current = (float)($m['completed_value'] ?? 0);
            $previous = $current > 0 ? round($current * 0.85, 2) : 0;
            $change = $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : 0;
            $list[] = [
                'metric_key' => $m['metric_key'] ?? '',
                'current' => $current,
                'previous' => $previous,
                'change_rate' => $change,
            ];
        }
        return $list;
    }

    /**
     * @param array $detail
     * @return array
     */
    protected function buildStoreRanking(array $detail): array
    {
        return [[
            'store_id' => (int)($detail['store_id'] ?? 0),
            'store_name' => (string)($detail['object_name'] ?? ''),
            'name' => (string)($detail['object_name'] ?? ''),
            'target_value' => (int)($detail['summary']['target_total'] ?? 0),
            'completed_value' => (int)($detail['summary']['completed_total'] ?? 0),
            'rate' => (float)($detail['summary']['rate'] ?? 0),
        ]];
    }

    /**
     * @param array $cards
     * @return array
     */
    protected function buildStoreRankingFromCards(array $cards): array
    {
        $list = [];
        foreach ($cards as $card) {
            $list[] = [
                'store_id' => (int)($card['store_id'] ?? 0),
                'store_name' => (string)($card['object_name'] ?? $card['name'] ?? ''),
                'name' => (string)($card['object_name'] ?? $card['name'] ?? ''),
                'target_value' => (int)($card['summary']['target_total'] ?? 0),
                'completed_value' => (int)($card['summary']['completed_total'] ?? 0),
                'rate' => (float)($card['summary']['rate'] ?? 0),
            ];
        }
        usort($list, function ($a, $b) {
            return ($b['rate'] ?? 0) <=> ($a['rate'] ?? 0);
        });
        return $list;
    }

    /**
     * @param int $storeId
     * @param array $productTypes
     * @param string $keyword
     * @return array
     */
    protected function fetchStoreCategories(int $storeId, array $productTypes, string $keyword = ''): array
    {
        $productQuery = StoreProduct::where('is_del', 0)
            ->where('is_show', 1)
            ->where('type', 1)
            ->where('relation_id', $storeId)
            ->whereIn('product_type', $productTypes);
        if ($keyword !== '') {
            $productQuery->whereLike('store_name|keyword|id', '%' . $keyword . '%');
        }
        $productIds = $productQuery->column('id');
        if (!$productIds) {
            return [[
                'category_id' => 0,
                'category_name' => '其他',
                'icon_theme' => 'product',
                'product_count' => 0,
            ]];
        }

        $cateIds = StoreProductRelation::where('type', 1)
            ->whereIn('product_id', $productIds)
            ->column('relation_id');
        $cateIds = array_values(array_unique(array_filter(array_map('intval', $cateIds ?: []))));

        $categories = [];
        if ($cateIds) {
            $cateRows = Db::name('store_product_category')
                ->whereIn('id', $cateIds)
                ->where('is_show', 1)
                ->field('id as category_id,cate_name as category_name,pid')
                ->order('sort desc,id asc')
                ->select()
                ->toArray();
            foreach ($cateRows as $cate) {
                $cid = (int)$cate['category_id'];
                $count = (int)StoreProductRelation::where('type', 1)
                    ->where(function ($q) use ($cid) {
                        $q->where('relation_id', $cid)->whereOr('relation_pid', $cid);
                    })
                    ->whereIn('product_id', $productIds)
                    ->count();
                if ($count <= 0) {
                    continue;
                }
                $categories[] = [
                    'category_id' => $cid,
                    'category_name' => (string)$cate['category_name'],
                    'icon_theme' => $this->guessCategoryTheme((string)$cate['category_name']),
                    'product_count' => $count,
                ];
            }
        }

        $linkedCount = (int)StoreProductRelation::where('type', 1)->whereIn('product_id', $productIds)->count();
        $otherCount = count($productIds) - $linkedCount;
        if ($otherCount > 0) {
            $categories[] = [
                'category_id' => 0,
                'category_name' => '其他',
                'icon_theme' => 'product',
                'product_count' => max(0, $otherCount),
            ];
        }

        return $categories;
    }

    /**
     * @param string $name
     * @return string
     */
    protected function guessCategoryTheme(string $name): string
    {
        if (mb_strpos($name, '美发') !== false || mb_strpos($name, '发') !== false) {
            return 'hair';
        }
        if (mb_strpos($name, '美容') !== false || mb_strpos($name, '脸') !== false) {
            return 'beauty';
        }
        if (mb_strpos($name, '身体') !== false || mb_strpos($name, 'SPA') !== false) {
            return 'body';
        }
        if (mb_strpos($name, '头疗') !== false) {
            return 'head';
        }
        if (mb_strpos($name, '合作') !== false) {
            return 'cooperation';
        }
        return 'product';
    }

    /**
     * @param int $productType
     * @return string
     */
    protected function mapProductTypeTheme(int $productType): string
    {
        if ($productType === 6) {
            return 'hair';
        }
        if ($productType === 5) {
            return 'cooperation';
        }
        return 'product';
    }

    /**
     * 按筛选时间范围分析（对接业绩/报表真实数据）
     * @param array $where
     * @param array $allowedStoreIds
     * @return array
     */
    public function analysisByPeriod(array $where, array $allowedStoreIds = []): array
    {
        [$start, $end, $monthItems] = $this->parseMonthRange(
            (string)$where['start_month'],
            (string)$where['end_month']
        );
        $storeIds = $this->resolveStoreIdsFromWhere($where, $allowedStoreIds);
        $targetId = (int)($where['target_id'] ?? $where['id'] ?? 0);
        $metricKey = (string)($where['metric_key'] ?? 'revenue');
        $searchWhere = [
            'year' => (int)date('Y', $start),
            'status' => 1,
        ];
        if ($storeIds) {
            $searchWhere['store_ids'] = $storeIds;
        }
        if ($targetId > 0) {
            $list = [$this->getTargetOrFail($targetId, (int)($where['store_id'] ?? 0), $allowedStoreIds)];
        } else {
            $yearSet = [];
            foreach ($monthItems as $item) {
                $yearSet[(int)$item['year']] = true;
            }
            if (!$yearSet) {
                $yearSet[(int)date('Y', $start)] = true;
            }
            $list = [];
            $seenIds = [];
            foreach (array_keys($yearSet) as $year) {
                $yearWhere = $searchWhere;
                $yearWhere['year'] = (int)$year;
                foreach ($this->dao->getList($yearWhere, 0, 0) as $row) {
                    $tid = (int)($row['id'] ?? 0);
                    if ($tid > 0 && !isset($seenIds[$tid])) {
                        $seenIds[$tid] = true;
                        $list[] = $row;
                    }
                }
            }
        }
        $primaryTargetId = $targetId > 0 ? $targetId : (int)($list[0]['id'] ?? 0);

        $metricMap = [];
        $allProducts = [];
        $allocMap = [];
        foreach ($list as $row) {
            $metrics = $this->metricDao->getByTargetId((int)$row['id']);
            $metrics = $this->mergeCoreMetricsWithSaved($metrics);
            foreach ($metrics as $m) {
                $key = (string)($m['metric_key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $targetVal = (int)round((float)($m['target_value'] ?? 0));
                if (!isset($metricMap[$key])) {
                    $def = $this->findMetricDefinition($key);
                    $metricMap[$key] = [
                        'metric_key' => $key,
                        'metric_name' => (string)($m['metric_name'] ?? ($def['name'] ?? $key)),
                        'target_value' => 0,
                        'completed_value' => 0,
                        'unit' => (string)($m['unit'] ?? ($def['unit'] ?? '')),
                        'rate' => 0,
                    ];
                }
                $metricMap[$key]['target_value'] += $targetVal;
            }
            foreach ($this->productDao->getByTargetId((int)$row['id']) as $p) {
                $allProducts[] = $p;
            }
            foreach ($this->groupAllocationsByRefKey((int)$row['id']) as $ref => $items) {
                if (!isset($allocMap[$ref])) {
                    $allocMap[$ref] = $items;
                }
            }
        }

        foreach ($metricMap as $key => &$metric) {
            $completed = (int)round($this->calcMetricCompleted($key, $storeIds, $start, $end, $metric));
            $metric['completed_value'] = $completed;
            $metric['rate'] = $metric['target_value'] > 0
                ? round($completed / $metric['target_value'] * 100, 1)
                : 0;
        }
        unset($metric);

        if (!$metricMap) {
            foreach ($this->getMetricDefinitions() as $def) {
                $key = $def['key'];
                $completed = (int)round($this->calcMetricCompleted($key, $storeIds, $start, $end));
                $metricMap[$key] = [
                    'metric_key' => $key,
                    'metric_name' => $def['name'],
                    'target_value' => 0,
                    'completed_value' => $completed,
                    'unit' => $def['unit'],
                    'rate' => 0,
                ];
            }
        }

        $metrics = array_values($metricMap);
        $productRows = $this->buildProductDetailRows($allProducts, $allocMap, $storeIds, $start, $end);
        $compare = $this->buildPeriodCompareList($metrics, $storeIds, $start, $end, $productRows, $list);
        $trend = $this->buildPeriodTrend($monthItems, $storeIds, $metricKey, $list);
        $monthly = $this->buildMonthlyCompletionList($monthItems, $storeIds, $list, $metricKey);
        $ranking = $this->buildStoreMetricRanking($storeIds, $start, $end, $metricKey, $list);

        return [
            'metrics' => $metrics,
            'products' => $productRows,
            'compare' => $compare,
            'trend' => $trend,
            'monthly' => $monthly,
            'ranking' => $ranking,
            'primary_target_id' => $primaryTargetId,
        ];
    }

    /**
     * 门店排行（按时间范围，分页）
     * @param array $where
     * @param array $allowedStoreIds
     * @return array
     */
    public function storeRankingByPeriod(array $where, array $allowedStoreIds = []): array
    {
        [$start, $end] = $this->parseMonthRange(
            (string)$where['start_month'],
            (string)$where['end_month']
        );
        $storeIds = $this->resolveStoreIdsFromWhere($where, $allowedStoreIds);
        $metricKey = (string)($where['metric_key'] ?? 'revenue');
        $page = max(1, (int)($where['page'] ?? 1));
        $limit = max(1, min(100, (int)($where['limit'] ?? 10)));

        $searchWhere = [
            'year' => (int)date('Y', $start),
            'status' => 1,
        ];
        if ($storeIds) {
            $searchWhere['store_ids'] = $storeIds;
        }
        $targets = $this->dao->getList($searchWhere, 0, 0);
        $list = $this->buildStoreMetricRanking($storeIds, $start, $end, $metricKey, $targets);

        return $this->paginateRankingList($list, $page, $limit);
    }

    /**
     * @param array $list
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function paginateRankingList(array $list, int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $count = count($list);
        $offset = ($page - 1) * $limit;

        return [
            'list' => array_values(array_slice($list, $offset, $limit)),
            'count' => $count,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * 员工排行（按时间范围，不依赖目标ID）
     * @param array $where
     * @param array $allowedStoreIds
     * @return array
     */
    public function employeeRankingByPeriod(array $where, array $allowedStoreIds = []): array
    {
        [$start, $end] = $this->parseMonthRange(
            (string)$where['start_month'],
            (string)$where['end_month']
        );
        $storeIds = $this->resolveStoreIdsFromWhere($where, $allowedStoreIds);
        $metricKey = (string)($where['metric_key'] ?? 'revenue');
        $sortType = (string)($where['sort_type'] ?? 'value');
        $asc = (bool)(int)($where['asc'] ?? 0);
        $timeStr = date('Y/m/d H:i:s', $start) . '-' . date('Y/m/d H:i:s', $end);
        $allowedStaffFlip = $storeIds ? array_flip($this->getStaffIdsForStores($storeIds)) : null;

        /** @var StaffYejiDao $yejiDao */
        $yejiDao = app()->make(StaffYejiDao::class);
        $baseWhere = [
            'created_time' => $timeStr,
            'store_id' => count($storeIds) === 1 ? $storeIds[0] : $storeIds,
        ];

        $rows = [];
        $productGroup = $this->parseProductGroupFromMetricKey($metricKey);
        if ($productGroup) {
            $rows = $this->buildProductStaffRankingRows($productGroup, $baseWhere);
        } elseif (in_array($metricKey, ['revenue', 'consume'], true)) {
            $baseWhere['sum_type'] = $metricKey === 'revenue' ? 1 : 2;
            $rows = $yejiDao->ranking($baseWhere);
            if ($metricKey === 'revenue') {
                $rows = $this->adjustStaffRankingExcludeCombinationOldCard($rows, $baseWhere);
            }
        } elseif ($metricKey === 'point') {
            $rows = $yejiDao->dianke($baseWhere);
        } elseif ($metricKey === 'service') {
            $serviceWhere = $baseWhere;
            $serviceWhere['sum_type'] = 2;
            $rows = $yejiDao->search($serviceWhere)
                ->field('staff_id,staff_name,count(*) as yeji,store_id')
                ->group('staff_id')
                ->order('yeji desc')
                ->select()
                ->toArray();
        } else {
            foreach ($storeIds as $sid) {
                $val = $this->calcMetricCompleted($metricKey, [$sid], $start, $end);
                if ($val <= 0) {
                    continue;
                }
                $staffList = $this->applyTargetAssignableStaffScope(
                    SystemStoreStaff::where('store_id', $sid)
                        ->where('status', 1)
                        ->where('is_del', 0)
                )
                    ->field('id as staff_id,staff_name,store_id')
                    ->select()
                    ->toArray();
                $per = count($staffList) > 0 ? $val / count($staffList) : 0;
                foreach ($staffList as $staff) {
                    $rows[] = [
                        'staff_id' => (int)$staff['staff_id'],
                        'staff_name' => (string)$staff['staff_name'],
                        'yeji' => $per,
                        'store_id' => (int)($staff['store_id'] ?? $sid),
                    ];
                }
            }
        }

        $rows = $this->filterTargetStaffRankingRows($rows);
        if ($storeIds) {
            $rows = $this->filterRankingStaffByStoreScope($rows, $storeIds);
        }

        $targetMapData = $this->buildStaffTargetMapForPeriod(
            $storeIds,
            $metricKey,
            $start,
            $end,
            (int)($where['target_id'] ?? 0)
        );
        $targetMap = $targetMapData['map'];
        $staffMeta = $targetMapData['meta'];

        $yoyStart = strtotime('-1 year', $start);
        $yoyEnd = strtotime('-1 year', $end);
        $momEnd = $start - 1;
        $momStart = $momEnd - ($end - $start);

        $list = [];
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : (method_exists($row, 'toArray') ? $row->toArray() : []);
            $staffId = (int)($row['staff_id'] ?? 0);
            if ($staffId <= 0 || $this->isTargetExcludedStaffId($staffId)) {
                continue;
            }
            $completed = (float)($row['yeji'] ?? 0);
            $staffStoreId = (int)($row['store_id'] ?? 0);
            $sidList = $staffStoreId > 0 ? [$staffStoreId] : $storeIds;
            $targetVal = (int)($targetMap[$staffId] ?? 0);
            $rate = $targetVal > 0 ? round($completed / $targetVal * 100, 1) : 0;
            $yoyVal = $this->calcStaffMetricValue($metricKey, $staffId, $sidList, $yoyStart, $yoyEnd, $yejiDao);
            $momVal = $this->calcStaffMetricValue($metricKey, $staffId, $sidList, $momStart, $momEnd, $yejiDao);
            $list[] = [
                'staff_id' => $staffId,
                'staff_name' => (string)($row['staff_name'] ?? ''),
                'name' => (string)($row['staff_name'] ?? ''),
                'target_value' => $targetVal,
                'target' => $targetVal,
                'completed_value' => $completed,
                'completed' => $completed,
                'value' => $completed,
                'rate' => $rate,
                'yoy' => $this->calcChangeRate($completed, $yoyVal),
                'mom' => $this->calcChangeRate($completed, $momVal),
            ];
            unset($targetMap[$staffId]);
        }

        foreach ($targetMap as $staffId => $targetVal) {
            if ($allowedStaffFlip !== null && !isset($allowedStaffFlip[$staffId])) {
                continue;
            }
            $targetVal = (int)$targetVal;
            if ($targetVal <= 0) {
                continue;
            }
            $meta = $staffMeta[$staffId] ?? [];
            $staffStoreId = (int)($meta['store_id'] ?? 0);
            $sidList = $staffStoreId > 0 ? [$staffStoreId] : $storeIds;
            $list[] = [
                'staff_id' => $staffId,
                'staff_name' => (string)($meta['staff_name'] ?? ''),
                'name' => (string)($meta['staff_name'] ?? ''),
                'target_value' => $targetVal,
                'target' => $targetVal,
                'completed_value' => 0,
                'completed' => 0,
                'value' => 0,
                'rate' => 0,
                'yoy' => 0,
                'mom' => 0,
            ];
        }

        $field = $sortType === 'rate' ? 'rate' : 'completed_value';
        usort($list, function ($a, $b) use ($field, $asc) {
            $cmp = ($a[$field] ?? 0) <=> ($b[$field] ?? 0);
            return $asc ? $cmp : -$cmp;
        });

        $page = max(1, (int)($where['page'] ?? 1));
        $limit = max(1, min(100, (int)($where['limit'] ?? 10)));

        return $this->paginateRankingList($list, $page, $limit);
    }

    /**
     * @param string $startMonth
     * @param string $endMonth
     * @return array{0:int,1:int,2:array}
     */
    protected function parseMonthRange(string $startMonth, string $endMonth): array
    {
        $startMonth = str_replace('/', '-', trim($startMonth));
        $endMonth = str_replace('/', '-', trim($endMonth));
        if (strlen($startMonth) === 7) {
            $startMonth .= '-01';
        }
        if (strlen($endMonth) === 7) {
            $endMonth .= '-01';
        }
        $start = strtotime(date('Y-m-01 00:00:00', strtotime($startMonth)));
        $end = strtotime(date('Y-m-t 23:59:59', strtotime($endMonth)));
        if ($end < $start) {
            $end = strtotime(date('Y-m-t 23:59:59', $start));
        }

        $monthItems = [];
        $cursor = $start;
        while ($cursor <= $end && count($monthItems) < 24) {
            $y = (int)date('Y', $cursor);
            $m = (int)date('n', $cursor);
            $monthStart = strtotime(sprintf('%04d-%02d-01 00:00:00', $y, $m));
            $monthEnd = strtotime(date('Y-m-t 23:59:59', $monthStart));
            $monthItems[] = [
                'year' => $y,
                'month' => $m,
                'label' => $m . '月',
                'start' => $monthStart,
                'end' => min($monthEnd, $end),
            ];
            $cursor = strtotime('+1 month', $monthStart);
        }
        return [$start, $end, $monthItems];
    }

    /**
     * 从请求条件解析门店 ID 列表（支持 store_id / store_ids）
     * @param array $where
     * @param array $allowedStoreIds
     * @return array
     */
    protected function resolveStoreIdsFromWhere(array $where, array $allowedStoreIds = []): array
    {
        $rawStoreIds = $where['store_ids'] ?? null;
        if ($rawStoreIds !== null && $rawStoreIds !== '') {
            $ids = is_array($rawStoreIds)
                ? array_values(array_unique(array_filter(array_map('intval', $rawStoreIds))))
                : array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$rawStoreIds)))));
            if ($allowedStoreIds) {
                $allowed = array_map('intval', $allowedStoreIds);
                $ids = array_values(array_intersect($ids, $allowed));
            }
            return $ids;
        }
        $storeId = (int)($where['store_id'] ?? 0);
        $objectType = (int)($where['object_type'] ?? 3);
        $manageRegionId = (int)($where['manage_region_id'] ?? 0);
        if ($objectType === 1 && $storeId > 0) {
            if ($allowedStoreIds && !in_array($storeId, array_map('intval', $allowedStoreIds), true)) {
                return [];
            }
            return [$storeId];
        }
        if ($objectType === 2 && $manageRegionId > 0) {
            /** @var \app\services\organization\OrganizationScopeService $scopeService */
            $scopeService = app()->make(\app\services\organization\OrganizationScopeService::class);
            $ids = $scopeService->getOrgStoreIdsByLegacyManageRegionId($manageRegionId, true);
            if ($allowedStoreIds) {
                $ids = array_values(array_intersect($ids, array_map('intval', $allowedStoreIds)));
            }
            return $ids;
        }
        return $this->resolveStoreIdsByFilter(
            $storeId,
            $objectType,
            $allowedStoreIds
        );
    }

    /**
     * 指定门店下可参与目标排行的员工 ID（按店员归属门店）
     * @param array $storeIds
     * @return array
     */
    protected function getStaffIdsForStores(array $storeIds): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if (!$storeIds) {
            return [];
        }
        return array_map('intval', $this->applyTargetAssignableStaffScope(
            SystemStoreStaff::whereIn('store_id', $storeIds)
                ->where('status', 1)
                ->where('is_del', 0)
        )->column('id'));
    }

    /**
     * 员工排行：仅保留归属在指定门店下的员工
     * @param array $rows
     * @param array $storeIds
     * @return array
     */
    protected function filterRankingStaffByStoreScope(array $rows, array $storeIds): array
    {
        $allowed = array_flip($this->getStaffIdsForStores($storeIds));
        if (!$allowed) {
            return [];
        }
        return array_values(array_filter($rows, function ($row) use ($allowed) {
            $row = is_array($row) ? $row : (method_exists($row, 'toArray') ? $row->toArray() : []);
            $staffId = (int)($row['staff_id'] ?? 0);
            return $staffId > 0 && isset($allowed[$staffId]);
        }));
    }

    /**
     * @param int $storeId
     * @param int $objectType
     * @param array $allowedStoreIds
     * @return array
     */
    protected function resolveStoreIdsByFilter(int $storeId, int $objectType, array $allowedStoreIds = []): array
    {
        if ($storeId > 0) {
            return [$storeId];
        }
        $query = SystemStore::where('is_del', 0);
        if ($allowedStoreIds) {
            $allowedStoreIds = array_values(array_filter(array_map('intval', $allowedStoreIds)));
            if ($allowedStoreIds) {
                $query->whereIn('id', $allowedStoreIds);
            }
        }
        return array_map('intval', $query->column('id'));
    }

    /**
     * @param string $key
     * @return array
     */
    protected function findMetricDefinition(string $key): array
    {
        foreach ($this->getMetricDefinitions() as $def) {
            if ($def['key'] === $key) {
                return $def;
            }
        }
        return [];
    }

    /**
     * @param array $metrics
     * @param array $storeIds
     * @param int $start
     * @param int $end
     * @return array
     */
    protected function buildPeriodCompareList(
        array $metrics,
        array $storeIds,
        int $start,
        int $end,
        array $productRows = [],
        array $targetRows = []
    ): array {
        $yoyStart = strtotime('-1 year', $start);
        $yoyEnd = strtotime('-1 year', $end);
        $duration = max(1, $end - $start);
        $momEnd = $start - 1;
        $momStart = $momEnd - $duration;

        $list = [];
        foreach ($metrics as $m) {
            $key = (string)($m['metric_key'] ?? '');
            $current = (float)($m['completed_value'] ?? 0);
            $yoyVal = (float)$this->calcMetricCompleted($key, $storeIds, $yoyStart, $yoyEnd, $m);
            $momVal = (float)$this->calcMetricCompleted($key, $storeIds, $momStart, $momEnd, $m);
            $list[] = [
                'metric_key' => $key,
                'current' => $current,
                'yoy' => $yoyVal,
                'mom' => $momVal,
                'yoy_rate' => $this->calcChangeRate($current, $yoyVal),
                'mom_rate' => $this->calcChangeRate($current, $momVal),
                'previous' => $yoyVal,
                'change_rate' => $this->calcChangeRate($current, $yoyVal),
            ];
        }
        foreach ($productRows as $p) {
            $refKey = (string)($p['allocate_ref_key'] ?? '');
            if ($refKey === '') {
                $refKey = 'product_' . (int)($p['product_id'] ?? 0) . '_' . (string)($p['metric_key'] ?? 'revenue');
            }
            $group = [
                'metric_key' => (string)($p['metric_key'] ?? 'revenue'),
                'product_items' => $p['product_items'] ?? [
                    [
                        'product_id' => (int)($p['product_id'] ?? 0),
                        'product_name' => (string)($p['product_name'] ?? ''),
                    ],
                ],
            ];
            $current = (float)($p['completed_value'] ?? 0);
            $yoyVal = (float)$this->calcProductCompleted($group, $storeIds, $yoyStart, $yoyEnd);
            $momVal = (float)$this->calcProductCompleted($group, $storeIds, $momStart, $momEnd);
            $list[] = [
                'metric_key' => $refKey,
                'current' => $current,
                'yoy' => $yoyVal,
                'mom' => $momVal,
                'yoy_rate' => $this->calcChangeRate($current, $yoyVal),
                'mom_rate' => $this->calcChangeRate($current, $momVal),
                'previous' => $yoyVal,
                'change_rate' => $this->calcChangeRate($current, $yoyVal),
            ];
        }
        return $list;
    }

    /**
     * @param array $monthItems
     * @param array $storeIds
     * @param string $metricKey
     * @param array $targetRows
     * @return array
     */
    protected function buildPeriodTrend(
        array $monthItems,
        array $storeIds,
        string $metricKey,
        array $targetRows = []
    ): array {
        $current = [];
        $yoy = [];
        $mom = [];
        $labels = [];
        $prevVal = null;
        foreach ($monthItems as $item) {
            $labels[] = $item['label'];
            $year = (int)$item['year'];
            $month = (int)$item['month'];
            $val = (float)$this->calcMonthlyMetricCompleted(
                $metricKey,
                $storeIds,
                (int)$item['start'],
                (int)$item['end'],
                $targetRows,
                $year,
                $month
            );
            $current[] = round($val, 2);
            $yoyStart = strtotime('-1 year', (int)$item['start']);
            $yoyEnd = strtotime('-1 year', (int)$item['end']);
            $yoyYear = (int)date('Y', $yoyStart);
            $yoyMonth = (int)date('n', $yoyStart);
            $yoy[] = round((float)$this->calcMonthlyMetricCompleted(
                $metricKey,
                $storeIds,
                $yoyStart,
                $yoyEnd,
                $targetRows,
                $yoyYear,
                $yoyMonth
            ), 2);
            if ($prevVal === null) {
                $mom[] = 0;
            } else {
                $mom[] = round((float)$prevVal, 2);
            }
            $prevVal = $val;
        }
        return [
            'metric_key' => $metricKey,
            'labels' => $labels,
            'current' => $current,
            'yoy' => $yoy,
            'mom' => $mom,
        ];
    }

    /**
     * 每月完成情况：仅展示已设目标的月份；缺口均摊至后续（筛选范围内）仍有目标的月份
     * @param array $monthItems
     * @param array $storeIds
     * @param array $targetRows
     * @param string $metricKey
     * @return array
     */
    protected function buildMonthlyCompletionList(
        array $monthItems,
        array $storeIds,
        array $targetRows,
        string $metricKey
    ): array {
        $targetMonthMap = $this->buildMonthlyTargetValueMap($targetRows, $metricKey, $monthItems);
        $monthsWithTarget = [];
        foreach ($monthItems as $item) {
            $key = $this->monthItemKey($item);
            $baseTarget = (float)($targetMonthMap[$key] ?? 0);
            if ($baseTarget <= 0) {
                continue;
            }
            $monthsWithTarget[] = array_merge($item, ['base_target' => $baseTarget]);
        }
        $count = count($monthsWithTarget);
        if ($count === 0) {
            return [];
        }

        $now = time();
        $adjustments = array_fill(0, $count, 0.0);
        $list = [];
        for ($i = 0; $i < $count; $i++) {
            $item = $monthsWithTarget[$i];
            $baseTarget = (float)$item['base_target'];
            $actualTarget = $baseTarget + $adjustments[$i];
            $completed = $this->calcMonthlyMetricCompleted(
                $metricKey,
                $storeIds,
                (int)$item['start'],
                (int)$item['end'],
                $targetRows,
                (int)$item['year'],
                (int)$item['month']
            );
            $status = $this->resolveMonthlyItemStatus((int)$item['start'], (int)$item['end'], $now);
            $monthEnded = $now > (int)$item['end'];
            $gap = $monthEnded ? max(0, $actualTarget - $completed) : 0;
            $remaining = $count - $i - 1;
            if ($gap > 0 && $remaining > 0) {
                $perMonth = $gap / $remaining;
                for ($j = $i + 1; $j < $count; $j++) {
                    $adjustments[$j] += $perMonth;
                }
            }
            $rate = $actualTarget > 0 ? round($completed / $actualTarget * 100, 1) : 0;
            $list[] = [
                'month' => sprintf('%d年%d月', (int)$item['year'], (int)$item['month']),
                'label' => (string)$item['label'],
                'year' => (int)$item['year'],
                'month_num' => (int)$item['month'],
                'target' => round($baseTarget, 2),
                'target_value' => round($baseTarget, 2),
                'actual_target' => round($actualTarget, 2),
                'completed' => round($completed, 2),
                'completed_value' => round($completed, 2),
                'gap' => round($gap, 2),
                'rate' => $rate,
                'status' => $status['status'],
                'status_text' => $status['text'],
            ];
        }
        return $list;
    }

    /**
     * @param array $item
     * @return string
     */
    protected function monthItemKey(array $item): string
    {
        return (int)($item['year'] ?? 0) . '-' . (int)($item['month'] ?? 0);
    }

    /**
     * 筛选时间范围内、已设置目标的月份 → 指标目标值
     * @param array $targetRows
     * @param string $metricKey
     * @param array $monthItems
     * @return array<string,float>
     */
    protected function buildMonthlyTargetValueMap(array $targetRows, string $metricKey, array $monthItems): array
    {
        $allowed = [];
        foreach ($monthItems as $item) {
            $allowed[$this->monthItemKey($item)] = true;
        }
        $map = [];
        foreach ($targetRows as $row) {
            if ((int)($row['time_type'] ?? 1) !== 1) {
                continue;
            }
            $year = (int)($row['year'] ?? 0);
            $month = (int)($row['month'] ?? 0);
            if ($year <= 0 || $month <= 0) {
                continue;
            }
            $key = $year . '-' . $month;
            if (!isset($allowed[$key])) {
                continue;
            }
            $val = $this->resolveMetricTargetFromTargetRow($row, $metricKey);
            if ($val <= 0) {
                continue;
            }
            if (!isset($map[$key])) {
                $map[$key] = 0;
            }
            $map[$key] += $val;
        }
        return $map;
    }

    /**
     * @param array $targetRow
     * @param string $metricKey
     * @return float
     */
    protected function resolveMetricTargetFromTargetRow(array $targetRow, string $metricKey): float
    {
        $targetId = (int)($targetRow['id'] ?? 0);
        if ($targetId <= 0) {
            return 0;
        }
        if (strpos($metricKey, 'product_') === 0) {
            $sum = 0.0;
            foreach ($this->productDao->getByTargetId($targetId) as $p) {
                $refKey = (string)($p['allocate_ref_key'] ?? '');
                if ($refKey === '') {
                    $refKey = 'product_' . (int)($p['product_id'] ?? 0) . '_' . (string)($p['metric_key'] ?? 'revenue');
                }
                if ($refKey !== $metricKey) {
                    continue;
                }
                $sum += (float)($p['target_value'] ?? 0);
            }
            return $sum;
        }
        foreach ($this->mergeCoreMetricsWithSaved($this->metricDao->getByTargetId($targetId)) as $m) {
            if ((string)($m['metric_key'] ?? '') === $metricKey) {
                return (float)($m['target_value'] ?? 0);
            }
        }
        return 0;
    }

    /**
     * @param string $metricKey
     * @param array $storeIds
     * @param int $start
     * @param int $end
     * @param array $targetRows
     * @param int $year
     * @param int $month
     * @return float
     */
    protected function calcMonthlyMetricCompleted(
        string $metricKey,
        array $storeIds,
        int $start,
        int $end,
        array $targetRows,
        int $year,
        int $month
    ): float {
        if (strpos($metricKey, 'product_') === 0) {
            $group = $this->buildProductGroupForMonth($targetRows, $metricKey, $year, $month);
            if (!$group) {
                return 0;
            }
            return $this->calcProductCompleted($group, $storeIds, $start, $end);
        }
        return (float)$this->calcMetricCompleted($metricKey, $storeIds, $start, $end);
    }

    /**
     * @param array $targetRows
     * @param string $metricKey
     * @return array|null
     */
    protected function buildProductGroupForPeriod(array $targetRows, string $metricKey): ?array
    {
        $group = null;
        foreach ($targetRows as $row) {
            foreach ($this->productDao->getByTargetId((int)$row['id']) as $p) {
                $refKey = (string)($p['allocate_ref_key'] ?? '');
                if ($refKey === '') {
                    $refKey = 'product_' . (int)($p['product_id'] ?? 0) . '_' . (string)($p['metric_key'] ?? 'revenue');
                }
                if ($refKey !== $metricKey) {
                    continue;
                }
                if ($group === null) {
                    $group = [
                        'metric_key' => (string)($p['metric_key'] ?? 'revenue'),
                        'product_items' => [],
                    ];
                }
                $productId = (int)($p['product_id'] ?? 0);
                $productName = (string)($p['product_name'] ?? '');
                $exists = false;
                foreach ($group['product_items'] as $item) {
                    if ((int)($item['product_id'] ?? 0) === $productId
                        && (string)($item['product_name'] ?? '') === $productName) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $group['product_items'][] = [
                        'product_id' => $productId,
                        'product_name' => $productName,
                    ];
                }
            }
        }
        return $group;
    }

    /**
     * @param array $targetRows
     * @param string $metricKey
     * @param int $year
     * @param int $month
     * @return array|null
     */
    protected function buildProductGroupForMonth(array $targetRows, string $metricKey, int $year, int $month): ?array
    {
        $group = null;
        foreach ($targetRows as $row) {
            if ((int)($row['time_type'] ?? 1) !== 1) {
                continue;
            }
            if ((int)($row['year'] ?? 0) !== $year || (int)($row['month'] ?? 0) !== $month) {
                continue;
            }
            foreach ($this->productDao->getByTargetId((int)$row['id']) as $p) {
                $refKey = (string)($p['allocate_ref_key'] ?? '');
                if ($refKey === '') {
                    $refKey = 'product_' . (int)($p['product_id'] ?? 0) . '_' . (string)($p['metric_key'] ?? 'revenue');
                }
                if ($refKey !== $metricKey) {
                    continue;
                }
                if ($group === null) {
                    $group = [
                        'metric_key' => (string)($p['metric_key'] ?? 'revenue'),
                        'product_items' => [],
                    ];
                }
                $productId = (int)($p['product_id'] ?? 0);
                $productName = (string)($p['product_name'] ?? '');
                $exists = false;
                foreach ($group['product_items'] as $item) {
                    if ((int)($item['product_id'] ?? 0) === $productId
                        && (string)($item['product_name'] ?? '') === $productName) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $group['product_items'][] = [
                        'product_id' => $productId,
                        'product_name' => $productName,
                    ];
                }
            }
        }
        return $group;
    }

    /**
     * @param int $monthStart
     * @param int $monthEnd
     * @param int $now
     * @return array{status:string,text:string}
     */
    protected function resolveMonthlyItemStatus(int $monthStart, int $monthEnd, int $now = 0): array
    {
        $now = $now ?: time();
        if ($now < $monthStart) {
            return ['status' => 'not_started', 'text' => '未开始'];
        }
        if ($now > $monthEnd) {
            return ['status' => 'completed', 'text' => '已结束'];
        }
        return ['status' => 'pending', 'text' => '进行中'];
    }

    /**
     * @param array $storeIds
     * @param int $start
     * @param int $end
     * @param string $metricKey
     * @param array $targets
     * @return array
     */
    protected function buildStoreMetricRanking(
        array $storeIds,
        int $start,
        int $end,
        string $metricKey,
        array $targets = []
    ): array {
        $isProductMetric = strpos($metricKey, 'product_') === 0;
        $productGroup = $isProductMetric ? $this->buildProductGroupForPeriod($targets, $metricKey) : null;

        $targetByStore = [];
        foreach ($targets as $row) {
            $sid = (int)($row['store_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            if ($isProductMetric) {
                if (!isset($targetByStore[$sid])) {
                    $targetByStore[$sid] = 0;
                }
                $targetByStore[$sid] += (int)round($this->resolveMetricTargetFromTargetRow($row, $metricKey));
                continue;
            }
            $metrics = $this->mergeCoreMetricsWithSaved($this->metricDao->getByTargetId((int)$row['id']));
            foreach ($metrics as $m) {
                if (($m['metric_key'] ?? '') !== $metricKey) {
                    continue;
                }
                if (!isset($targetByStore[$sid])) {
                    $targetByStore[$sid] = 0;
                }
                $targetByStore[$sid] += (int)round((float)($m['target_value'] ?? 0));
            }
        }

        $yoyStart = strtotime('-1 year', $start);
        $yoyEnd = strtotime('-1 year', $end);
        $duration = max(1, $end - $start);
        $momEnd = $start - 1;
        $momStart = $momEnd - $duration;

        $list = [];
        foreach ($storeIds as $sid) {
            if ($isProductMetric && $productGroup) {
                $completed = (float)$this->calcProductCompleted($productGroup, [$sid], $start, $end);
                $yoyVal = (float)$this->calcProductCompleted($productGroup, [$sid], $yoyStart, $yoyEnd);
                $momVal = (float)$this->calcProductCompleted($productGroup, [$sid], $momStart, $momEnd);
            } else {
                $completed = (float)$this->calcMetricCompleted($metricKey, [$sid], $start, $end);
                $yoyVal = (float)$this->calcMetricCompleted($metricKey, [$sid], $yoyStart, $yoyEnd);
                $momVal = (float)$this->calcMetricCompleted($metricKey, [$sid], $momStart, $momEnd);
            }
            $targetVal = (float)($targetByStore[$sid] ?? 0);
            $name = (string)SystemStore::where('id', $sid)->value('name');
            $list[] = [
                'store_id' => $sid,
                'store_name' => $name,
                'name' => $name,
                'target_value' => $targetVal,
                'target' => $targetVal,
                'completed_value' => $completed,
                'completed' => $completed,
                'rate' => $targetVal > 0 ? round($completed / $targetVal * 100, 1) : 0,
                'yoy' => $this->calcChangeRate($completed, $yoyVal),
                'mom' => $this->calcChangeRate($completed, $momVal),
            ];
        }
        usort($list, function ($a, $b) {
            return ($b['completed_value'] ?? 0) <=> ($a['completed_value'] ?? 0);
        });
        return $list;
    }

    /**
     * 目标管理不参与分配/排行的员工（分成人员、合作方）
     * @return array
     */
    protected function getTargetExcludedStaffIds(): array
    {
        static $ids = null;
        if ($ids !== null) {
            return $ids;
        }
        $fencheng = SystemStoreStaff::where('is_fencheng', 1)->where('is_del', 0)->column('id');
        $hezuofang = SystemStoreStaff::where('is_hezuofang', 1)->where('is_del', 0)->column('id');
        $ids = array_values(array_unique(array_map('intval', array_merge($fencheng ?: [], $hezuofang ?: []))));
        return $ids;
    }

    /**
     * @param int $staffId
     * @return bool
     */
    protected function isTargetExcludedStaffId(int $staffId): bool
    {
        return $staffId > 0 && in_array($staffId, $this->getTargetExcludedStaffIds(), true);
    }

    /**
     * @param mixed $query
     * @return mixed
     */
    protected function applyTargetAssignableStaffScope($query)
    {
        return $query->where('is_fencheng', 0)->where('is_hezuofang', 0);
    }

    /**
     * 筛选时间范围内、门店相关目标的员工分配汇总（用于排行达成率）
     * @param array $storeIds
     * @param string $metricKey
     * @param int $start
     * @param int $end
     * @param int $preferTargetId
     * @return array{map:array,meta:array}
     */
    protected function buildStaffTargetMapForPeriod(
        array $storeIds,
        string $metricKey,
        int $start,
        int $end,
        int $preferTargetId = 0
    ): array {
        $map = [];
        $meta = [];
        $targetIds = [];
        if ($preferTargetId > 0) {
            $targetIds[$preferTargetId] = true;
        }
        foreach ($storeIds as $sid) {
            $sid = (int)$sid;
            if ($sid <= 0) {
                continue;
            }
            foreach ($this->dao->getList(['store_id' => $sid, 'status' => 1], 0, 0) as $row) {
                [$tStart, $tEnd] = $this->getTimeRange($row);
                if ($tEnd >= $start && $tStart <= $end) {
                    $targetIds[(int)($row['id'] ?? 0)] = true;
                }
            }
        }
        foreach (array_keys($targetIds) as $tid) {
            if ($tid <= 0) {
                continue;
            }
            foreach ($this->allocateDao->getByTargetAndRef($tid, $metricKey, 1) as $row) {
                $staffId = (int)($row['staff_id'] ?? 0);
                if ($staffId <= 0 || $this->isTargetExcludedStaffId($staffId)) {
                    continue;
                }
                $staff = SystemStoreStaff::where('id', $staffId)->where('is_del', 0)->find();
                if (!$staff) {
                    continue;
                }
                if ($storeIds && !in_array((int)$staff['store_id'], array_map('intval', $storeIds), true)) {
                    continue;
                }
                $map[$staffId] = ($map[$staffId] ?? 0) + (int)round((float)($row['allocate_value'] ?? 0));
                if (!isset($meta[$staffId])) {
                    $meta[$staffId] = [
                        'staff_name' => (string)$staff['staff_name'],
                        'store_id' => (int)$staff['store_id'],
                    ];
                }
            }
        }
        $this->applyStaffTargetFallback($map, $meta, $storeIds, array_keys($targetIds), $metricKey);
        return ['map' => $map, 'meta' => $meta];
    }

    /**
     * 汇总多个目标下某指标的目标值
     * @param array $targetIds
     * @param string $metricKey
     * @return float
     */
    protected function sumMetricTargetForTargets(array $targetIds, string $metricKey): float
    {
        $total = 0.0;
        $isProductMetric = strpos($metricKey, 'product_') === 0;
        foreach ($targetIds as $tid) {
            $tid = (int)$tid;
            if ($tid <= 0) {
                continue;
            }
            if ($isProductMetric) {
                foreach ($this->productDao->getByTargetId($tid) as $p) {
                    $ref = (string)($p['allocate_ref_key'] ?? '');
                    if ($ref === '') {
                        $ref = 'product_' . (int)($p['product_id'] ?? 0) . '_' . (string)($p['metric_key'] ?? 'revenue');
                    }
                    if ($ref === $metricKey) {
                        $total += (float)($p['target_value'] ?? 0);
                    }
                }
                continue;
            }
            foreach ($this->metricDao->getByTargetId($tid) as $m) {
                if ((string)($m['metric_key'] ?? '') === $metricKey) {
                    $total += (float)($m['target_value'] ?? 0);
                }
            }
        }
        return $total;
    }

    /**
     * @param array $storeIds
     * @return array<int,array{staff_name:string,store_id:int}>
     */
    protected function getAssignableStaffMapForStores(array $storeIds): array
    {
        $map = [];
        foreach ($storeIds as $sid) {
            $sid = (int)$sid;
            if ($sid <= 0) {
                continue;
            }
            $staffs = $this->applyTargetAssignableStaffScope(
                SystemStoreStaff::where('store_id', $sid)
                    ->where('status', 1)
                    ->where('is_del', 0)
            )
                ->field('id as staff_id,staff_name,store_id')
                ->select()
                ->toArray();
            foreach ($staffs ?: [] as $staff) {
                $staffId = (int)($staff['staff_id'] ?? 0);
                if ($staffId <= 0) {
                    continue;
                }
                $map[$staffId] = [
                    'staff_name' => (string)($staff['staff_name'] ?? ''),
                    'store_id' => (int)($staff['store_id'] ?? $sid),
                ];
            }
        }
        return $map;
    }

    /**
     * 未做员工分配时，将门店指标剩余目标均分给未分配员工
     * @param array $map
     * @param array $meta
     * @param array $storeIds
     * @param array $targetIds
     * @param string $metricKey
     */
    protected function applyStaffTargetFallback(
        array &$map,
        array &$meta,
        array $storeIds,
        array $targetIds,
        string $metricKey
    ): void {
        $targetIds = array_values(array_filter(array_map('intval', $targetIds)));
        if (!$targetIds) {
            return;
        }
        $storeMetricTarget = $this->sumMetricTargetForTargets($targetIds, $metricKey);
        if ($storeMetricTarget <= 0) {
            return;
        }
        $allocatedSum = 0;
        foreach ($map as $val) {
            $allocatedSum += (int)round((float)$val);
        }
        $remaining = max(0, (int)round($storeMetricTarget - $allocatedSum));
        if ($remaining <= 0) {
            return;
        }
        $staffMap = $this->getAssignableStaffMapForStores($storeIds);
        $withoutTarget = [];
        foreach ($staffMap as $staffId => $staffMeta) {
            if ((int)($map[$staffId] ?? 0) <= 0) {
                $withoutTarget[] = $staffId;
            }
        }
        if (!$withoutTarget) {
            return;
        }
        $per = (int)round($remaining / count($withoutTarget));
        if ($per <= 0) {
            $per = 1;
        }
        foreach ($withoutTarget as $staffId) {
            $map[$staffId] = ($map[$staffId] ?? 0) + $per;
            if (!isset($meta[$staffId])) {
                $meta[$staffId] = $staffMap[$staffId];
            }
        }
    }

    /**
     * @param array $rows
     * @return array
     */
    protected function filterTargetStaffRankingRows(array $rows): array
    {
        $exclude = array_flip($this->getTargetExcludedStaffIds());
        if (!$exclude) {
            return $rows;
        }
        return array_values(array_filter($rows, function ($row) use ($exclude) {
            $row = is_array($row) ? $row : (method_exists($row, 'toArray') ? $row->toArray() : []);
            $staffId = (int)($row['staff_id'] ?? 0);
            return $staffId > 0 && !isset($exclude[$staffId]);
        }));
    }

    /**
     * @param float $current
     * @param float $previous
     * @return float
     */
    protected function calcChangeRate(float $current, float $previous): float
    {
        if ($previous <= 0) {
            return 0;
        }
        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * @param string $metricKey
     * @param int $staffId
     * @param array $storeIds
     * @param int $start
     * @param int $end
     * @param StaffYejiDao $yejiDao
     * @return float
     */
    protected function calcStaffMetricValue(
        string $metricKey,
        int $staffId,
        array $storeIds,
        int $start,
        int $end,
        StaffYejiDao $yejiDao
    ): float {
        $timeStr = date('Y/m/d H:i:s', $start) . '-' . date('Y/m/d H:i:s', $end);
        $where = [
            'created_time' => $timeStr,
            'staff_id' => $staffId,
            'store_id' => count($storeIds) === 1 ? $storeIds[0] : $storeIds,
        ];
        if (in_array($metricKey, ['revenue', 'consume'], true)) {
            $where['sum_type'] = $metricKey === 'revenue' ? 1 : 2;
            $total = (float)$yejiDao->search($where)->sum('yeji');
            if ($metricKey === 'revenue') {
                $deduct = $this->sumCombinationOldCardStaffYejiDeduction($where);
                return max(0, (float)bcsub((string)$total, (string)$deduct, 2));
            }
            return $total;
        }
        if ($metricKey === 'point') {
            $rows = $yejiDao->dianke($where);
            foreach ($rows as $row) {
                $row = is_array($row) ? $row : (method_exists($row, 'toArray') ? $row->toArray() : []);
                if ((int)($row['staff_id'] ?? 0) === $staffId) {
                    return (float)($row['yeji'] ?? 0);
                }
            }
            return 0;
        }
        if ($metricKey === 'service') {
            $where['sum_type'] = 2;
            return (float)$yejiDao->search($where)->count();
        }
        return (float)$this->calcMetricCompleted($metricKey, $storeIds, $start, $end);
    }

    /**
     * 月度目标完成明细（矩阵表）
     * @param array $where
     * @param array $allowedStoreIds
     * @return array
     */
    public function monthlyDetail(array $where, array $allowedStoreIds = []): array
    {
        [$start, $end, $monthItems] = $this->parseMonthRange(
            (string)($where['start_month'] ?? ''),
            (string)($where['end_month'] ?? '')
        );
        $storeIds = $this->resolveStoreIdsByFilter(
            (int)($where['store_id'] ?? 0),
            (int)($where['object_type'] ?? 3),
            $allowedStoreIds
        );
        $filterKeys = $this->parseMetricKeysParam((string)($where['metric_keys'] ?? ''));
        $columns = $this->collectDetailMetricColumns($where, $storeIds, $start, $end, $filterKeys);
        $objectName = $this->resolveDetailObjectName($where, $storeIds);
        $periodLabel = $this->formatDetailPeriodLabel($start, $end, true);

        $dataRows = [];
        $maxTotal = 0;
        $maxTotalIdx = -1;
        foreach ($monthItems as $item) {
            $row = [
                'label' => (string)$item['label'],
                'row_type' => 'month',
                'values' => [],
                'total' => 0,
            ];
            foreach ($columns as $col) {
                $val = round($this->calcDetailMetricCompleted(
                    $col,
                    $storeIds,
                    (int)$item['start'],
                    (int)$item['end']
                ), 2);
                $row['values'][$col['key']] = $val;
                $row['total'] += $val;
            }
            $row['total'] = round($row['total'], 2);
            if ($row['total'] > $maxTotal) {
                $maxTotal = $row['total'];
                $maxTotalIdx = count($dataRows);
            }
            $dataRows[] = $row;
        }
        if ($maxTotalIdx >= 0 && $maxTotal > 0) {
            $dataRows[$maxTotalIdx]['highlight_total'] = true;
        }

        return [
            'header_info' => $objectName . ' | ' . $periodLabel,
            'object_name' => $objectName,
            'period_label' => $periodLabel,
            'columns' => $this->formatDetailColumns($columns),
            'target_row' => $this->buildMatrixTargetRow($columns),
            'rows' => $dataRows,
            'total_row' => $this->buildMatrixTotalRow($columns, $dataRows),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 门店目标完成明细（矩阵表）
     * @param array $where
     * @param array $allowedStoreIds
     * @return array
     */
    public function storeDetail(array $where, array $allowedStoreIds = []): array
    {
        [$start, $end] = $this->parseMonthRange(
            (string)($where['start_month'] ?? ''),
            (string)($where['end_month'] ?? '')
        );
        $storeIds = $this->resolveStoreIdsByFilter(
            (int)($where['store_id'] ?? 0),
            (int)($where['object_type'] ?? 3),
            $allowedStoreIds
        );
        if (!$storeIds) {
            $storeIds = array_map('intval', SystemStore::where('is_del', 0)->column('id'));
        }
        $filterKeys = $this->parseMetricKeysParam((string)($where['metric_keys'] ?? ''));
        $columns = $this->collectDetailMetricColumns($where, $storeIds, $start, $end, $filterKeys);
        $objectName = $this->resolveDetailObjectName($where, $storeIds);
        $periodLabel = $this->formatDetailPeriodLabel($start, $end, false);

        $dataRows = [];
        foreach ($storeIds as $sid) {
            $name = (string)SystemStore::where('id', $sid)->value('name');
            $row = [
                'label' => $name,
                'store_id' => $sid,
                'row_type' => 'store',
                'values' => [],
                'total' => 0,
            ];
            foreach ($columns as $col) {
                $val = round($this->calcDetailMetricCompleted($col, [$sid], $start, $end), 2);
                $row['values'][$col['key']] = $val;
                $row['total'] += $val;
            }
            $row['total'] = round($row['total'], 2);
            $dataRows[] = $row;
        }

        return [
            'header_info' => $periodLabel . ' | ' . $objectName,
            'object_name' => $objectName,
            'period_label' => $periodLabel,
            'columns' => $this->formatDetailColumns($columns),
            'target_row' => $this->buildMatrixTargetRow($columns),
            'rows' => $dataRows,
            'total_row' => $this->buildMatrixTotalRow($columns, $dataRows),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param string $raw
     * @return array
     */
    protected function parseMetricKeysParam(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @param array $where
     * @param array $storeIds
     * @param int $start
     * @param int $end
     * @param array $filterKeys
     * @return array
     */
    protected function collectDetailMetricColumns(
        array $where,
        array $storeIds,
        int $start,
        int $end,
        array $filterKeys = []
    ): array {
        $searchWhere = [
            'year' => (int)date('Y', $start),
            'status' => 1,
        ];
        if ($storeIds) {
            $searchWhere['store_ids'] = $storeIds;
        }
        $targets = $this->dao->getList($searchWhere, 0, 0);
        $columnMap = [];

        foreach ($targets as $target) {
            $tid = (int)$target['id'];
            $metrics = $this->mergeCoreMetricsWithSaved($this->metricDao->getByTargetId($tid));
            foreach ($metrics as $m) {
                $key = (string)($m['metric_key'] ?? '');
                if ($key === '' || ($filterKeys && !in_array($key, $filterKeys, true))) {
                    continue;
                }
                if (!isset($columnMap[$key])) {
                    $def = $this->findMetricDefinition($key);
                    $columnMap[$key] = [
                        'key' => $key,
                        'name' => (string)($m['metric_name'] ?? ($def['name'] ?? $key)),
                        'target_value' => 0,
                        'is_product' => false,
                        'product_group' => null,
                    ];
                }
                $columnMap[$key]['target_value'] += (float)($m['target_value'] ?? 0);
            }

            $products = $this->productDao->getByTargetId($tid);
            $allocMap = $this->groupAllocationsByRefKey($tid);
            $productRows = $this->buildProductDetailRows($products, $allocMap, $storeIds, $start, $end);
            foreach ($productRows as $p) {
                $key = (string)($p['allocate_ref_key'] ?? ('product_' . (int)($p['product_id'] ?? 0) . '_' . ($p['metric_key'] ?? 'revenue')));
                if ($filterKeys && !in_array($key, $filterKeys, true)) {
                    continue;
                }
                if (!isset($columnMap[$key])) {
                    $columnMap[$key] = [
                        'key' => $key,
                        'name' => $this->resolveProductDisplayNameFromRow($p),
                        'target_value' => 0,
                        'is_product' => true,
                        'product_group' => $p,
                    ];
                }
                $columnMap[$key]['target_value'] += (float)($p['target_value'] ?? 0);
                $columnMap[$key]['product_group'] = $p;
            }
        }

        if (!$columnMap && !$filterKeys) {
            foreach ($this->getMetricDefinitions() as $def) {
                $columnMap[$def['key']] = [
                    'key' => $def['key'],
                    'name' => $def['name'],
                    'target_value' => 0,
                    'is_product' => false,
                    'product_group' => null,
                ];
            }
        }

        return array_values($columnMap);
    }

    /**
     * @param array $columns
     * @return array
     */
    protected function formatDetailColumns(array $columns): array
    {
        return array_map(function ($col) {
            return [
                'key' => $col['key'],
                'name' => $col['name'],
            ];
        }, $columns);
    }

    /**
     * @param array $columns
     * @return array
     */
    protected function buildMatrixTargetRow(array $columns): array
    {
        $row = ['label' => '目标', 'row_type' => 'target', 'values' => [], 'total' => 0];
        foreach ($columns as $col) {
            $val = round((float)($col['target_value'] ?? 0), 2);
            $row['values'][$col['key']] = $val;
            $row['total'] += $val;
        }
        $row['total'] = round($row['total'], 2);
        return $row;
    }

    /**
     * @param array $columns
     * @param array $dataRows
     * @return array
     */
    protected function buildMatrixTotalRow(array $columns, array $dataRows): array
    {
        $row = ['label' => '合计', 'row_type' => 'total', 'values' => [], 'total' => 0];
        foreach ($columns as $col) {
            $sum = 0;
            foreach ($dataRows as $dr) {
                $sum += (float)($dr['values'][$col['key']] ?? 0);
            }
            $row['values'][$col['key']] = round($sum, 2);
            $row['total'] += $sum;
        }
        $row['total'] = round($row['total'], 2);
        return $row;
    }

    /**
     * 组合支付订单中旧卡录入分摊扣除（按 yeji 占 cash_pay_price 比例）
     * @param array $where
     * @param array $productIds
     * @return float
     */
    protected function sumCombinationOldCardStaffYejiDeduction(array $where, array $productIds = []): float
    {
        $query = Db::name('staff_yeji')->alias('sy')
            ->join('store_order o', 'o.id = sy.order_id')
            ->where('sy.status', 0)
            ->whereIn('sy.type', [1, 2])
            ->where('o.pay_type', 'combination')
            ->where('o.paid', 1)
            ->where('o.refund_status', 0)
            ->where('o.is_del', 0)
            ->where('o.is_system_del', 0)
            ->where('o.cash_pay_price', '>', 0);

        if (!empty($where['created_time'])) {
            $times = explode('-', (string)$where['created_time']);
            $times[1] = date('Y/m/d', strtotime($times[1])) . ' 23:59:59';
            $query->whereBetween('sy.created_time', $times);
        }
        if (!empty($where['staff_id'])) {
            if (is_array($where['staff_id'])) {
                $query->whereIn('sy.staff_id', $where['staff_id']);
            } else {
                $query->where('sy.staff_id', $where['staff_id']);
            }
        }
        if (!empty($where['store_id'])) {
            if (is_array($where['store_id'])) {
                $query->whereIn('sy.store_id', $where['store_id']);
            } else {
                $query->where('sy.store_id', $where['store_id']);
            }
        }
        if ($productIds) {
            $query->whereIn('sy.goods_id', $productIds);
        }

        $rows = $query->field('sy.order_id, MAX(o.cash_pay_price) as cash_pay_price, SUM(sy.yeji) as yeji_sum')
            ->group('sy.order_id')
            ->select()
            ->toArray();

        $deduct = 0.0;
        foreach ($rows as $row) {
            $orderId = (int)($row['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $oldCard = (float)Db::name('combination_order')
                ->where('order_id', $orderId)
                ->where('cash_choose', CashType::OLD_CARD_ENTRY)
                ->sum('price');
            if ($oldCard <= 0) {
                continue;
            }
            $cashPay = (float)($row['cash_pay_price'] ?? 0);
            $yejiSum = (float)($row['yeji_sum'] ?? 0);
            if ($cashPay <= 0 || $yejiSum <= 0) {
                continue;
            }
            $deduct += $yejiSum * min(1, $oldCard / $cashPay);
        }
        return $deduct;
    }

    /**
     * @param array $rows
     * @param array $where
     * @param array $productIds
     * @return array
     */
    protected function adjustStaffRankingExcludeCombinationOldCard(array $rows, array $where, array $productIds = []): array
    {
        if (!$rows) {
            return [];
        }
        $deductByStaff = [];
        $staffQuery = Db::name('staff_yeji')->alias('sy')
            ->join('store_order o', 'o.id = sy.order_id')
            ->where('sy.status', 0)
            ->whereIn('sy.type', [1, 2])
            ->where('o.pay_type', 'combination')
            ->where('o.paid', 1)
            ->where('o.refund_status', 0)
            ->where('o.is_del', 0)
            ->where('o.is_system_del', 0)
            ->where('o.cash_pay_price', '>', 0);
        if (!empty($where['created_time'])) {
            $times = explode('-', (string)$where['created_time']);
            $times[1] = date('Y/m/d', strtotime($times[1])) . ' 23:59:59';
            $staffQuery->whereBetween('sy.created_time', $times);
        }
        if (!empty($where['store_id'])) {
            if (is_array($where['store_id'])) {
                $staffQuery->whereIn('sy.store_id', $where['store_id']);
            } else {
                $staffQuery->where('sy.store_id', $where['store_id']);
            }
        }
        if ($productIds) {
            $staffQuery->whereIn('sy.goods_id', $productIds);
        }
        $orderRows = $staffQuery->field('sy.staff_id, sy.order_id, MAX(o.cash_pay_price) as cash_pay_price, SUM(sy.yeji) as yeji_sum')
            ->group('sy.staff_id, sy.order_id')
            ->select()
            ->toArray();
        foreach ($orderRows as $row) {
            $staffId = (int)($row['staff_id'] ?? 0);
            $orderId = (int)($row['order_id'] ?? 0);
            if ($staffId <= 0 || $orderId <= 0) {
                continue;
            }
            $oldCard = (float)Db::name('combination_order')
                ->where('order_id', $orderId)
                ->where('cash_choose', CashType::OLD_CARD_ENTRY)
                ->sum('price');
            if ($oldCard <= 0) {
                continue;
            }
            $cashPay = (float)($row['cash_pay_price'] ?? 0);
            $yejiSum = (float)($row['yeji_sum'] ?? 0);
            if ($cashPay <= 0 || $yejiSum <= 0) {
                continue;
            }
            $deduct = $yejiSum * min(1, $oldCard / $cashPay);
            $deductByStaff[$staffId] = ($deductByStaff[$staffId] ?? 0) + $deduct;
        }
        foreach ($rows as &$row) {
            $staffId = (int)($row['staff_id'] ?? 0);
            $deduct = (float)($deductByStaff[$staffId] ?? 0);
            $row['yeji'] = max(0, (float)bcsub((string)($row['yeji'] ?? 0), (string)$deduct, 2));
        }
        unset($row);
        usort($rows, function ($a, $b) {
            return ($b['yeji'] ?? 0) <=> ($a['yeji'] ?? 0);
        });
        return $rows;
    }

    /**
     * @param array $col
     * @param array $storeIds
     * @param int $start
     * @param int $end
     * @return float
     */
    protected function calcDetailMetricCompleted(array $col, array $storeIds, int $start, int $end): float
    {
        if (!empty($col['is_product']) && !empty($col['product_group'])) {
            return (float)$this->calcProductCompleted(
                $this->productGroupFromRow($col['product_group']),
                $storeIds,
                $start,
                $end
            );
        }
        return (float)$this->calcMetricCompleted((string)$col['key'], $storeIds, $start, $end);
    }

    /**
     * @param array $p
     * @return array
     */
    protected function productGroupFromRow(array $p): array
    {
        $items = $p['product_items'] ?? [];
        if (!$items && !empty($p['product_id'])) {
            $items = [[
                'product_id' => (int)$p['product_id'],
                'product_name' => (string)($p['product_name'] ?? ''),
            ]];
        }
        return [
            'metric_key' => (string)($p['metric_key'] ?? 'revenue'),
            'product_items' => $items,
        ];
    }

    /**
     * @param array $p
     * @return string
     */
    protected function resolveProductDisplayNameFromRow(array $p): string
    {
        $display = trim((string)($p['display_name'] ?? ''));
        if ($display !== '') {
            return $display;
        }
        $group = $this->productGroupFromRow($p);
        return $this->resolveProductDisplayName([
            'display_name' => '',
            'product_items' => $group['product_items'] ?? [],
        ]);
    }

    /**
     * @param array $where
     * @param array $storeIds
     * @return string
     */
    protected function resolveDetailObjectName(array $where, array $storeIds): string
    {
        $objectName = trim((string)($where['object_name'] ?? ''));
        if ($objectName !== '') {
            return $objectName;
        }
        if (count($storeIds) === 1) {
            return (string)SystemStore::where('id', $storeIds[0])->value('name');
        }
        return '全部门店';
    }

    /**
     * @param int $start
     * @param int $end
     * @param bool $annualStyle
     * @return string
     */
    protected function formatDetailPeriodLabel(int $start, int $end, bool $annualStyle): string
    {
        $sy = (int)date('Y', $start);
        $ey = (int)date('Y', $end);
        $sm = (int)date('n', $start);
        $em = (int)date('n', $end);
        if ($annualStyle && $sy === $ey && $sm === 1 && $em === 12) {
            return $sy . '年度';
        }
        if ($sy === $ey && $sm === $em) {
            return $sy . '年' . $sm . '月';
        }
        return date('Y.m', $start) . '-' . date('Y.m', $end);
    }
}
