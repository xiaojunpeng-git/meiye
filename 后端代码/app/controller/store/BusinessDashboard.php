<?php
namespace app\controller\store;

use app\services\order\store\BranchOrderServices;
use app\services\statistics\BusinessDashboardServices;

/**
 * 门店经营看板
 */
class BusinessDashboard extends AuthController
{
    /**
     * @return array{0:int,1:int,2:int,3:string}
     */
    protected function resolveScope(): array
    {
        $params = $this->request->getMore([
            ['data', '', '', 'time'],
        ]);
        $timeStr = (string)($params['time'] ?? '');
        /** @var BranchOrderServices $orderServices */
        $orderServices = app()->make(BranchOrderServices::class);
        if ($timeStr === '') {
            $timeStr = date('Y/m/d') . '-' . date('Y/m/d');
        }
        [$start, $end] = $orderServices->timeHandle($timeStr);
        return [(int)$this->storeId, (int)$start, (int)$end, $timeStr];
    }

    public function overview(BusinessDashboardServices $services)
    {
        [$storeId, $start, $end, $timeStr] = $this->resolveScope();
        if ($storeId <= 0) {
            return app('json')->fail('门店未登录');
        }
        return app('json')->success($services->overview([$storeId], $start, $end, $timeStr));
    }

    public function trend(BusinessDashboardServices $services)
    {
        [$storeId] = $this->resolveScope();
        $params = $this->request->getMore([
            ['metric', 'cash_performance'],
            ['trend_data', ''],
        ]);
        $metric = (string)($params['metric'] ?? 'cash_performance');
        $trendData = (string)($params['trend_data'] ?? '');
        /** @var BranchOrderServices $orderServices */
        $orderServices = app()->make(BranchOrderServices::class);
        if ($trendData !== '') {
            [$start, $end] = $orderServices->timeHandle($trendData);
        } else {
            $end = strtotime(date('Y-m-d 23:59:59'));
            $start = strtotime(date('Y-m-d 00:00:00', strtotime('-29 day')));
        }
        if ($storeId <= 0) {
            return app('json')->fail('门店未登录');
        }
        return app('json')->success($services->trend([$storeId], $metric, (int)$start, (int)$end));
    }

    public function staffRanking(BusinessDashboardServices $services)
    {
        [$storeId, $start, $end, $timeStr] = $this->resolveScope();
        $params = $this->request->getMore([
            ['sort_by', 'cash_performance'],
            ['sort_order', 'desc'],
        ]);
        if ($storeId <= 0) {
            return app('json')->fail('门店未登录');
        }
        return app('json')->success($services->staffRanking(
            $storeId,
            $start,
            $end,
            $timeStr,
            (string)($params['sort_by'] ?? 'cash_performance'),
            (string)($params['sort_order'] ?? 'desc')
        ));
    }

    public function reservationDetail(BusinessDashboardServices $services)
    {
        [$storeId, $start, $end] = $this->resolveScope();
        $params = $this->request->getMore([
            ['page', 1],
            ['limit', 20],
        ]);
        if ($storeId <= 0) {
            return app('json')->fail('门店未登录');
        }
        return app('json')->success($services->reservationDetail(
            [$storeId],
            $start,
            $end,
            (int)($params['page'] ?? 1),
            (int)($params['limit'] ?? 20)
        ));
    }

    public function newProfileDetail(BusinessDashboardServices $services)
    {
        [$storeId, $start, $end] = $this->resolveScope();
        $params = $this->request->getMore([
            ['page', 1],
            ['limit', 20],
        ]);
        if ($storeId <= 0) {
            return app('json')->fail('门店未登录');
        }
        return app('json')->success($services->newProfileDetail(
            [$storeId],
            $start,
            $end,
            (int)($params['page'] ?? 1),
            (int)($params['limit'] ?? 20)
        ));
    }

    public function sourceCustomerDetail(BusinessDashboardServices $services)
    {
        [$storeId, $start, $end] = $this->resolveScope();
        $params = $this->request->getMore([
            ['metric', 'casual_customer_count'],
            ['page', 1],
            ['limit', 20],
        ]);
        if ($storeId <= 0) {
            return app('json')->fail('门店未登录');
        }
        $metric = (string)($params['metric'] ?? 'casual_customer_count');
        $isNew = $metric === 'new_customer_count' ? 1 : 0;
        return app('json')->success($services->sourceCustomerDetail(
            [$storeId],
            $isNew,
            $start,
            $end,
            (int)($params['page'] ?? 1),
            (int)($params['limit'] ?? 20)
        ));
    }

    public function moneyDetail(BusinessDashboardServices $services)
    {
        [$storeId, $start, $end, $timeStr] = $this->resolveScope();
        $params = $this->request->getMore([
            ['metric', 'cash_performance'],
            ['page', 1],
            ['limit', 20],
        ]);
        if ($storeId <= 0) {
            return app('json')->fail('门店未登录');
        }
        return app('json')->success($services->moneyMetricDetail(
            [$storeId],
            (string)($params['metric'] ?? 'cash_performance'),
            $start,
            $end,
            $timeStr,
            (int)($params['page'] ?? 1),
            (int)($params['limit'] ?? 20)
        ));
    }
}
