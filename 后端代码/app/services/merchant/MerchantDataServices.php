<?php
namespace app\services\merchant;

use app\services\BaseServices;
use app\services\order\agent\AgentOrderServices;
use app\services\store\SystemStoreStaffServices;
use app\services\yeji\SatffYejiServices;

/**
 * 商家数仓概览（统一 scope_store_ids；普通员工用个人业绩页口径，禁止整店经营聚合）
 */
class MerchantDataServices extends BaseServices
{
    public function businessOverview(array $access, array $filter): array
    {
        /** @var MerchantAccessServices $accessServices */
        $accessServices = app()->make(MerchantAccessServices::class);
        $scopeStoreIds = $accessServices->requireScopeStoreIds($access);
        $role = (string)($access['active_role'] ?? '');
        $storeId = (int)($access['active_store_id'] ?? 0);
        $uid = (int)($access['uid'] ?? 0);
        $start = (string)($filter['start_date'] ?? date('Y-m-d'));
        $end = (string)($filter['end_date'] ?? date('Y-m-d'));
        $time = str_replace('-', '/', $start) . '-' . str_replace('-', '/', $end);

        $primary = [];
        $primaryDeveloping = false;
        $note = '';
        $metricsMode = 'store';

        $canStoreMetrics = $accessServices->canAggregateStoreMetrics($access) && !empty($scopeStoreIds);
        $isStaffSelfOnly = $role === 'store_staff'
            || (!$canStoreMetrics && in_array('merchant.data.self', $access['permissions'] ?? [], true));

        /** @var MerchantMetricPresenter $presenter */
        $presenter = app()->make(MerchantMetricPresenter::class);

        if ($isStaffSelfOnly) {
            $metricsMode = 'staff_self';
            $staffId = 0;
            if ($uid > 0 && $storeId > 0 && in_array($storeId, $scopeStoreIds, true)) {
                try {
                    /** @var SystemStoreStaffServices $staffServices */
                    $staffServices = app()->make(SystemStoreStaffServices::class);
                    $staffRow = $staffServices->getStaffInfoByUid($uid, $storeId);
                    $staff = $staffRow ? (is_array($staffRow) ? $staffRow : $staffRow->toArray()) : null;
                    $staffId = (int)($staff['id'] ?? 0);
                } catch (\Throwable $e) {
                    $staffId = 0;
                }
            }
            if ($staffId <= 0) {
                $primaryDeveloping = true;
                $note = '未找到当前门店员工档案，无法加载个人业绩';
                $primary = [];
            } else {
                try {
                    /** @var SatffYejiServices $yejiServices */
                    $yejiServices = app()->make(SatffYejiServices::class);
                    $yejiInfo = $yejiServices->staffInfo([
                        'staff_id' => $staffId,
                        'created_time' => $time,
                        'sum_type' => 1,
                    ]);
                    $primary = $presenter->presentStaffYejiInfo(is_array($yejiInfo) ? $yejiInfo : []);
                    $note = '口径与「个人业绩」页一致（销售/劳动/客数/指定客/提成/项目数）';
                } catch (\Throwable $e) {
                    $primaryDeveloping = true;
                    $note = '个人业绩加载失败';
                    $primary = [];
                }
            }
        } elseif (!$canStoreMetrics) {
            $primaryDeveloping = true;
            $note = '当前身份无有效门店经营范围或无整店数据权限，不返回聚合数据';
            $primary = [
                ['metric_code' => 'cash_performance', 'title' => '现金业绩', 'number' => null, 'developing' => true, 'detail_api' => null, 'detail_developing' => true],
                ['metric_code' => 'actual_performance', 'title' => '实收业绩', 'number' => null, 'developing' => true, 'detail_api' => null, 'detail_developing' => true],
                ['metric_code' => 'consume_amount', 'title' => '消耗金额', 'number' => null, 'developing' => true, 'detail_api' => null, 'detail_developing' => true],
            ];
        } else {
            try {
                /** @var AgentOrderServices $agentOrder */
                $agentOrder = app()->make(AgentOrderServices::class);
                $where = [
                    'time' => $time,
                    'store_id' => count($scopeStoreIds) === 1 ? $scopeStoreIds[0] : $scopeStoreIds,
                ];
                $raw = $agentOrder->homeStatics($where);
                $primary = $presenter->presentHomeStatics(is_array($raw) ? $raw : []);
            } catch (\Throwable $e) {
                $primaryDeveloping = true;
                $note = '经营指标加载失败';
                $primary = [];
            }
        }

        $secondary = $metricsMode === 'staff_self' ? [] : [
            ['title' => '开卡充值金额', 'number' => null, 'developing' => true, 'note' => '口径待确认'],
            ['title' => '服务/产品收入', 'number' => null, 'developing' => true, 'note' => '口径待确认'],
            ['title' => '退款金额', 'number' => null, 'developing' => true, 'note' => '口径待确认'],
        ];

        return [
            'primary' => $primary,
            'primary_developing' => $primaryDeveloping,
            'secondary' => $secondary,
            'metrics_mode' => $metricsMode,
            'filter' => [
                'date_type' => $filter['date_type'] ?? 'custom',
                'start_date' => $start,
                'end_date' => $end,
                'time' => $time,
            ],
            'scope' => [
                'role' => $role,
                'store_id' => $storeId,
                'scope_store_ids' => $scopeStoreIds,
                'resolved_store_ids' => $access['resolved_store_ids'] ?? [],
                'scope_mode' => $role === 'region_agent' ? 'resolved_all' : 'active_store',
            ],
            'note' => $note,
            'updated_at' => date('Y-m-d H:i:s'),
            'metric_version' => $metricsMode === 'staff_self'
                ? 'SatffYejiServices::staffInfo'
                : 'MetricDictionaryServices@1.0.0 + AgentOrderServices::homeStatics',
        ];
    }

