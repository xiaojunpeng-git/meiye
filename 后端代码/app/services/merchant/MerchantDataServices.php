<?php
namespace app\services\merchant;

use app\services\BaseServices;
use app\services\order\agent\AgentOrderServices;

/**
 * 商家数仓概览（统一 scope_store_ids；普通员工禁止整店经营聚合）
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
        $start = (string)($filter['start_date'] ?? date('Y-m-d'));
        $end = (string)($filter['end_date'] ?? date('Y-m-d'));
        $time = str_replace('-', '/', $start) . '-' . str_replace('-', '/', $end);

        $primary = [];
        $primaryDeveloping = false;
        $note = '';

        $canStoreMetrics = $accessServices->canAggregateStoreMetrics($access) && !empty($scopeStoreIds);
        $isStaffSelfOnly = $role === 'store_staff'
            || (!$canStoreMetrics && in_array('merchant.data.self', $access['permissions'] ?? [], true));

        if (!$canStoreMetrics) {
            $primaryDeveloping = true;
            $note = $isStaffSelfOnly
                ? '普通员工仅可查看本人数据；个人经营口径待确认前不返回整店聚合'
                : '当前身份无有效门店经营范围或无整店数据权限，不返回聚合数据';
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
                /** @var MerchantMetricPresenter $presenter */
                $presenter = app()->make(MerchantMetricPresenter::class);
                $primary = $presenter->presentHomeStatics(is_array($raw) ? $raw : []);
            } catch (\Throwable $e) {
                $primaryDeveloping = true;
                $note = '经营指标加载失败';
                $primary = [];
            }
        }

        $secondary = [
            ['title' => '开卡充值金额', 'number' => null, 'developing' => true, 'note' => '口径待确认'],
            ['title' => '服务/产品收入', 'number' => null, 'developing' => true, 'note' => '口径待确认'],
            ['title' => '退款金额', 'number' => null, 'developing' => true, 'note' => '口径待确认'],
        ];

        return [
            'primary' => $primary,
            'primary_developing' => $primaryDeveloping,
            'secondary' => $secondary,
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
            'metric_version' => 'MetricDictionaryServices@1.0.0 + AgentOrderServices::homeStatics',
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
            'scope_store_ids' => $scopeStoreIds,
            'resolved_store_ids' => $access['resolved_store_ids'] ?? [],
            'updated_at' => date('Y-m-d H:i:s'),
            'note' => '新增客户统一出口 MerchantCustomerMetricServices + 字典 new_customer（口径 A）；其余未核实指标 developing',
        ];
    }

    public function staffStatistics(array $access, array $filter): array
    {
        return [
            'metrics' => [
                ['title' => '成交客户数', 'developing' => true],
                ['title' => '开卡充值客户数', 'developing' => true],
                ['title' => '新增客户数', 'developing' => true],
                ['title' => '跟进客户数', 'developing' => true],
                ['title' => '成功邀约客户数', 'developing' => true],
                ['title' => '服务次数', 'developing' => true],
                ['title' => '预约单数', 'developing' => true],
            ],
            'scope_store_ids' => $access['scope_store_ids'] ?? [],
            'note' => '员工统计聚合层尚未统一，首期占位',
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }
}
