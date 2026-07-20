<?php
namespace app\controller\admin\v1\store;

use app\controller\admin\AuthController;
use app\model\store\SystemStore;
use app\services\order\store\BranchOrderServices;
use app\services\organization\OrganizationScopeService;
use app\services\statistics\BusinessDashboardServices;

/**
 * 平台经营看板
 */
class BusinessDashboard extends AuthController
{
    /**
     * @return array{0:int[],1:int,2:int,3:string}
     */
    protected function resolveScope(): array
    {
        $params = $this->request->getMore([
            ['data', '', '', 'time'],
            ['org_id', 0],
            ['store_id', 0],
        ]);
        $orgId = (int)($params['org_id'] ?? 0);
        $storeId = (int)($params['store_id'] ?? 0);
        $timeStr = (string)($params['time'] ?? '');

        /** @var OrganizationScopeService $scope */
        $scope = app()->make(OrganizationScopeService::class);
        if ((int)$this->adminType === 3 && $this->agentId) {
            $allowed = $scope->getResolvedStoreIdsByLegacyAgentId((int)$this->agentId);
        } else {
            $allowed = SystemStore::where('is_del', 0)
                ->where('name', '<>', '总部')
                ->column('id') ?: [];
            $allowed = array_values(array_filter(array_map('intval', $allowed)));
        }
        if ($orgId <= 0 && $storeId <= 0) {
            $orgId = $scope->resolveGroupRootOrgId();
        }
        $storeIds = $scope->resolveDashboardStoreIds($orgId, $storeId, $allowed);

        /** @var BranchOrderServices $orderServices */
        $orderServices = app()->make(BranchOrderServices::class);
        if ($timeStr === '') {
            $timeStr = date('Y/m/01') . '-' . date('Y/m/d');
        }
        [$start, $end] = $orderServices->timeHandle($timeStr);
        return [$storeIds, (int)$start, (int)$end, $timeStr];
    }

    public function overview(BusinessDashboardServices $services)
    {
        [$storeIds, $start, $end, $timeStr] = $this->resolveScope();
        if (!$storeIds) {
            return app('json')->fail('无权限或当前范围无门店');
        }
        return app('json')->success($services->overview($storeIds, $start, $end, $timeStr));
    }

    public function trend(BusinessDashboardServices $services)
    {
        [$storeIds, , ,] = $this->resolveScope();
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
        if (!$storeIds) {
            return app('json')->fail('无权限或当前范围无门店');
        }
        return app('json')->success($services->trend($storeIds, $metric, (int)$start, (int)$end));
    }

    public function storeRanking(BusinessDashboardServices $services)
    {
        [$storeIds, $start, $end, $timeStr] = $this->resolveScope();
        $params = $this->request->getMore([
            ['sort_by', 'cash_performance'],
            ['sort_order', 'desc'],
        ]);
        if (!$storeIds) {
            return app('json')->fail('无权限或当前范围无门店');
        }
        return app('json')->success($services->storeRanking(
            $storeIds,
            $start,
            $end,
            $timeStr,
            (string)($params['sort_by'] ?? 'cash_performance'),
            (string)($params['sort_order'] ?? 'desc')
        ));
    }

    public function reservationDetail(BusinessDashboardServices $services)
    {
        [$storeIds, $start, $end] = $this->resolveScope();
        $params = $this->request->getMore([
            ['page', 1],
            ['limit', 20],
        ]);
        if (!$storeIds) {
            return app('json')->fail('无权限或当前范围无门店');
        }
        return app('json')->success($services->reservationDetail(
            $storeIds,
            $start,
            $end,
            (int)($params['page'] ?? 1),
            (int)($params['limit'] ?? 20)
        ));
    }

    public function newProfileDetail(BusinessDashboardServices $services)
    {
        [$storeIds, $start, $end] = $this->resolveScope();
        $params = $this->request->getMore([
            ['page', 1],
            ['limit', 20],
        ]);
        if (!$storeIds) {
            return app('json')->fail('无权限或当前范围无门店');
        }
        return app('json')->success($services->newProfileDetail(
            $storeIds,
            $start,
            $end,
            (int)($params['page'] ?? 1),
            (int)($params['limit'] ?? 20)
        ));
    }

    public function sourceCustomerDetail(BusinessDashboardServices $services)
    {
        [$storeIds, $start, $end] = $this->resolveScope();
        $params = $this->request->getMore([
            ['metric', 'casual_customer_count'],
            ['page', 1],
            ['limit', 20],
        ]);
        if (!$storeIds) {
            return app('json')->fail('无权限或当前范围无门店');
        }
        $metric = (string)($params['metric'] ?? 'casual_customer_count');
        $isNew = $metric === 'new_customer_count' ? 1 : 0;
        return app('json')->success($services->sourceCustomerDetail(
            $storeIds,
            $isNew,
            $start,
            $end,
            (int)($params['page'] ?? 1),
            (int)($params['limit'] ?? 20)
        ));
    }

    public function moneyDetail(BusinessDashboardServices $services)
    {
        [$storeIds, $start, $end, $timeStr] = $this->resolveScope();
        $params = $this->request->getMore([
            ['metric', 'cash_performance'],
            ['page', 1],
            ['limit', 20],
        ]);
        if (!$storeIds) {
            return app('json')->fail('无权限或当前范围无门店');
        }
        return app('json')->success($services->moneyMetricDetail(
            $storeIds,
            (string)($params['metric'] ?? 'cash_performance'),
            $start,
            $end,
            $timeStr,
            (int)($params['page'] ?? 1),
            (int)($params['limit'] ?? 20)
        ));
    }
}
