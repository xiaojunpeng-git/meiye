<?php
namespace app\controller\api\target;

use app\Request;
use app\services\agent\SystemRegionAgentServices;
use app\services\store\SystemStoreStaffServices;
use app\services\target\StoreTargetServices;
use think\exception\ValidateException;

/**
 * 门店目标管理
 * Class StoreTarget
 * @package app\controller\api\target
 */
class StoreTarget
{
    /**
     * @var SystemStoreStaffServices
     */
    protected $staffServices;

    /**
     * @var StoreTargetServices
     */
    protected $targetServices;

    /**
     * @var int
     */
    protected $uid;

    /**
     * @var array
     */
    protected $staffInfo;

    /**
     * @var int
     */
    protected $store_id;

    /**
     * @var int
     */
    protected $staff_id;

    /**
     * 区域管理员（无需绑定门店店员）
     * @var bool
     */
    protected $isRegionAgent = false;

    /**
     * @var int
     */
    protected $agentId = 0;

    /**
     * 区域下可管理的门店 ID
     * @var array
     */
    protected $regionStoreIds = [];

    /**
     * @param SystemStoreStaffServices $staffServices
     * @param StoreTargetServices $targetServices
     * @param Request $request
     */
    public function __construct(
        SystemStoreStaffServices $staffServices,
        StoreTargetServices $targetServices,
        Request $request
    ) {
        $this->staffServices = $staffServices;
        $this->targetServices = $targetServices;
        $this->uid = (int)$request->uid();
        try {
            $this->staffInfo = $staffServices->getStaffInfoByUid($this->uid);
            if (!empty($this->staffInfo)) {
                $this->staffInfo = $this->staffInfo->toArray();
            } else {
                $this->staffInfo = [];
            }
        } catch (\Throwable $e) {
            $this->staffInfo = [];
        }
        $this->store_id = (int)($this->staffInfo['store_id'] ?? 0);
        $this->staff_id = (int)($this->staffInfo['id'] ?? 0);

        // 区域管理人员：无论是否绑定门店店员，都读取管辖门店（eb_system_region_agent_store）
        if ($this->uid > 0) {
            /** @var SystemRegionAgentServices $regionServices */
            $regionServices = app()->make(SystemRegionAgentServices::class);
            $agentInfo = $regionServices->getRegionAgentByUid($this->uid);
            if ($agentInfo) {
                $this->isRegionAgent = true;
                $this->agentId = (int)($agentInfo['id'] ?? 0);
                $this->regionStoreIds = $regionServices->getRegionAgentStoreId($this->agentId) ?: [];
                if (!$this->regionStoreIds) {
                    /** @var \app\services\store\SystemRegionManageServices $manageServices */
                    $manageServices = app()->make(\app\services\store\SystemRegionManageServices::class);
                    $manageId = $manageServices->findManageRegionIdByAgentId($this->agentId);
                    if ($manageId) {
                        $this->regionStoreIds = $manageServices->getStoreIdsByManageRegion($manageId, true);
                    }
                }
                $this->regionStoreIds = array_values(array_filter(array_map('intval', $this->regionStoreIds)));
            }
        }

        if (!$this->store_id && $this->regionStoreIds) {
            $this->store_id = (int)$this->regionStoreIds[0];
        }
    }

    /**
     * 店长或区域管理员
     */
    protected function checkAccess(): void
    {
        if ($this->isRegionAgent && $this->regionStoreIds) {
            return;
        }
        if ($this->store_id > 0 && (int)($this->staffInfo['is_manager'] ?? 0) === 1) {
            return;
        }
        throw new ValidateException('暂无目标管理权限');
    }

    /**
     * 解析并校验门店 ID
     * @param int|string $requestStoreId
     * @return int
     */
    protected function resolveStoreId($requestStoreId = 0): int
    {
        $storeId = (int)$requestStoreId;
        if ($storeId > 0) {
            $this->assertStoreAllowed($storeId);
            return $storeId;
        }
        if ($this->store_id > 0) {
            return $this->store_id;
        }
        if ($this->regionStoreIds) {
            return (int)$this->regionStoreIds[0];
        }
        return 0;
    }