    public function customerOverview(array $access, array $filter): array
    {
        /** @var MerchantAccessServices $accessServices */
        $accessServices = app()->make(MerchantAccessServices::class);
        $scopeStoreIds = $accessServices->requireScopeStoreIds($access);
        $start = strtotime(($filter['start_date'] ?? date('Y-m-d')) . ' 00:00:00');
        $end = strtotime(($filter['end_date'] ?? date('Y-m-d')) . ' 23:59:59');

        /** @var MerchantCustomerMetricServices $customerMetrics */
        $customerMetrics = app()->make(MerchantCustomerMetricServices::class);
        $newCustomer = $customerMetrics->newCustomerMetric($scopeStoreIds, $start, $end);

        $metrics = [
            [
                'title' => $newCustomer['title'],
                'number' => $newCustomer['number'],
                'code' => $newCustomer['metric_code'],
                'metric_code' => $newCustomer['metric_code'],
                'developing' => $newCustomer['developing'],
                'note' => $newCustomer['note'],
                'source' => $newCustomer['source'],
                'formula' => $newCustomer['formula'],
                'time_field' => $newCustomer['time_field'],
                'detail_api' => $newCustomer['detail_api'],
                'detail_developing' => $newCustomer['detail_developing'],
                'tooltip_api' => $newCustomer['tooltip_api'],
            ],
            ['title' => '成交客户数', 'number' => null, 'developing' => true],
            ['title' => '开卡充值客户数', 'number' => null, 'developing' => true],
            ['title' => '预约客户数', 'number' => null, 'code' => 'reservation_customer', 'developing' => true],
            ['title' => '到店客户数', 'number' => null, 'developing' => true],
            ['title' => '服务客次', 'number' => null, 'developing' => true],
            ['title' => '复购客户数', 'number' => null, 'developing' => true],
            ['title' => '沉睡/召回客户数', 'number' => null, 'developing' => true],
        ];

        return [
            'metrics' => $metrics,
            'filter' => [
                'date_type' => $filter['date_type'] ?? 'custom',
                'start_date' => $filter['start_date'] ?? date('Y-m-d'),
                'end_date' => $filter['end_date'] ?? date('Y-m-d'),
            ],
            'scope_store_ids' => $scopeStoreIds,
            'note' => '新增客户统一出口 MerchantCustomerMetricServices + 字典 new_customer（口径 A）；其余未核实指标 developing',
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function staffStatistics(array $access, array $filter): array
    {
        return [
            'list' => [
                ['title' => '成交客户数', 'developing' => true],
                ['title' => '开卡充值客户数', 'developing' => true],
                ['title' => '新增客户数', 'developing' => true],
                ['title' => '跟进客户数', 'developing' => true],
                ['title' => '成功邀约客户数', 'developing' => true],
                ['title' => '服务次数', 'developing' => true],
                ['title' => '预约单数', 'developing' => true],
            ],
            'note' => '员工统计指标待与报表口径核对后接入',
            'filter' => $filter,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }
}
