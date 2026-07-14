<?php

namespace app\controller\admin\v1\target;

use app\controller\admin\AuthController;
use app\services\target\StoreTargetServices;
use think\facade\App;

/**
 * 总后台-门店目标
 */
class StoreTarget extends AuthController
{
    /**
     * @var StoreTargetServices
     */
    protected $services;

    public function __construct(App $app, StoreTargetServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 目标列表（汇总用）
     */
    public function list()
    {
        $where = $this->request->getMore([
            ['year', ''],
            ['store_id', ''],
            ['object_type', ''],
            ['page', 1],
            ['limit', 100],
        ]);
        return $this->success($this->services->list($where, []));
    }

    /**
     * 目标数据分析
     */
    public function analysis()
    {
        $where = $this->request->getMore([
            ['target_id', 0],
            ['id', 0],
            ['year', ''],
            ['store_id', ''],
            ['object_type', 3],
            ['start_month', ''],
            ['end_month', ''],
            ['metric_key', 'revenue'],
        ]);
        return $this->success($this->services->analysis($where, []));
    }

    /**
     * 门店/员工排行（分页）
     */
    public function ranking()
    {
        $where = $this->request->getMore([
            ['target_id', 0],
            ['metric_key', 'revenue'],
            ['sort_type', 'rate'],
            ['asc', 0],
            ['store_id', ''],
            ['object_type', 3],
            ['start_month', ''],
            ['end_month', ''],
            ['rank_type', 'employee'],
            ['page', 1],
            ['limit', 10],
        ]);
        $page = max(1, (int)($where['page'] ?? 1));
        $limit = max(1, min(100, (int)($where['limit'] ?? 10)));
        $rankType = (string)($where['rank_type'] ?? 'employee');

        if ($rankType === 'store') {
            if (empty($where['start_month']) || empty($where['end_month'])) {
                return $this->success($this->services->paginateRankingList([], $page, $limit));
            }
            return $this->success($this->services->storeRankingByPeriod($where, []));
        }

        $targetId = (int)($where['target_id'] ?? 0);
        $hasPeriod = !empty($where['start_month']) && !empty($where['end_month']);
        if ($hasPeriod) {
            return $this->success($this->services->employeeRankingByPeriod($where, []));
        }
        if (!$targetId) {
            return $this->success($this->services->paginateRankingList([], $page, $limit));
        }

        $list = $this->services->employeeRanking(
            $targetId,
            (string)($where['metric_key'] ?? 'revenue'),
            (string)($where['sort_type'] ?? 'rate'),
            (bool)(int)($where['asc'] ?? 0),
            []
        );

        return $this->success($this->services->paginateRankingList($list, $page, $limit));
    }

    /**
     * 门店/对象选项
     */
    public function storeOptions()
    {
        return $this->success($this->services->storeOptions(0, []));
    }

    /**
     * 指标选项
     */
    public function metricOptions()
    {
        return $this->success([
            'core_metrics' => $this->services->getMetricDefinitions(),
            'product_metric_types' => $this->services->getProductMetricTypes(),
        ]);
    }

    /**
     * 月度目标完成明细
     */
    public function monthlyDetail()
    {
        $where = $this->request->getMore([
            ['start_month', ''],
            ['end_month', ''],
            ['store_id', ''],
            ['object_type', 3],
            ['object_name', ''],
            ['metric_keys', ''],
        ]);
        return $this->success($this->services->monthlyDetail($where, []));
    }

    /**
     * 门店目标完成明细
     */
    public function storeDetail()
    {
        $where = $this->request->getMore([
            ['start_month', ''],
            ['end_month', ''],
            ['store_id', ''],
            ['object_type', 3],
            ['object_name', ''],
            ['metric_keys', ''],
        ]);
        return $this->success($this->services->storeDetail($where, []));
    }
}