    /**
     * 目标详情/复制/删除：仅请求显式传 store_id 时才锁定门店；区域管理员未传时按管辖门店校验
     * @param int|string $requestStoreId
     * @return int
     */
    protected function resolveTargetAccessStoreId($requestStoreId = 0): int
    {
        $storeId = (int)$requestStoreId;
        if ($storeId > 0) {
            return $this->resolveStoreId($storeId);
        }
        if ($this->isRegionAgent && $this->regionStoreIds) {
            return 0;
        }
        return $this->store_id > 0 ? $this->store_id : 0;
    }

    /**
     * @param int $storeId
     */
    protected function assertStoreAllowed(int $storeId): void
    {
        if ($storeId <= 0) {
            return;
        }
        if ($this->isRegionAgent && $this->regionStoreIds) {
            if (!in_array($storeId, $this->regionStoreIds, true)) {
                throw new ValidateException('无权查看该门店');
            }
            return;
        }
        if ($this->store_id > 0 && $storeId !== $this->store_id) {
            throw new ValidateException('无权查看该门店');
        }
    }

    /**
     * 解析 store_ids 参数（逗号分隔字符串或数组）
     * @param mixed $storeIds
     * @return array
     */
    protected function parseStoreIdsParam($storeIds): array
    {
        if ($storeIds === '' || $storeIds === null) {
            return [];
        }
        if (is_array($storeIds)) {
            return array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        }
        return array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$storeIds)))));
    }

    /**
     * 列表/分析筛选条件
     * @param array $where
     * @return array
     */
    protected function buildListWhere(array $where): array
    {
        $parsedIds = $this->parseStoreIdsParam($where['store_ids'] ?? '');
        if ($parsedIds) {
            if ($this->isRegionAgent && $this->regionStoreIds) {
                $parsedIds = array_values(array_intersect($parsedIds, $this->regionStoreIds));
            } elseif ($this->store_id > 0) {
                $parsedIds = array_values(array_intersect($parsedIds, [$this->store_id]));
            }
            if ($parsedIds) {
                if (count($parsedIds) === 1) {
                    $where['store_id'] = (int)$parsedIds[0];
                    unset($where['store_ids']);
                } else {
                    $where['store_ids'] = $parsedIds;
                    unset($where['store_id']);
                }
                unset($where['object_type'], $where['manage_region_id']);
                return $where;
            }
        }

        $reqStoreId = $where['store_id'] ?? '';
        if ($reqStoreId !== '' && (int)$reqStoreId > 0) {
            $where['store_id'] = $this->resolveStoreId($reqStoreId);
        } elseif ($this->isRegionAgent && $this->regionStoreIds) {
            $where['store_ids'] = $this->regionStoreIds;
            unset($where['store_id']);
        } elseif ($this->store_id > 0) {
            $where['store_id'] = $this->store_id;
        }
        return $where;
    }

    /**
     * 目标列表
     * @param Request $request
     * @return mixed
     */
    public function list(Request $request)
    {
        $this->checkAccess();
        $where = $request->getMore([
            ['year', ''],
            ['store_id', ''],
            ['store_ids', ''],
            ['object_type', ''],
            ['manage_region_id', ''],
            ['keyword', ''],
        ]);
        $where = $this->buildListWhere($where);
        return app('json')->success($this->targetServices->list($where, $this->regionStoreIds));
    }

    /**
     * 目标详情
     * @param Request $request
     * @param int $id
     * @return mixed
     */
    public function detail(Request $request, int $id)
    {
        $this->checkAccess();
        [$storeId] = $request->getMore([['store_id', 0]], true);
        $storeId = $this->resolveTargetAccessStoreId($storeId);
        return app('json')->success($this->targetServices->detail($id, $storeId, $this->regionStoreIds));
    }

    /**
     * 保存目标
     * @param Request $request
     * @return mixed
     */
    public function save(Request $request)
    {
        $this->checkAccess();
        $data = $request->postMore([
            ['id', 0],
            ['store_id', 0],
            ['name', ''],
            ['object_type', 1],
            ['object_name', ''],
            ['time_type', 1],
            ['year', date('Y')],
            ['month', 0],
            ['period_start', 0],
            ['period_end', 0],
            ['status', 1],
            ['metrics', []],
            ['products', []],
        ]);
        $storeId = $this->resolveStoreId($data['store_id'] ?? 0);
        unset($data['store_id']);
        return app('json')->success($this->targetServices->save($data, $storeId, $this->regionStoreIds));
    }

    /**
     * 复制目标
     * @param int $id
     * @param Request $request
     * @return mixed
     */
    public function copy(int $id, Request $request)
    {
        $this->checkAccess();
        [$storeId] = $request->getMore([['store_id', 0]], true);
        $storeId = $this->resolveTargetAccessStoreId($storeId);
        return app('json')->success($this->targetServices->copy($id, $storeId, $this->regionStoreIds));
    }

    /**
     * 删除目标
     * @param int $id
     * @param Request $request
     * @return mixed
     */
    public function delete(int $id, Request $request)
    {
        $this->checkAccess();
        [$storeId] = $request->getMore([['store_id', 0]], true);
        $storeId = $this->resolveTargetAccessStoreId($storeId);
        $this->targetServices->delete($id, $storeId, $this->regionStoreIds);
        return app('json')->success('删除成功');
    }

    /**
     * 目标分析
     * @param Request $request
     * @return mixed
     */
    public function analysis(Request $request)
    {
        $this->checkAccess();
        $where = $request->getMore([
            ['target_id', 0],
            ['id', 0],
            ['year', ''],
            ['store_id', ''],
            ['store_ids', ''],
            ['object_type', ''],
            ['manage_region_id', ''],
            ['start_month', ''],
            ['end_month', ''],
            ['metric_key', 'revenue'],
        ]);
        if (!empty($where['store_ids'])) {
            $where = $this->buildListWhere($where);
        } elseif (!empty($where['store_id'])) {
            $where['store_id'] = $this->resolveStoreId($where['store_id']);
        } elseif (empty($where['target_id']) && empty($where['id'])) {
            $where = $this->buildListWhere($where);
        } elseif ($this->store_id > 0 && empty($where['store_id'])) {
            $where['store_id'] = $this->store_id;
        }
        return app('json')->success($this->targetServices->analysis($where, $this->regionStoreIds));
    }

    /**
     * 指标选项
     * @return mixed
     */
    public function metricOptions()
    {
        return app('json')->success([
            'core_metrics' => $this->targetServices->getMetricDefinitions(),
            'product_metric_types' => $this->targetServices->getProductMetricTypes(),
        ]);
    }

    /**
     * 门店选项
     * @param Request $request
     * @return mixed
     */
    public function storeOptions(Request $request)
    {
        $this->checkAccess();
        return app('json')->success($this->targetServices->storeOptions(
            $this->store_id,
            $this->regionStoreIds
        ));
    }

    /**
     * 目标对象树（集团-区域-门店）
     * @param Request $request
     * @return mixed
     */
    public function storeOptionsTree(Request $request)
    {
        $this->checkAccess();
        return app('json')->success($this->targetServices->storeOptionsTree(
            $this->store_id,
            $this->regionStoreIds
        ));
    }

    /**
     * 商品搜索
     * @param Request $request
     * @return mixed
     */
    public function productSearch(Request $request)
    {
        $this->checkAccess();
        [$keyword, $storeId] = $request->getMore([
            ['keyword', ''],
            ['store_id', 0],
        ], true);
        $storeId = $this->resolveStoreId($storeId);
        return app('json')->success($this->targetServices->productSearch((string)$keyword, $storeId));
    }

    /**
     * 目标设置-商品选择树
     * @param Request $request
     * @return mixed
     */
    public function productSelect(Request $request)
    {
        $this->checkAccess();
        [$itemType, $keyword, $storeId, $scope, $categoryId, $page, $limit] = $request->getMore([
            ['item_type', 'project'],
            ['keyword', ''],
            ['store_id', 0],
            ['scope', 'tree'],
            ['category_id', 0],
            ['page', 1],
            ['limit', 20],
        ], true);
        $storeId = $this->resolveStoreId($storeId);
        $itemType = (string)$itemType;
        $keyword = (string)$keyword;
        $scope = (string)$scope;
        if ($scope === 'categories') {
            return app('json')->success($this->targetServices->productSelectCategories(
                $itemType,
                $storeId,
                $keyword
            ));
        }
        if ($scope === 'products') {
            return app('json')->success($this->targetServices->productSelectProducts(
                $itemType,
                $storeId,
                (int)$categoryId,
                $keyword,
                (int)$page,
                (int)$limit
            ));
        }
        return app('json')->success($this->targetServices->productSelectTree(
            $itemType,
            $storeId,
            $keyword
        ));
    }

    /**
     * 门店/员工排行（支持按时间区间或单目标）
     * @param Request $request
     * @return mixed
     */
    public function ranking(Request $request)
    {
        $this->checkAccess();
        $where = $request->getMore([
            ['target_id', 0],
            ['metric_key', 'revenue'],
            ['sort_type', 'value'],
            ['asc', 0],
            ['store_id', ''],
            ['store_ids', ''],
            ['object_type', 3],
            ['manage_region_id', ''],
            ['start_month', ''],
            ['end_month', ''],
            ['rank_type', 'employee'],
            ['page', 1],
            ['limit', 10],
        ]);
        $page = max(1, (int)($where['page'] ?? 1));
        $limit = max(1, min(100, (int)($where['limit'] ?? 10)));
        $rankType = (string)($where['rank_type'] ?? 'employee');

        if (!empty($where['store_ids'])) {
            $where = $this->buildListWhere($where);
        } elseif (!empty($where['store_id']) && (int)$where['store_id'] > 0) {
            $where['store_id'] = $this->resolveStoreId($where['store_id']);
        } else {
            $where = $this->buildListWhere($where);
        }

        if ($rankType === 'store') {
            if (empty($where['start_month']) || empty($where['end_month'])) {
                return app('json')->success($this->targetServices->paginateRankingList([], $page, $limit));
            }
            return app('json')->success($this->targetServices->storeRankingByPeriod($where, $this->regionStoreIds));
        }

        $targetId = (int)($where['target_id'] ?? 0);
        $hasPeriod = !empty($where['start_month']) && !empty($where['end_month']);
        if ($hasPeriod) {
            return app('json')->success($this->targetServices->employeeRankingByPeriod($where, $this->regionStoreIds));
        }
        if (!$targetId) {
            throw new ValidateException('请选择目标或时间范围');
        }

        $list = $this->targetServices->employeeRanking(
            $targetId,
            (string)($where['metric_key'] ?? 'revenue'),
            (string)($where['sort_type'] ?? 'value'),
            (bool)(int)($where['asc'] ?? 0),
            $this->regionStoreIds
        );

        return app('json')->success($this->targetServices->paginateRankingList($list, $page, $limit));
    }

    /**
     * 分配页数据
     * @param Request $request
     * @return mixed
     */
    public function allocateInfo(Request $request)
    {
        $this->checkAccess();
        $params = $request->getMore([
            ['store_id', 0],
            ['target_id', 0],
            ['ref_key', ''],
            ['allocate_type', 1],
            ['target_value', 0],
            ['metric_name', ''],
            ['unit', ''],
        ]);
        $params['store_id'] = $this->resolveStoreId($params['store_id'] ?? 0);
        return app('json')->success($this->targetServices->allocateInfo(
            $params,
            $this->isRegionAgent ? $this->regionStoreIds : []
        ));
    }

    /**
     * 保存分配
     * @param Request $request
     * @return mixed
     */
    public function allocateSave(Request $request)
    {
        $this->checkAccess();
        $data = $request->postMore([
            ['target_id', 0],
            ['store_id', 0],
            ['ref_key', ''],
            ['allocate_type', 1],
            ['target_value', 0],
            ['metric_name', ''],
            ['unit', ''],
            ['items', []],
        ]);
        $storeId = $this->resolveStoreId($data['store_id'] ?? 0);
        unset($data['store_id']);
        return app('json')->success($this->targetServices->saveAllocate(
            $data,
            $storeId,
            $this->isRegionAgent ? $this->regionStoreIds : []
        ));
    }
}
